<?php
/** Queue-only import helpers. No session, database bootstrap or endpoint side effects. */

function ai_import_clean_text(string $text, int $limit = 0): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
    if ($limit > 0 && mb_strlen($text, 'UTF-8') > $limit) {
        $text = rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…';
    }
    return $text;
}

function ai_import_meta_content(string $html, string $attr, string $name): string
{
    if (!in_array($attr, ['name', 'property'], true)) {
        return '';
    }
    $name = preg_quote($name, '~');
    if (preg_match('~<meta\b(?=[^>]*\b' . $attr . '=["\']' . $name . '["\'])(?=[^>]*\bcontent=["\']([^"\']*)["\'])[^>]*>~i', $html, $match)) {
        return ai_import_clean_text($match[1], 12000);
    }
    return '';
}

/** Exactly one bounded HTTPS request, with no redirects, streams or arbitrary URL input. */
function ai_import_youtube_fetch(string $videoId, string $type, float $deadline): ?string
{
    if (!preg_match('/\A[A-Za-z0-9_-]{11}\z/', $videoId)
        || !in_array($type, ['oembed', 'watch'], true) || !function_exists('curl_init')) {
        return null;
    }
    $remaining = (int) floor(($deadline - microtime(true) - 0.5) * 1000);
    if ($remaining < 250) {
        throw new AiJobException('timeout', 'The worker time budget is exhausted.', true);
    }
    $canonical = 'https://www.youtube.com/watch?v=' . $videoId;
    $url = $type === 'oembed'
        ? 'https://www.youtube.com/oembed?url=' . rawurlencode($canonical) . '&format=json'
        : $canonical;
    $body = '';
    $limit = $type === 'oembed' ? 65536 : 2097152;
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    try {
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining),
            CURLOPT_TIMEOUT_MS => min(8000, $remaining),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'BlogImport/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return $ok !== false && $status >= 200 && $status < 300 && $body !== '' ? $body : null;
    } finally {
        curl_close($ch);
    }
}

function ai_import_parse_metadata(string $html, array $metadata): array
{
    $metadata['description'] = ai_import_meta_content($html, 'name', 'description')
        ?: ai_import_meta_content($html, 'property', 'og:description');
    $metadata['keywords'] = ai_import_meta_content($html, 'name', 'keywords');
    if (preg_match('~ytInitialPlayerResponse\s*=\s*({.+?});~s', $html, $match)) {
        $player = json_decode($match[1], true);
        $details = is_array($player) && is_array($player['videoDetails'] ?? null) ? $player['videoDetails'] : [];
        if ($metadata['description'] === '' && is_string($details['shortDescription'] ?? null)) {
            $metadata['description'] = ai_import_clean_text($details['shortDescription'], 12000);
        }
        if ($metadata['keywords'] === '' && is_array($details['keywords'] ?? null)) {
            $words = array_filter($details['keywords'], 'is_string');
            $metadata['keywords'] = ai_import_clean_text(implode(', ', $words), 500);
        }
        if (empty($metadata['title']) && is_string($details['title'] ?? null)) {
            $metadata['title'] = ai_import_clean_text($details['title'], 255);
        }
    }
    if (empty($metadata['title'])) {
        $metadata['title'] = ai_import_clean_text(ai_import_meta_content($html, 'property', 'og:title')
            ?: ai_import_meta_content($html, 'name', 'title'), 255);
    }
    return $metadata;
}

/** Called by the runner for one import stage; draft creation and its job link commit together. */
function ai_run_import_stage(array &$job, float $deadline): void
{
    $cp = $job['checkpoint'];
    $payload = $job['payload'];
    switch ($job['stage']) {
        case 'prepare':
            $line = trim((string) ($payload['line'] ?? ''));
            if ($line === '') {
                throw new AiJobException('invalid_input', 'An import idea is required.');
            }
            $videoId = blog_youtube_id($line) ?? '';
            $cp['import'] = [
                'line' => $line,
                'video_id' => $videoId,
                'url' => $videoId !== '' ? 'https://www.youtube.com/watch?v=' . $videoId : '',
                'metadata' => ['title' => '', 'description' => '', 'keywords' => ''],
            ];
            $cp['save'] = ai_runner_should_save($payload);
            $cp['persisted'] = false;
            $cp['warnings'] = [];
            ai_job_checkpoint($job, $cp, $videoId !== '' ? 'import_oembed' : 'import_insert');
            return;

        case 'import_oembed':
            $body = ai_import_youtube_fetch($cp['import']['video_id'], 'oembed', $deadline);
            $data = $body !== null ? json_decode($body, true) : null;
            if (is_array($data) && is_string($data['title'] ?? null)) {
                $cp['import']['metadata']['title'] = ai_import_clean_text($data['title'], 255);
            }
            ai_job_checkpoint($job, $cp, 'import_metadata');
            return;

        case 'import_metadata':
            $body = ai_import_youtube_fetch($cp['import']['video_id'], 'watch', $deadline);
            if ($body !== null) {
                $cp['import']['metadata'] = ai_import_parse_metadata($body, $cp['import']['metadata']);
            } else {
                $cp['warnings'][] = ['code' => 'metadata_unavailable', 'message' => 'Video details were unavailable; the original idea is retained.'];
            }
            ai_job_checkpoint($job, $cp, 'import_insert');
            return;

        case 'import_insert':
            $source = $cp['import'];
            $meta = $source['metadata'];
            $videoId = $source['video_id'];
            $title = $videoId !== '' ? ($meta['title'] ?: 'Video YouTube ' . $videoId) : $source['line'];
            $title = ai_import_clean_text($title, 255);
            if ($title === '') {
                throw new AiJobException('invalid_input', 'The import title is empty.');
            }
            $keywords = ai_import_clean_text($meta['keywords'], 500);
            $firstKeyword = array_values(array_filter(array_map('trim', explode(',', $keywords))));
            $row = [
                'title' => $title,
                'summary' => ai_import_clean_text($meta['description'], 600),
                'content' => $videoId !== '' ? blog_youtube_iframe($videoId) : '',
                'thumbnail' => '',
                'meta_title' => '',
                'meta_description' => ai_import_clean_text($meta['description'], 160),
                'meta_keywords' => $keywords,
                'focus_keyword' => $videoId !== '' ? ai_import_clean_text($firstKeyword[0] ?? $title, 120) : '',
                'slug' => '',
            ];
            $cp['entity_kind'] = 'post';
            $cp['row'] = $row;
            $cp['title'] = $title;
            $cp['description'] = $row['summary'];
            $cp['content'] = $row['content'];
            $cp['youtube_id'] = $videoId;
            // Preserve the submitted idea AND full bounded metadata, not just the shortened summary.
            $cp['seed'] = trim($source['line'] . "\n\n" . $meta['description']
                . ($keywords !== '' ? "\n\nTu khoa tham khao: " . $keywords : ''));
            $cp['do_seo'] = $videoId !== '';
            $cp['snapshot'] = ai_entity_snapshot($row, 'post');
            $cp['import_token'] = hash('sha256', 'ai-import-job:' . (string) $job['id']);
            $next = $videoId !== '' ? ($cp['save'] ? 'thumbnail' : 'article') : 'cache';
            $result = ['success' => true, 'id' => 0, 'title' => $title, 'saved' => false,
                'created' => 0, 'content' => $row['content'], 'description' => $row['summary']];
            if (!$cp['save']) {
                ai_job_checkpoint($job, $cp, $next, $result);
                return;
            }
            ai_job_transaction($job, static function (PDO $pdo) use (&$job, $cp, $row, $payload, $next, $result): void {
                if (!empty($job['entity_id'])) {
                    throw new AiJobException('conflict', 'The import is already linked to a draft.');
                }
                $marker = 'ai-import:' . $cp['import_token'];
                $existing = $pdo->prepare('SELECT id FROM posts WHERE ai_import_token=? LIMIT 1');
                $existing->execute([$cp['import_token']]);
                $existingId = (int) $existing->fetchColumn();
                if ($existingId > 0) {
                    $stored = $pdo->prepare('SELECT * FROM posts WHERE id=? LIMIT 1');
                    $stored->execute([$existingId]);
                    $storedRow = $stored->fetch(PDO::FETCH_ASSOC);
                    if ($storedRow) {
                        $job['entity_id'] = $existingId;
                        $cp['row'] = $storedRow;
                        $cp['snapshot'] = ai_entity_snapshot($storedRow, 'post');
                        $cp['tags_snapshot'] = ai_runner_post_tags_snapshot($pdo, $existingId);
                        $cp['dirty'] = true;
                        $cp['cache_invalidated'] = false;
                        $cp['persisted'] = true;
                        $job['checkpoint'] = $cp;
                        $job['stage'] = $next;
                        $result['id'] = $existingId;
                        $result['saved'] = true;
                        $result['created'] = 1;
                        $result['edit_url'] = 'edit.php?id=' . $existingId;
                        $job['result'] = $result;
                        return;
                    }
                }
                $slugBase = create_slug($row['title']);
                $slugBase = $slugBase !== '' ? mb_substr($slugBase, 0, 200, 'UTF-8') : 'bai-viet';
                $slug = $slugBase;
                $lookup = $pdo->prepare('SELECT id FROM posts WHERE slug = ? LIMIT 1');
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $lookup->execute([$slug]);
                    if (!$lookup->fetchColumn()) {
                        break;
                    }
                    $slug = $slugBase . '-' . bin2hex(random_bytes(6));
                }
                $pdo->prepare("INSERT INTO posts (title, slug, summary, content, status, schema_type, thumbnail, author_name, meta_description, meta_keywords, focus_keyword, ai_import_token, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 0, 'BlogPosting', '', ?, ?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$row['title'], $slug, $row['summary'], $row['content'],
                        mb_substr(trim((string) ($payload['author_name'] ?? 'Admin')), 0, 255, 'UTF-8'),
                        $row['meta_description'], $row['meta_keywords'], $row['focus_keyword'], $cp['import_token']]);
                $id = (int) $pdo->lastInsertId();
                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id,username,action,resource_type,resource_id,details,ip_address,user_agent) VALUES (?, 'AI Worker', 'ai_import_created', 'post', ?, ?, '', '')");
                $audit->execute([(int) $job['owner_id'], $id, $marker]);
                if ($row['meta_keywords'] !== '') {
                    blog_sync_post_tags($pdo, $id, $row['meta_keywords']);
                }
                $job['entity_id'] = $id;
                $stored = $pdo->prepare('SELECT * FROM posts WHERE id=? LIMIT 1');
                $stored->execute([$id]);
                $row = $stored->fetch(PDO::FETCH_ASSOC) ?: array_merge($row, ['id' => $id, 'slug' => $slug]);
                $cp['row'] = $row;
                $cp['snapshot'] = ai_entity_snapshot($row, 'post');
                $cp['tags_snapshot'] = ai_runner_post_tags_snapshot($pdo, $id);
                $cp['dirty'] = true;
                $cp['cache_invalidated'] = false;
                $cp['persisted'] = true;
                $job['checkpoint'] = $cp;
                $job['stage'] = $next;
                $result['id'] = $id;
                $result['saved'] = true;
                $result['created'] = 1;
                $result['edit_url'] = 'edit.php?id=' . $id;
                $job['result'] = $result;
            });
            return;
    }
    throw new AiJobException('invalid_stage', 'The import stage is not supported.');
}
