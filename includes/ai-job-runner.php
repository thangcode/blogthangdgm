<?php
/** Resumable AI job stages. This file intentionally requires no dependencies itself. */

if (!class_exists('AiJobException', false)) {
    class AiJobException extends RuntimeException
    {
        public string $errorCode;
        public bool $retryable;

        public function __construct(string $errorCode, string $message = '', bool $retryable = false, ?Throwable $previous = null)
        {
            $this->errorCode = $errorCode;
            $this->retryable = $retryable;
            parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
        }
    }
}

function ai_runner_should_save(array $payload): bool
{
    return !array_key_exists('save', $payload) || !in_array($payload['save'], [false, 0, '0', 'false'], true);
}

function ai_runner_post_tags_snapshot(PDO $pdo, int $postId, bool $forUpdate = false): string
{
    if ($postId <= 0) return '';
    if ($forUpdate) {
        $lock = $pdo->prepare('SELECT id FROM posts WHERE id=? LIMIT 1 FOR UPDATE');
        $lock->execute([$postId]);
        if (!$lock->fetchColumn()) throw new AiJobException('not_found', 'The entity no longer exists.');
        $pdo->prepare('SELECT tag_id FROM post_tags WHERE post_id=? FOR UPDATE')->execute([$postId]);
    }
    $stmt = $pdo->prepare("SELECT t.id, t.name, t.slug FROM post_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.post_id=? ORDER BY t.id");
    $stmt->execute([$postId]);
    $json = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new AiJobException('invalid_input', 'The post tags snapshot could not be encoded.');
    return hash('sha256', $json);
}

function ai_entity_snapshot(array $row, string $kind): string
{
    $fields = [
        'post' => ['id', 'title', 'slug', 'summary', 'content', 'thumbnail', 'meta_title', 'meta_description', 'meta_keywords', 'focus_keyword', 'updated_at'],
        'page' => ['id', 'title', 'slug', 'summary', 'content', 'meta_title', 'meta_description', 'meta_keywords', 'focus_keyword', 'updated_at'],
        'category' => ['id', 'name', 'slug', 'description', 'content', 'meta_title', 'meta_description', 'meta_keywords', 'focus_keyword'],
        'product' => ['id', 'name', 'slug', 'description', 'content', 'image', 'gallery', 'meta_title', 'meta_description', 'meta_keywords', 'focus_keyword', 'updated_at'],
    ];
    $snapshot = [];
    foreach ($fields[$kind] ?? array_keys($row) as $field) {
        if (array_key_exists($field, $row)) {
            $snapshot[$field] = $row[$field];
        }
    }
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new AiJobException('invalid_input', 'The entity snapshot could not be encoded.');
    }
    return hash('sha256', $kind . "\n" . $json);
}

function ai_runner_stage_min_seconds(string $stage): float
{
    if (in_array($stage, ['article', 'product_generate'], true)) return 20.0;
    if ($stage === 'seo') return 15.0;
    if (in_array($stage, ['thumbnail', 'import_oembed', 'import_metadata'], true)) return 8.0;
    return 2.0;
}

function ai_runner_llm_options(float $deadline, bool $singleAttempt = false): array
{
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0.1) {
        throw new AiJobException('timeout', 'The worker time budget is exhausted.', true);
    }
    return [
        'deadline' => $deadline,
        'connect_timeout' => 10,
        'single_attempt' => $singleAttempt,
    ];
}

function ai_runner_error(array $response, string $fallback = 'provider_unavailable'): AiJobException
{
    $code = trim((string) ($response['error_code'] ?? $response['code'] ?? ''));
    if ($code === '') {
        $error = strtolower((string) ($response['error'] ?? ''));
        if (strpos($error, '429') !== false || strpos($error, 'rate') !== false) $code = 'rate_limited';
        elseif (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) $code = 'timeout';
        elseif (strpos($error, '401') !== false || strpos($error, '403') !== false || strpos($error, 'auth') !== false) $code = 'authentication_failed';
        elseif (strpos($error, 'format') !== false || strpos($error, 'json') !== false) $code = 'invalid_output';
        elseif (strpos($error, 'curl') !== false || strpos($error, 'connect') !== false || strpos($error, 'transport') !== false) $code = 'transport_error';
        else $code = $fallback;
    }
    $aliases = ['invalid_request' => 'invalid_input', 'request_rejected' => 'invalid_input',
        'transport_unavailable' => 'transport_error', 'content_filtered' => 'invalid_output'];
    $code = $aliases[$code] ?? $code;
    $retryable = array_key_exists('retryable', $response)
        ? (bool) $response['retryable']
        : in_array($code, ['timeout', 'rate_limited', 'provider_unavailable', 'transport_error', 'db_unavailable'], true);
    return new AiJobException($code, (string) ($response['error'] ?? $response['message'] ?? $code), $retryable);
}

function ai_runner_entity_definition(string $kind): array
{
    $definitions = [
        'post' => ['table' => 'posts', 'title' => 'title', 'summary' => 'summary'],
        'page' => ['table' => 'pages', 'title' => 'title', 'summary' => 'summary'],
        'category' => ['table' => 'categories', 'title' => 'name', 'summary' => 'description'],
        'product' => ['table' => 'products', 'title' => 'name', 'summary' => 'description'],
    ];
    if (!isset($definitions[$kind])) {
        throw new AiJobException('invalid_input', 'The entity kind is not supported.');
    }
    return $definitions[$kind];
}

function ai_runner_load_entity(PDO $pdo, string $kind, int $id, bool $forUpdate = false): array
{
    $definition = ai_runner_entity_definition($kind);
    $stmt = $pdo->prepare('SELECT * FROM `' . $definition['table'] . '` WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new AiJobException('not_found', 'The entity no longer exists.');
    }
    return $row;
}

function ai_runner_assert_snapshot(array $job, array $row, string $kind, ?PDO $pdo = null): void
{
    $expected = (string) ($job['checkpoint']['snapshot'] ?? $job['payload']['snapshot'] ?? '');
    if ($expected !== '' && !hash_equals($expected, ai_entity_snapshot($row, $kind))) {
        throw new AiJobException('conflict', 'The entity changed while the AI job was waiting.');
    }
    if ($kind === 'post' && $pdo && !empty($job['checkpoint']['tags_snapshot'] ?? $job['payload']['tags_snapshot'] ?? '')) {
        $expectedTags = (string) ($job['checkpoint']['tags_snapshot'] ?? $job['payload']['tags_snapshot']);
        $lockTags = $pdo->inTransaction();
        if (!hash_equals($expectedTags, ai_runner_post_tags_snapshot($pdo, (int) ($row['id'] ?? 0), $lockTags))) {
            throw new AiJobException('conflict', 'The post tags changed while the AI job was waiting.');
        }
    }
}

function ai_runner_keywords($keywords): string
{
    if (is_array($keywords)) {
        return implode(', ', array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $keywords), 'strlen')));
    }
    return trim((string) $keywords);
}

function ai_runner_result(array $job, array $cp): array
{
    $kind = (string) ($cp['entity_kind'] ?? $job['kind'] ?? '');
    $id = (int) ($job['entity_id'] ?? 0);
    $row = is_array($cp['row'] ?? null) ? $cp['row'] : [];
    $result = is_array($job['result'] ?? null) ? $job['result'] : [];
    $title = (string) ($cp['title'] ?? $row[$kind === 'category' ? 'name' : ($kind === 'product' ? 'name' : 'title')] ?? '');
    $result = array_merge($result, [
        'success' => true,
        'message' => (string) ($result['message'] ?? 'Hoàn tất.'),
        'id' => $id,
        'title' => $title,
        'saved' => !empty($cp['persisted']),
    ]);
    foreach (['content', 'description', 'meta_title', 'meta_description', 'meta_keywords', 'focus_keyword', 'thumbnail', 'model'] as $field) {
        if (array_key_exists($field, $cp)) $result[$field] = $cp[$field];
        elseif (array_key_exists($field, $row)) $result[$field] = $row[$field];
    }
    if ($kind === 'post' && isset($result['meta_keywords'])) $result['tags'] = $result['meta_keywords'];
    if ($kind === 'seo') {
        $result['meta_keywords'] = $cp['meta_keywords_list'] ?? array_values(array_filter(array_map('trim', explode(',', (string) ($result['meta_keywords'] ?? ''))), 'strlen'));
    }
    if ($kind === 'product' && !empty($cp['product_mode'])) {
        $result['text'] = (string) ($cp['product_text'] ?? '');
        if ($cp['product_mode'] === 'all') {
            foreach (['title', 'description', 'content', 'model'] as $field) {
                if (isset($cp[$field])) $result[$field] = $cp[$field];
            }
        }
    }
    if (($job['kind'] ?? '') === 'import') {
        $result['message'] = empty($cp['youtube_id']) ? 'Đã tạo bài nháp.' : 'Đã tạo bài nháp từ YouTube.';
        $result['created'] = $id > 0 && !empty($cp['save']) ? 1 : 0;
        $result['skipped'] = 0;
        if ($id > 0) $result['edit_url'] = 'edit.php?id=' . $id;
        $result['ai'] = empty($cp['youtube_id']) ? '' : (!empty($cp['meta_title']) ? 'content_seo' : 'content_only');
        $result['items'] = [[
            'id' => $id, 'title' => $title, 'edit_url' => $id > 0 ? 'edit.php?id=' . $id : '', 'ai' => $result['ai'],
        ]];
    }
    if (!empty($cp['warnings'])) $result['warnings'] = $cp['warnings'];
    return $result;
}

function ai_runner_product_columns(PDO $pdo): array
{
    $wanted = ['id', 'name', 'slug', 'description', 'content', 'image', 'gallery', 'meta_title', 'meta_description', 'meta_keywords', 'focus_keyword', 'updated_at'];
    $placeholders = implode(',', array_fill(0, count($wanted), '?'));
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME IN ($placeholders)");
    $stmt->execute($wanted);
    return array_values(array_intersect($wanted, $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

function ai_runner_prepare_entity(array &$job): void
{
    $kind = (string) $job['kind'];
    $action = (string) $job['action'];
    if (!in_array($kind, ['post', 'page', 'category', 'product'], true)) {
        throw new AiJobException('invalid_input', 'The requested job kind is invalid.');
    }
    $allowed = $kind === 'product' ? ['title', 'description', 'content', 'all', 'rewrite', 'seo'] : ['rewrite', 'seo', 'all'];
    if (!in_array($action, $allowed, true)) {
        throw new AiJobException('invalid_input', 'The requested AI action is invalid.');
    }
    $id = (int) ($job['entity_id'] ?? 0);
    $payload = $job['payload'];
    $cp = $job['checkpoint'];
    $pdo = ai_jobs_pdo();
    if ($kind === 'product' && $id <= 0) {
        $row = [
            'id' => 0, 'name' => trim((string) ($payload['name'] ?? '')), 'description' => (string) ($payload['description'] ?? $payload['text'] ?? ''),
            'content' => (string) ($payload['content'] ?? $payload['text'] ?? ''), 'image' => (string) ($payload['image'] ?? ''),
            'gallery' => $payload['gallery'] ?? [], 'slug' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '', 'focus_keyword' => '',
        ];
    } else {
        if ($id <= 0) throw new AiJobException('invalid_input', 'An entity id is required.');
        $row = ai_runner_load_entity($pdo, $kind, $id);
        ai_runner_assert_snapshot($job, $row, $kind, $pdo);
    }
    $definition = ai_runner_entity_definition($kind);
    $title = trim((string) ($row[$definition['title']] ?? ''));
    if ($title === '') throw new AiJobException('invalid_input', 'The entity title is empty.');
    $summary = trim((string) ($row[$definition['summary']] ?? ''));
    $content = (string) ($row['content'] ?? '');
    $cp = array_merge($cp, [
        'entity_kind' => $kind, 'row' => $row, 'title' => $title, 'description' => $summary, 'content' => $content,
        'save' => $kind === 'product' ? false : ai_runner_should_save($payload), 'snapshot' => ai_entity_snapshot($row, $kind), 'dirty' => false, 'persisted' => false,
    ]);
    if ($kind === 'post' && $id > 0) {
        $cp['tags_snapshot'] = (string) ($payload['tags_snapshot'] ?? ai_runner_post_tags_snapshot($pdo, $id));
    }
    if ($kind === 'product') {
        $cp['product_columns'] = ai_runner_product_columns($pdo);
        $cp['product_mode'] = $action === 'rewrite' ? 'all' : $action;
        $cp['images'] = is_array($payload['images'] ?? null) ? $payload['images'] : [];
        if (!$cp['images']) {
            if (!empty($row['image'])) $cp['images'][] = $row['image'];
            $gallery = is_array($row['gallery'] ?? null) ? $row['gallery'] : json_decode((string) ($row['gallery'] ?? ''), true);
            if (is_array($gallery)) $cp['images'] = array_merge($cp['images'], $gallery);
        }
        ai_job_checkpoint($job, $cp, $cp['product_mode'] === 'seo' ? 'seo' : 'product_generate');
        return;
    }
    $cp['youtube_id'] = function_exists('blog_extract_youtube_id') ? (blog_extract_youtube_id($content) ?? '') : '';
    $cp['seed'] = trim(strip_tags($content));
    if ($cp['seed'] === '') $cp['seed'] = $summary;
    $cp['do_seo'] = in_array($action, ['seo', 'all'], true);
    $next = $action === 'seo' ? 'seo' : ($kind === 'post' && $cp['youtube_id'] !== '' && trim((string) ($row['thumbnail'] ?? '')) === '' ? 'thumbnail' : 'article');
    ai_job_checkpoint($job, $cp, $next);
}

function ai_runner_run_article(array &$job, float $deadline): void
{
    $cp = $job['checkpoint'];
    $title = (string) $cp['title'];
    if (($cp['entity_kind'] ?? '') === 'category') $title = 'Chuyên mục: ' . $title;
    $response = ai_rewrite_blog_post($title, (string) ($cp['seed'] ?? ''), (string) ($cp['youtube_id'] ?? ''), ai_runner_llm_options($deadline));
    if (empty($response['ok'])) throw ai_runner_error($response);
    $cp['content'] = (string) ($response['content'] ?? '');
    if (trim($cp['content']) === '') throw new AiJobException('invalid_output', 'The generated article is empty.');
    if (trim((string) ($cp['description'] ?? '')) === '') {
        $cp['description'] = trim((string) ($response['description'] ?? ''));
    }
    $cp['model'] = (string) ($response['model'] ?? '');
    $next = !empty($cp['do_seo'])
        ? (!empty($cp['save']) ? 'save_content' : 'seo')
        : 'save';
    ai_job_checkpoint($job, $cp, $next, ai_runner_result($job, $cp));
}

function ai_runner_run_seo(array &$job, float $deadline): void
{
    $cp = $job['checkpoint'];
    $response = ai_generate_seo((string) $cp['title'], (string) ($cp['description'] ?? ''), strip_tags((string) ($cp['content'] ?? '')), ai_runner_llm_options($deadline));
    if (empty($response['ok'])) throw ai_runner_error($response, 'invalid_output');
    $cp['meta_title'] = (string) ($response['meta_title'] ?? '');
    $cp['meta_description'] = (string) ($response['meta_description'] ?? '');
    $cp['meta_keywords_list'] = is_array($response['meta_keywords'] ?? null)
        ? array_values($response['meta_keywords'])
        : array_values(array_filter(array_map('trim', explode(',', (string) ($response['meta_keywords'] ?? ''))), 'strlen'));
    $cp['meta_keywords'] = ai_runner_keywords($response['meta_keywords'] ?? '');
    $cp['focus_keyword'] = (string) ($response['focus_keyword'] ?? '');
    $cp['model'] = (string) ($response['model'] ?? $cp['model'] ?? '');
    if ($cp['meta_title'] === '' && $cp['meta_description'] === '') throw new AiJobException('invalid_output', 'The generated SEO data is empty.');
    ai_job_checkpoint($job, $cp, (($cp['entity_kind'] ?? '') === 'product' || ($job['kind'] ?? '') === 'seo') ? 'cache' : 'save');
}

function ai_runner_prepare_seo(array &$job): void
{
    $payload = $job['payload'];
    $title = trim(strip_tags((string) ($payload['title'] ?? '')));
    if ($title === '') throw new AiJobException('invalid_input', 'A title is required for SEO generation.');
    $cp = array_merge($job['checkpoint'], [
        'entity_kind' => 'seo', 'title' => $title,
        'description' => trim(strip_tags((string) ($payload['description'] ?? ''))),
        'content' => (string) ($payload['content'] ?? ''), 'save' => false, 'dirty' => false, 'persisted' => false,
    ]);
    ai_job_checkpoint($job, $cp, 'seo');
}

function ai_runner_product_prompt(string $mode, string $name, string $text): array
{
    $rules = ai_content_rules();
    if ($mode === 'title') {
        return ['Bạn là biên tập viên SEO. Viết lại TÊN sản phẩm ngắn gọn, tự nhiên, dễ đọc, chuẩn SEO khoảng 50-65 ký tự. Chỉ trả về một dòng tiêu đề.\n' . $rules,
            'Tên gốc: ' . ($text !== '' ? $text : $name), ['max_tokens' => 80, 'temperature' => 0.7]];
    }
    if ($mode === 'content') {
        return ['Bạn là copywriter SEO. Viết lại NỘI DUNG bằng HTML thuần, bắt đầu bằng <h2>, không dùng <h1>. Chỉ trả về HTML.\n' . $rules,
            "Tên sản phẩm: $name\nNội dung gốc:\n$text", ['max_tokens' => 2000, 'temperature' => 0.7]];
    }
    return ['Bạn là copywriter SEO. Viết lại MÔ TẢ NGẮN sản phẩm thành 2-3 câu súc tích. Chỉ trả về đoạn văn.\n' . $rules,
        "Tên sản phẩm: $name\nMô tả gốc: $text", ['max_tokens' => 300, 'temperature' => 0.7]];
}

function ai_runner_run_product(array &$job, float $deadline): void
{
    $cp = $job['checkpoint'];
    $mode = (string) $cp['product_mode'];
    $row = $cp['row'];
    $name = (string) ($row['name'] ?? $cp['title']);
    $text = (string) ($job['payload']['text'] ?? ($mode === 'content' ? ($row['content'] ?? '') : ($row['description'] ?? '')));
    if ($mode === 'all') {
        $response = ai_generate_article($name, $text, (array) ($cp['images'] ?? []), ai_runner_llm_options($deadline));
        if (empty($response['ok'])) throw ai_runner_error($response, 'invalid_output');
        $cp['title'] = (string) ($response['title'] ?? $name);
        $cp['description'] = (string) ($response['description'] ?? '');
        $cp['content'] = (string) ($response['content'] ?? '');
        $cp['model'] = (string) ($response['model'] ?? '');
    } else {
        [$system, $user, $options] = ai_runner_product_prompt($mode, $name, $text);
        $response = llm_chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            array_merge($options, ai_runner_llm_options($deadline)));
        if (empty($response['ok'])) throw ai_runner_error($response);
        $output = trim((string) ($response['text'] ?? ''));
        $output = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $output);
        if ($mode === 'content') $output = preg_replace('#<(/?)h1(\s[^>]*)?>#i', '<$1h2$2>', $output);
        if ($output === '') throw new AiJobException('invalid_output', 'The generated product text is empty.');
        $cp['product_text'] = $output;
        $cp[$mode] = $output;
        $cp['model'] = (string) ($response['model_used'] ?? '');
    }
    ai_job_checkpoint($job, $cp, 'cache');
}

function ai_runner_save_fields(array &$job, bool $contentOnly): void
{
    $cp = $job['checkpoint'];
    if (empty($cp['save'])) {
        ai_job_checkpoint($job, $cp, $contentOnly ? 'seo' : 'cache', ai_runner_result($job, $cp));
        return;
    }
    $kind = (string) $cp['entity_kind'];
    ai_job_transaction($job, static function (PDO $pdo) use (&$job, $cp, $kind, $contentOnly): void {
        $current = ai_runner_load_entity($pdo, $kind, (int) $job['entity_id'], true);
        ai_runner_assert_snapshot($job, $current, $kind, $pdo);
        $action = (string) $job['action'];
        $sets = []; $values = [];
        if ($contentOnly || in_array($action, ['rewrite', 'all'], true)) {
            if ($kind === 'category') {
                if (function_exists('has_table_column') && has_table_column($pdo, 'categories', 'content')) {
                    $sets[] = '`content`=?'; $values[] = $cp['content'];
                }
            } else {
                $sets[] = '`content`=?'; $values[] = $cp['content'];
                $sets[] = '`summary`=?'; $values[] = $cp['description'];
            }
        }
        if (!$contentOnly && in_array($action, ['seo', 'all'], true)) {
            foreach (['meta_title', 'meta_description', 'meta_keywords', 'focus_keyword'] as $field) {
                if (array_key_exists($field, $cp)) { $sets[] = '`' . $field . '`=?'; $values[] = $cp[$field]; }
            }
        }
        if (!$sets) throw new AiJobException('invalid_input', 'There is no generated content to save.');
        if ($kind !== 'category' && array_key_exists('updated_at', $current)) $sets[] = '`updated_at`=NOW()';
        $values[] = (int) $job['entity_id'];
        $pdo->prepare('UPDATE `' . ai_runner_entity_definition($kind)['table'] . '` SET ' . implode(',', $sets) . ' WHERE id=?')->execute($values);
        if (!$contentOnly && $kind === 'post' && !empty($cp['meta_keywords']) && function_exists('blog_sync_post_tags')) {
            blog_sync_post_tags($pdo, (int) $job['entity_id'], $cp['meta_keywords']);
        }
        $stored = ai_runner_load_entity($pdo, $kind, (int) $job['entity_id']);
        $cp['row'] = $stored;
        $cp['snapshot'] = ai_entity_snapshot($stored, $kind);
        if ($kind === 'post') $cp['tags_snapshot'] = ai_runner_post_tags_snapshot($pdo, (int) $job['entity_id']);
        $cp['dirty'] = true;
        $cp['cache_invalidated'] = false;
        $cp['persisted'] = true;
        $job['checkpoint'] = $cp;
        $job['stage'] = $contentOnly ? 'invalidate_content' : 'cache';
        $job['result'] = ai_runner_result($job, $cp);
    });
}

function ai_runner_save_content(array &$job): void
{
    ai_runner_save_fields($job, true);
}

function ai_runner_save(array &$job): void
{
    ai_runner_save_fields($job, false);
}

function ai_runner_fetch_thumbnail(string $videoId, float $deadline): ?string
{
    if (!preg_match('/\A[A-Za-z0-9_-]{11}\z/', $videoId) || !function_exists('curl_init')) return null;
    $remaining = (int) floor(($deadline - microtime(true) - 0.25) * 1000);
    if ($remaining < 250) throw new AiJobException('timeout', 'The worker time budget is exhausted.', true);
    $body = '';
    $ch = curl_init('https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg');
    if ($ch === false) return null;
    try {
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining),
            CURLOPT_TIMEOUT_MS => min(8000, $remaining), CURLOPT_NOSIGNAL => true, CURLOPT_USERAGENT => 'BlogImport/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 5242880) return 0;
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return $ok !== false && $status >= 200 && $status < 300 && @getimagesizefromstring($body) !== false ? $body : null;
    } finally { curl_close($ch); }
}

function ai_runner_store_thumbnail(PDO $pdo, string $data, string $title, int $jobId = 0): ?string
{
    $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR : dirname(__DIR__) . DIRECTORY_SEPARATOR;
    $ym = date('Y/m');
    $dir = $root . 'assets/uploads/media/' . $ym . '/';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
    $base = function_exists('create_slug') ? create_slug($title) : 'thumb';
    if ($base === '') $base = 'thumb';
    $identity = $jobId > 0 ? 'ai-job-' . $jobId : 'ai-' . substr(hash('sha256', $data), 0, 16);
    $stored = $identity . '-' . substr($base, 0, 40) . '.jpg';
    $absolute = $dir . $stored;
    $temp = $absolute . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($temp, $data, LOCK_EX) === false) return null;
    if (!is_file($absolute) && !@rename($temp, $absolute)) {
        @unlink($temp);
        return null;
    }
    if (is_file($temp)) @unlink($temp);
    return 'assets/uploads/media/' . $ym . '/' . $stored;
}

function ai_runner_thumbnail(array &$job, float $deadline): void
{
    $cp = $job['checkpoint'];
    $videoId = (string) ($cp['youtube_id'] ?? '');
    if ($videoId === '' || empty($cp['save']) || empty($job['entity_id'])) {
        ai_job_checkpoint($job, $cp, 'article');
        return;
    }
    $pdo = ai_jobs_pdo();
    $current = ai_runner_load_entity($pdo, 'post', (int) $job['entity_id']);
    ai_runner_assert_snapshot($job, $current, 'post', $pdo);
    if (trim((string) ($current['thumbnail'] ?? '')) !== '') {
        ai_job_checkpoint($job, $cp, 'article');
        return;
    }
    $data = ai_runner_fetch_thumbnail($videoId, $deadline);
    $thumbnail = $data !== null ? ai_runner_store_thumbnail($pdo, $data, (string) $cp['title'], (int) $job['id']) : null;
    if ($thumbnail) {
        try {
            ai_job_transaction($job, static function (PDO $pdo) use (&$job, $cp, $thumbnail): void {
                $current = ai_runner_load_entity($pdo, 'post', (int) $job['entity_id'], true);
                ai_runner_assert_snapshot($job, $current, 'post', $pdo);
                $stmt = $pdo->prepare("UPDATE posts SET thumbnail=? WHERE id=? AND (thumbnail IS NULL OR thumbnail='')");
                $stmt->execute([$thumbnail, $job['entity_id']]);
                if ($stmt->rowCount() !== 1) throw new AiJobException('conflict', 'The post thumbnail changed while the AI job was waiting.');
                $cp['thumbnail'] = $thumbnail;
                $current = ai_runner_load_entity($pdo, 'post', (int) $job['entity_id']);
                $cp['row'] = $current;
                $cp['snapshot'] = ai_entity_snapshot($current, 'post');
                $cp['tags_snapshot'] = ai_runner_post_tags_snapshot($pdo, (int) $job['entity_id']);
                $cp['dirty'] = true;
                $cp['cache_invalidated'] = false;
                $cp['persisted'] = true;
                $job['checkpoint'] = $cp;
                $job['stage'] = 'article';
                $job['result'] = ai_runner_result($job, $cp);
            });
        } catch (Throwable $e) {
            $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR : dirname(__DIR__) . DIRECTORY_SEPARATOR;
            $absolute = $root . str_replace('/', DIRECTORY_SEPARATOR, $thumbnail);
            if (is_file($absolute)) @unlink($absolute);
            throw $e;
        }
        if (function_exists('register_media_file')) {
            $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR : dirname(__DIR__) . DIRECTORY_SEPARATOR;
            @register_media_file($pdo, $root . str_replace('/', DIRECTORY_SEPARATOR, $thumbnail), $thumbnail, (string) $cp['title']);
        }
        return;
    }
    $cp['warnings'][] = ['code' => 'thumbnail_unavailable', 'message' => 'The YouTube thumbnail could not be imported.'];
    ai_job_checkpoint($job, $cp, 'article');
}

function ai_runner_invalidate_cache(array &$job, string $nextStage): void
{
    $cp = $job['checkpoint'];
    if (!empty($cp['dirty']) && empty($cp['cache_invalidated']) && class_exists('PageCache')) {
        try {
            if (method_exists('PageCache', 'invalidateContent')) {
                PageCache::invalidateContent((string) ($cp['entity_kind'] ?? $job['kind']), (int) ($job['entity_id'] ?? 0), (string) ($cp['row']['slug'] ?? ''));
            } else {
                PageCache::flush();
            }
            $cp['cache_invalidated'] = true;
        } catch (Throwable $ignored) {
            $cp['warnings'][] = ['code' => 'cache_invalidation_failed', 'message' => 'Content was saved, but cache invalidation failed.'];
        }
    }
    if ($nextStage === 'finish') {
        ai_job_finish($job, ai_runner_result($job, $cp));
    } else {
        ai_job_checkpoint($job, $cp, $nextStage, ai_runner_result($job, $cp));
    }
}

function ai_runner_cache(array &$job): void
{
    ai_runner_invalidate_cache($job, 'finish');
}

function ai_runner_assert_owner_active(array &$job): void
{
    if (!empty($job['owner_checked'])) return;
    $pdo = ai_jobs_pdo();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='admin' LIMIT 1");
    $stmt->execute([(int) ($job['owner_id'] ?? 0)]);
    if (!$stmt->fetchColumn()) throw new AiJobException('cancelled', 'The job owner is no longer an administrator.');
    $job['owner_checked'] = true;
}

function ai_run_job(array &$job, float $deadline): void
{
    if (microtime(true) >= $deadline - 0.1) throw new AiJobException('timeout', 'The worker time budget is exhausted.', true);
    ai_runner_assert_owner_active($job);
    $kind = (string) ($job['kind'] ?? '');
    $stage = (string) ($job['stage'] ?? 'prepare');
    if ($kind === 'import' && in_array($stage, ['prepare', 'import_oembed', 'import_metadata', 'import_insert'], true)) {
        ai_run_import_stage($job, $deadline);
        return;
    }
    switch ($stage) {
        case 'prepare':
            if ($kind === 'seo') ai_runner_prepare_seo($job);
            else ai_runner_prepare_entity($job);
            return;
        case 'thumbnail': ai_runner_thumbnail($job, $deadline); return;
        case 'article': ai_runner_run_article($job, $deadline); return;
        case 'save_content': ai_runner_save_content($job); return;
        case 'invalidate_content': ai_runner_invalidate_cache($job, 'seo'); return;
        case 'seo': ai_runner_run_seo($job, $deadline); return;
        case 'product_generate': ai_runner_run_product($job, $deadline); return;
        case 'save': ai_runner_save($job); return;
        case 'cache': ai_runner_cache($job); return;
    }
    throw new AiJobException('invalid_input', 'The requested AI job or stage is not supported.');
}
