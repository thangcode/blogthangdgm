<?php
/**
 * Pipeline viết bài mới bằng AI từ tiêu đề/từ khóa (ai_jobs kind='write').
 * Chạy trong CLI worker (scripts/ai-worker.php); file này không có side-effect session/endpoint.
 *
 * Stage flow: prepare -> write_thumb -> write_img_1..N -> write_article
 *   -> write_article_b (chỉ khi bài dài >=1500 từ) -> seo -> save -> cache.
 * Mỗi stage checkpoint riêng nên worker chết giữa chừng vẫn tiếp tục đúng chỗ.
 */

if (!function_exists('ai_write_ensure_schema')) {
    /** Tạo lười bảng danh sách ý tưởng viết bài (idempotent). */
    function ai_write_ensure_schema(PDO $pdo): bool
    {
        static $done = null;
        if ($done !== null) return $done;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_write_ideas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                owner_id BIGINT UNSIGNED NOT NULL,
                idea VARCHAR(500) NOT NULL,
                brief TEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                job_id BIGINT UNSIGNED DEFAULT NULL,
                post_id BIGINT UNSIGNED DEFAULT NULL,
                message VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aiw_owner_status (owner_id, status),
                KEY idx_aiw_post (post_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // Nâng cấp bảng đã tồn tại: thêm cột brief nếu thiếu.
            $colCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $colCheck->execute(['ai_write_ideas', 'brief']);
            if (!(int) $colCheck->fetchColumn()) {
                $pdo->exec('ALTER TABLE ai_write_ideas ADD COLUMN brief TEXT DEFAULT NULL AFTER idea');
            }
            $done = true;
        } catch (Throwable $e) {
            $done = false;
        }
        return $done;
    }
}

if (!function_exists('ai_write_idea_touch')) {
    /** Cập nhật trạng thái ý tưởng; nuốt lỗi để không làm hỏng luồng viết bài. */
    function ai_write_idea_touch(PDO $pdo, int $ideaId, array $fields): void
    {
        if ($ideaId <= 0 || $fields === []) return;
        try {
            $allowed = ['status', 'job_id', 'post_id', 'message'];
            $sets = [];
            $values = [];
            foreach ($fields as $k => $v) {
                if (!in_array($k, $allowed, true)) continue;
                $sets[] = '`' . $k . '`=?';
                $values[] = $v;
            }
            if (!$sets) return;
            $sets[] = 'updated_at=NOW()';
            $values[] = $ideaId;
            $pdo->prepare('UPDATE ai_write_ideas SET ' . implode(',', $sets) . ' WHERE id=?')->execute($values);
        } catch (Throwable $e) {
            // Không bắt buộc; UI sẽ tự đồng bộ trạng thái từ ai_jobs.
        }
    }
}

if (!function_exists('ai_write_next_image_stage')) {
    /** Stage tiếp theo sau khi xong thumbnail / ảnh thứ $done. */
    function ai_write_next_image_stage(array $cp, int $done): string
    {
        $count = (int) ($cp['image_count'] ?? 0);
        return $done < $count ? 'write_img_' . ($done + 1) : 'write_article';
    }
}

if (!function_exists('ai_write_prepare_next')) {
    /** Stage đầu tiên sau prepare tuỳ cấu hình ảnh. */
    function ai_write_prepare_next(array $cp): string
    {
        if (!empty($cp['want_thumb'])) return 'write_thumb';
        return ai_write_next_image_stage($cp, 0);
    }
}

if (!function_exists('ai_write_gen_topic')) {
    /**
     * Chủ đề đưa vào prompt: tiêu đề + mô tả/yêu cầu thêm + dữ liệu tin tức
     * tham khảo (grounding). Research được ưu tiên làm cơ sở sự thật thay
     * trí nhớ model — quan trọng với chủ đề tin tức/sự kiện mới.
     */
    function ai_write_gen_topic(array $cp): string
    {
        $topic = (string) ($cp['topic'] ?? $cp['title'] ?? '');
        $brief = trim((string) ($cp['brief'] ?? ''));
        if ($brief !== '') {
            $topic .= "\n\nMÔ TẢ / YÊU CẦU THÊM CHO BÀI VIẾT: " . $brief;
        }
        $research = trim((string) ($cp['research'] ?? ''));
        if ($research !== '') {
            $topic .= "\n\nDỮ LIỆU TIN TỨC MỚI NHẤT ĐÃ KIỂM CHỨNG (ưu tiên làm cơ sở sự thật — viết bám sát các sự kiện, tên gọi, thời điểm trong đây; KHÔNG viết kiểu 'chưa xác nhận' khi nguồn đã nêu rõ):\n" . $research;
        }
        return $topic;
    }
}

if (!function_exists('ai_write_research_llm')) {
    /**
     * Research tầng 1: dùng model chat có search grounding thật qua provider.
     * Hiện hỗ trợ: gemini -> tools[google_search]; grok -> search_parameters.
     * Quét mọi model chat đang bật (không chỉ model đã gán tính năng), thử tối đa
     * 4 model — model từ chối tool thì bỏ qua, thử model kế tiếp.
     */
    function ai_write_research_llm(string $topic, float $deadline): string
    {
        try { $pdo = function_exists('ai_jobs_pdo') ? ai_jobs_pdo() : llm_pdo(); } catch (Throwable $e) { return ''; }
        if (!$pdo) return '';
        try {
            $st = $pdo->query("SELECT m.* FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id
                               WHERE m.status = 1 AND p.status = 1 AND m.kind = 'chat'
                               ORDER BY m.sort_order ASC, m.id ASC");
            $cands = $st ? $st->fetchAll() : [];
        } catch (Throwable $e) { $cands = []; }
        $tried = 0;
        foreach ($cands as $m) {
            $m['provider'] = llm_get_provider($pdo, (int) ($m['provider_id'] ?? 0));
            if (empty($m['provider']) || (string) ($m['provider']['api_type'] ?? 'openai') !== 'openai') continue;
            $name = strtolower((string) ($m['model_name'] ?? ''));
            $opts = ['deadline' => $deadline, 'max_tokens' => 800, 'temperature' => 0.2];
            if (strpos($name, 'gemini') !== false) {
                $opts['tools'] = [['google_search' => new stdClass()]];
            } elseif (strpos($name, 'grok') !== false) {
                $opts['search_parameters'] = ['mode' => 'on'];
            } else {
                continue; // model không rõ cơ chế search -> bỏ qua, tránh bịa dữ kiện
            }
            if (++$tried > 4 || microtime(true) + 2 >= $deadline) break;
            $res = llm_call_model($m, [
                ['role' => 'system', 'content' => 'Bạn là trợ lý nghiên cứu tin tức. Dùng công cụ search để lấy thông tin MỚI NHẤT, THẬT về chủ đề. Trả về tối đa 10 dòng bullet, mỗi dòng 1 dữ kiện đã xác nhận (sự kiện, ngày, tên, số liệu, nguồn). Không bình luận, không suy đoán. Nếu chủ đề hoàn toàn không có tin tức liên quan thì trả lời đúng 1 từ: NONE'],
                ['role' => 'user', 'content' => 'Chủ đề: ' . $topic],
            ], $opts);
            if (empty($res['ok'])) continue;
            $text = trim((string) ($res['text'] ?? ''));
            if ($text === '' || strtoupper($text) === 'NONE') continue;
            return mb_substr($text, 0, 3000, 'UTF-8');
        }
        return '';
    }
}

if (!function_exists('ai_write_research_rss')) {
    /**
     * Research tầng 2 (fallback): headline tin tức gần đây từ Google News RSS
     * (không cần API key/model). Trả "• Tiêu đề — Nguồn (dd/mm/yyyy)" hoặc ''.
     */
    function ai_write_research_rss(string $topic, float $deadline): string
    {
        $topic = trim($topic);
        if ($topic === '' || !function_exists('curl_init')) return '';
        $remaining = (int) floor(($deadline - microtime(true) - 0.5) * 1000);
        if ($remaining < 1500) return '';
        $ch = curl_init('https://news.google.com/rss/search?q=' . rawurlencode($topic) . '&hl=vi&gl=VN&ceid=VN:vi');
        if ($ch === false) return '';
        $body = '';
        try {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $remaining), CURLOPT_TIMEOUT_MS => min(12000, $remaining),
                CURLOPT_NOSIGNAL => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BlogAiWrite/1.0)',
            ]);
            $body = (string) curl_exec($ch);
            if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) return '';
        } catch (Throwable $e) {
            return '';
        } finally {
            curl_close($ch);
        }
        $xml = @simplexml_load_string($body);
        if (!$xml) return '';
        $lines = [];
        foreach ($xml->channel->item ?? [] as $item) {
            $title = trim(preg_replace('/\s+/u', ' ', (string) $item->title) ?? '');
            if ($title === '') continue;
            $src = trim((string) ($item->source ?? ''));
            $ts = strtotime((string) ($item->pubDate ?? ''));
            $lines[] = '• ' . $title . ($src !== '' ? ' — ' . $src : '') . ($ts ? ' (' . date('d/m/Y', $ts) . ')' : '');
            if (count($lines) >= 8) break;
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('ai_write_research')) {
    /**
     * Grounding cho bài viết: ưu tiên model search thật (google_search / live
     * search) để có dữ kiện đầy đủ; không được thì dùng Google News RSS.
     * Luôn fail-safe: lỗi mọi tầng -> '' (prompt không đổi, bài vẫn viết được).
     */
    function ai_write_research(string $topic, float $deadline): string
    {
        if (trim($topic) === '') return '';
        $llm = ai_write_research_llm($topic, $deadline);
        if ($llm !== '') return $llm;
        return ai_write_research_rss($topic, $deadline);
    }
}

if (!function_exists('ai_write_image_bytes')) {
    /**
     * Lấy bytes ảnh từ kết quả image model: data URI base64 hoặc URL HTTPS.
     * HTTPS: không redirect, giới hạn 8MB, phải là ảnh hợp lệ (chống SSRF/đầu vào rác).
     */
    function ai_write_image_bytes(string $imageUrl, float $deadline): ?string
    {
        $imageUrl = trim($imageUrl);
        if (strncasecmp($imageUrl, 'data:image/', 11) === 0) {
            $comma = strpos($imageUrl, ',');
            if ($comma === false) return null;
            $data = base64_decode(substr($imageUrl, $comma + 1), true);
            if ($data === false || strlen($data) > 8388608) return null;
            return @getimagesizefromstring($data) !== false ? $data : null;
        }
        if (stripos($imageUrl, 'https://') !== 0 || !function_exists('curl_init')) {
            return null;
        }
        $remaining = (int) floor(($deadline - microtime(true) - 0.25) * 1000);
        if ($remaining < 250) {
            throw new AiJobException('timeout', 'The worker time budget is exhausted.', true);
        }
        $body = '';
        $ch = curl_init($imageUrl);
        if ($ch === false) return null;
        try {
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT_MS => min(5000, $remaining),
                CURLOPT_TIMEOUT_MS => min(30000, $remaining), CURLOPT_NOSIGNAL => true,
                CURLOPT_USERAGENT => 'BlogAiWrite/1.0',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 8388608) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $ok !== false && $status >= 200 && $status < 300
                && @getimagesizefromstring($body) !== false ? $body : null;
        } finally {
            curl_close($ch);
        }
    }
}

if (!function_exists('ai_write_store_image')) {
    /**
     * Ghi bytes ảnh ra assets/uploads/media/Y/m/ rồi nén WebP bằng compress_to_webp()
     * (đúng pipeline nén hiện tại). Trả về path tương đối hoặc null.
     */
    function ai_write_store_image(int $jobId, int $seq, string $data, string $title): ?string
    {
        $info = @getimagesizefromstring($data);
        if (!$info) return null;
        $ext = [
            'image/jpeg' => 'jpg', 'image/png' => 'png',
            'image/gif' => 'gif', 'image/webp' => 'webp',
        ][$info['mime'] ?? ''] ?? 'png';

        $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR : dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $ym = date('Y/m');
        $dir = $root . 'assets/uploads/media/' . $ym . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;

        $base = function_exists('create_slug') ? create_slug($title) : 'ai-image';
        if ($base === '') $base = 'ai-image';
        $stored = 'aiw-' . $jobId . '-' . $seq . '-' . mb_substr($base, 0, 40, 'UTF-8');
        // Nếu bản .webp đã tồn tại (crash giữa chừng sau khi convert), tái dùng luôn.
        $webp = $dir . $stored . '.webp';
        if (is_file($webp)) {
            return 'assets/uploads/media/' . $ym . '/' . $stored . '.webp';
        }
        $absolute = $dir . $stored . '.' . $ext;
        if (!is_file($absolute)) {
            $temp = $absolute . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($temp, $data, LOCK_EX) === false) return null;
            if (!@rename($temp, $absolute)) {
                @unlink($temp);
                return null;
            }
        }
        // Nén WebP: trả về file .webp mới (xóa gốc) hoặc giữ nguyên nếu không convert được.
        $final = function_exists('compress_to_webp')
            ? compress_to_webp($absolute, 82, 1600)
            : $absolute;
        if (!is_string($final) || !is_file($final)) $final = $absolute;
        return 'assets/uploads/media/' . $ym . '/' . basename($final);
    }
}

if (!function_exists('ai_write_generate_image')) {
    /**
     * Gọi image model, lấy bytes, lưu + nén WebP. Trả path tương đối hoặc null;
     * lỗi retryable của provider thì throw để job thử lại. Caller đăng ký media
     * library sau khi checkpoint/transaction commit thành công.
     */
    function ai_write_generate_image(int $jobId, int $seq, string $prompt, string $title, float $deadline): ?string
    {
        $res = llm_call_feature('image', [['role' => 'user', 'content' => $prompt]], ai_runner_llm_options($deadline));
        if (empty($res['ok'])) {
            throw ai_runner_error($res);
        }
        $bytes = ai_write_image_bytes((string) ($res['image_url'] ?? ''), $deadline);
        if ($bytes === null) return null;
        return ai_write_store_image($jobId, $seq, $bytes, $title);
    }
}

if (!function_exists('ai_write_register_media')) {
    /** Đăng ký file đã lưu vào media library (best-effort, nuốt lỗi). */
    function ai_write_register_media(string $rel, string $title): void
    {
        if (!function_exists('register_media_file') || $rel === '') return;
        $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR : dirname(__DIR__) . DIRECTORY_SEPARATOR;
        try {
            register_media_file(ai_jobs_pdo(), $root . str_replace('/', DIRECTORY_SEPARATOR, $rel), $rel, $title);
        } catch (Throwable $e) {
            // Media library là phụ trợ; không làm hỏng job chính.
        }
    }
}

if (!function_exists('ai_write_prepare')) {
    /**
     * Stage 'prepare': tạo bài nháp (status=0) hoặc gắn lại bài đã tạo (idempotent
     * qua posts.ai_import_token theo job id — retry không tạo trùng).
     */
    function ai_write_prepare(array &$job): void
    {
        $payload = $job['payload'];
        $topic = trim(strip_tags((string) ($payload['topic'] ?? '')));
        if ($topic === '') {
            throw new AiJobException('invalid_input', 'A topic or title is required.');
        }
        $topic = mb_substr($topic, 0, 500, 'UTF-8');
        $brief = mb_substr(trim(strip_tags((string) ($payload['brief'] ?? ''))), 0, 2000, 'UTF-8');
        $targetWords = max(400, min(3000, (int) ($payload['target_words'] ?? 1200)));
        $imageCount = max(0, min(3, (int) ($payload['image_count'] ?? 0)));
        $withThumb = !empty($payload['with_thumb']);
        $statusTarget = (int) ($payload['status'] ?? 0);
        if (!in_array($statusTarget, [0, 1, 2], true)) $statusTarget = 0;
        $categoryId = max(0, (int) ($payload['category_id'] ?? 0));
        $author = mb_substr(trim((string) ($payload['author_name'] ?? 'Admin')), 0, 255, 'UTF-8');
        if ($author === '') $author = 'Admin';
        $title = mb_substr($topic, 0, 255, 'UTF-8');
        // Token theo idea_id để retry/re-enqueue cùng ý tưởng tái dùng đúng draft,
        // không chồng thêm bài nháp rỗng. Job không gắn idea vẫn theo job id.
        $ideaId = (int) ($payload['idea_id'] ?? 0);
        $token = hash('sha256', $ideaId > 0 ? 'ai-write-idea:' . $ideaId : 'ai-write-job:' . (string) $job['id']);
        $twoPass = $targetWords >= 1500;

        ai_job_transaction($job, static function (PDO $pdo) use (&$job, $payload, $topic, $brief, $ideaId, $title, $token, $targetWords, $imageCount, $withThumb, $statusTarget, $categoryId, $author, $twoPass): void {
            $row = null;
            $id = 0;
            if (!empty($job['entity_id'])) {
                $row = ai_runner_load_entity($pdo, 'post', (int) $job['entity_id'], true);
                $id = (int) $job['entity_id'];
            } else {
                $find = $pdo->prepare('SELECT * FROM posts WHERE ai_import_token=? LIMIT 1');
                $find->execute([$token]);
                $row = $find->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($row) $id = (int) $row['id'];
            }
            $created = 0;
            if (!$row) {
                $slugBase = create_slug($title);
                $slugBase = $slugBase !== '' ? mb_substr($slugBase, 0, 200, 'UTF-8') : 'bai-viet';
                $slug = $slugBase;
                $lookup = $pdo->prepare('SELECT id FROM posts WHERE slug = ? LIMIT 1');
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $lookup->execute([$slug]);
                    if (!$lookup->fetchColumn()) break;
                    $slug = $slugBase . '-' . bin2hex(random_bytes(6));
                }
                $pdo->prepare("INSERT INTO posts (title, slug, summary, content, status, schema_type, thumbnail, thumbnail_alt, author_name, ai_import_token, created_at, updated_at)
                    VALUES (?, ?, '', '', 0, 'BlogPosting', '', '', ?, ?, NOW(), NOW())")
                    ->execute([$title, $slug, $author, $token]);
                $id = (int) $pdo->lastInsertId();
                $created = 1;
                $pdo->prepare("INSERT INTO audit_logs (user_id,username,action,resource_type,resource_id,details,ip_address,user_agent) VALUES (?, 'AI Worker', 'ai_write_created', 'post', ?, ?, '', '')")
                    ->execute([(int) $job['owner_id'], $id, 'ai-write:' . $token]);
                if ($categoryId > 0 && function_exists('blog_sync_post_categories')) {
                    $primary = blog_sync_post_categories($pdo, $id, [$categoryId]);
                    if ($primary !== null) {
                        $pdo->prepare('UPDATE posts SET primary_category_id=? WHERE id=?')->execute([$primary, $id]);
                    }
                }
                $stored = $pdo->prepare('SELECT * FROM posts WHERE id=? LIMIT 1');
                $stored->execute([$id]);
                $row = $stored->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id, 'title' => $title, 'slug' => $slug];
            }

            $cp = [
                'entity_kind' => 'post', 'row' => $row, 'title' => (string) ($row['title'] ?? $title),
                'topic' => $topic, 'brief' => $brief, 'target_words' => $targetWords, 'image_count' => $imageCount,
                'want_thumb' => $withThumb, 'status_target' => $statusTarget,
                'category_id' => $categoryId, 'author_name' => $author, 'two_pass' => $twoPass,
                'import_token' => $token, 'save' => true, 'persisted' => true, 'dirty' => true,
                'cache_invalidated' => false, 'snapshot' => ai_entity_snapshot($row, 'post'),
                'tags_snapshot' => ai_runner_post_tags_snapshot($pdo, $id),
                'images' => [], 'warnings' => [],
            ];
            if ($ideaId > 0) {
                ai_write_idea_touch($pdo, $ideaId, [
                    'status' => 'running', 'job_id' => (int) $job['id'], 'post_id' => $id,
                    'message' => '',
                ]);
            }
            $next = ai_write_prepare_next($cp);
            $job['entity_id'] = $id;
            $job['checkpoint'] = $cp;
            $job['stage'] = $next;
            $job['result'] = [
                'success' => true, 'id' => $id, 'title' => (string) ($row['title'] ?? $title),
                'saved' => true, 'created' => $created, 'edit_url' => 'edit.php?id=' . $id,
            ];
        });
    }
}

if (!function_exists('ai_write_thumbnail')) {
    /**
     * Stage 'write_thumb': tạo thumbnail bằng image model + nén WebP + ghi posts.thumbnail.
     * Provider lỗi retryable -> throw để stage chạy lại; lỗi vĩnh viễn/không cấu hình
     * -> cảnh báo và đi tiếp (không chặn việc viết bài).
     */
    function ai_write_thumbnail(array &$job, float $deadline): void
    {
        $cp = $job['checkpoint'];
        $next = ai_write_next_image_stage($cp, 0);
        if (empty($job['entity_id'])) {
            ai_job_checkpoint($job, $cp, $next);
            return;
        }
        $pdo = ai_jobs_pdo();
        $current = ai_runner_load_entity($pdo, 'post', (int) $job['entity_id']);
        ai_runner_assert_snapshot($job, $current, 'post', $pdo);
        if (trim((string) ($current['thumbnail'] ?? '')) !== '' || !empty($cp['thumbnail'])) {
            ai_job_checkpoint($job, $cp, $next);
            return;
        }
        if (!function_exists('llm_feature_available') || !llm_feature_available('image')) {
            $cp['warnings'][] = ['code' => 'image_not_configured', 'message' => 'Chưa cấu hình model tạo ảnh; bài viết không có thumbnail tự động.'];
            ai_job_checkpoint($job, $cp, $next);
            return;
        }
        $topicT = (string) ($cp['topic'] ?? $cp['title']);
        $titleT = (string) ($cp['title'] ?? $topicT);
        $prompt = 'Poster bìa (key visual / thumbnail) cho bài blog, phong cách editorial hiện đại, sinh động, màu sắc rực rỡ hài hòa, '
            . 'bố cục có điểm nhấn mạnh, chiều sâu, ánh sáng ấn tượng, chất lượng marketing 4k. '
            . 'Chủ đề bài viết: "' . $topicT . '". '
            . 'IN CHỮ TIẾNG VIỆT rõ ràng, ĐÚNG CHÍNH TẢ CÓ DẤU, typography hiện đại đẹp mắt dễ đọc: '
            . 'tiêu đề lớn là điểm nhấn chính: "' . $titleT . '". '
            . 'TUYỆT ĐỐI: không watermark, không logo thương hiệu, không chữ vô nghĩa, không sai chính tả.';
        try {
            $thumbnail = ai_write_generate_image((int) $job['id'], 0, $prompt, (string) $cp['title'], $deadline);
        } catch (AiJobException $e) {
            if ($e->retryable) throw $e;
            $cp['warnings'][] = ['code' => 'thumbnail_failed', 'message' => 'Không tạo được thumbnail: ' . ai_jobs_safe_message($e->errorCode)];
            ai_job_checkpoint($job, $cp, $next);
            return;
        }
        if ($thumbnail === null) {
            $cp['warnings'][] = ['code' => 'thumbnail_failed', 'message' => 'Không lưu được thumbnail từ dữ liệu AI trả về.'];
            ai_job_checkpoint($job, $cp, $next);
            return;
        }
        $deleteOrphan = static function (string $rel): void {
            $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR : dirname(__DIR__) . DIRECTORY_SEPARATOR;
            $absolute = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($absolute)) @unlink($absolute);
        };
        try {
            ai_job_transaction($job, static function (PDO $pdo) use (&$job, $cp, $thumbnail, $next): void {
                $current = ai_runner_load_entity($pdo, 'post', (int) $job['entity_id'], true);
                ai_runner_assert_snapshot($job, $current, 'post', $pdo);
                $alt = mb_substr((string) ($cp['title'] ?? ''), 0, 255, 'UTF-8');
                $stmt = $pdo->prepare("UPDATE posts SET thumbnail=?, thumbnail_alt=? WHERE id=? AND (thumbnail IS NULL OR thumbnail='')");
                $stmt->execute([$thumbnail, $alt, (int) $job['entity_id']]);
                if ($stmt->rowCount() !== 1) {
                    $cp['warnings'][] = ['code' => 'thumbnail_conflict', 'message' => 'Thumbnail đã được đặt thủ công; giữ ảnh hiện có.'];
                } else {
                    $cp['thumbnail'] = $thumbnail;
                }
                $stored = ai_runner_load_entity($pdo, 'post', (int) $job['entity_id']);
                $cp['row'] = $stored;
                $cp['snapshot'] = ai_entity_snapshot($stored, 'post');
                $cp['tags_snapshot'] = ai_runner_post_tags_snapshot($pdo, (int) $job['entity_id']);
                $cp['dirty'] = true;
                $cp['cache_invalidated'] = false;
                $cp['persisted'] = true;
                $job['checkpoint'] = $cp;
                $job['stage'] = $next;
                $job['result'] = ai_runner_result($job, $cp);
            });
        } catch (Throwable $e) {
            // Transaction rollback -> post không tham chiếu file; xóa file mồ côi.
            $deleteOrphan($thumbnail);
            throw $e;
        }
        if (($job['checkpoint']['thumbnail'] ?? '') === $thumbnail) {
            ai_write_register_media($thumbnail, (string) $cp['title']);
        } else {
            // Admin đã đặt thumbnail khác trong lúc chạy -> file vừa tạo không được dùng.
            $deleteOrphan($thumbnail);
        }
    }
}

if (!function_exists('ai_write_image_step')) {
    /**
     * Stage 'write_img_N': tạo ảnh minh họa thứ N trong nội dung (tối đa 3),
     * lưu + nén WebP, path nằm trong checkpoint['images'] để chèn vào HTML lúc save.
     */
    function ai_write_image_step(array &$job, float $deadline, int $index): void
    {
        $cp = $job['checkpoint'];
        $imageCount = (int) ($cp['image_count'] ?? 0);
        $next = ai_write_next_image_stage($cp, $index);
        if ($index < 1 || $index > $imageCount) {
            ai_job_checkpoint($job, $cp, $next);
            return;
        }
        $cp['images'] = is_array($cp['images'] ?? null) ? $cp['images'] : [];
        if (count($cp['images']) >= $index) {
            ai_job_checkpoint($job, $cp, $next);
            return;
        }
        if (!function_exists('llm_feature_available') || !llm_feature_available('image')) {
            $cp['warnings'][] = ['code' => 'image_not_configured', 'message' => 'Chưa cấu hình model tạo ảnh; bỏ qua ảnh minh họa.'];
            ai_job_checkpoint($job, $cp, 'write_article');
            return;
        }
        $angles = [
            1 => ['khái niệm tổng quan, bối cảnh sử dụng', 'Tổng quan'],
            2 => ['quy trình hoặc các bước thực hiện', 'Quy trình'],
            3 => ['kết quả, lợi ích thực tế đạt được', 'Kết quả'],
        ];
        [$angle, $caption] = $angles[$index] ?? $angles[1];
        $prompt = 'Ảnh poster minh họa trong bài blog, phong cách editorial hiện đại, sinh động, giàu chi tiết, '
            . 'màu sắc hài hòa, bố cục đẹp, ánh sáng ấn tượng, chất lượng 4k. '
            . 'Chủ đề bài viết: "' . (string) ($cp['topic'] ?? $cp['title']) . '" — khía cạnh minh họa: ' . $angle . '. '
            . 'Có thể IN CHỮ TIẾNG VIỆT ngắn gọn, ĐÚNG CHÍNH TẢ CÓ DẤU (nhãn/caption nhỏ hoặc vài keyword): ví dụ "' . $caption . '". '
            . 'TUYỆT ĐỐI: không watermark, không logo thương hiệu, không chữ vô nghĩa, không sai chính tả.';
        try {
            $rel = ai_write_generate_image((int) $job['id'], $index, $prompt, (string) $cp['title'], $deadline);
        } catch (AiJobException $e) {
            if ($e->retryable) throw $e;
            $cp['warnings'][] = ['code' => 'image_failed', 'message' => 'Không tạo được ảnh minh họa ' . $index . ': ' . ai_jobs_safe_message($e->errorCode)];
            ai_job_checkpoint($job, $cp, $next);
            return;
        }
        if ($rel === null) {
            $cp['warnings'][] = ['code' => 'image_failed', 'message' => 'Không lưu được ảnh minh họa ' . $index . '.'];
            ai_job_checkpoint($job, $cp, $next);
            return;
        }
        $cp['images'][] = $rel;
        ai_job_checkpoint($job, $cp, $next, ai_runner_result($job, $cp));
        ai_write_register_media($rel, (string) $cp['title']);
    }
}

if (!function_exists('ai_write_article')) {
    /** Stage 'write_article': pass A — tiêu đề + mô tả + (toàn bộ | phần đầu) nội dung. */
    function ai_write_article(array &$job, float $deadline): void
    {
        $cp = $job['checkpoint'];
        if (trim((string) ($cp['content'] ?? '')) !== '' && empty($cp['two_pass'])) {
            ai_job_checkpoint($job, $cp, 'seo', ai_runner_result($job, $cp));
            return;
        }
        // Grounding: lấy tin tức mới nhất trước khi viết (cache trong checkpoint
        // để retry không fetch lại). Lỗi fetch -> '' -> prompt giữ nguyên.
        if (!isset($cp['research'])) {
            $cp['research'] = ai_write_research((string) ($cp['topic'] ?? $cp['title'] ?? ''), $deadline);
        }
        $res = ai_generate_topic_article(ai_write_gen_topic($cp), (int) ($cp['target_words'] ?? 1200), ai_runner_llm_options($deadline));
        if (empty($res['ok'])) throw ai_runner_error($res);
        if (trim((string) ($res['title'] ?? '')) !== '') $cp['title'] = (string) $res['title'];
        $cp['description'] = (string) ($res['description'] ?? '');
        $cp['content'] = (string) ($res['content'] ?? '');
        $cp['model'] = (string) ($res['model'] ?? '');
        $next = !empty($res['needs_part_b']) && !empty($cp['two_pass']) ? 'write_article_b' : 'seo';
        ai_job_checkpoint($job, $cp, $next, ai_runner_result($job, $cp));
    }
}

if (!function_exists('ai_write_article_b')) {
    /** Stage 'write_article_b': pass B — phần còn lại + FAQ + kết luận cho bài dài. */
    function ai_write_article_b(array &$job, float $deadline): void
    {
        $cp = $job['checkpoint'];
        $partA = trim((string) ($cp['content'] ?? ''));
        if ($partA === '') {
            ai_job_checkpoint($job, $cp, 'write_article');
            return;
        }
        $res = ai_continue_topic_article(ai_write_gen_topic($cp), (string) ($cp['title'] ?? ''), $partA, (int) ($cp['target_words'] ?? 1500), ai_runner_llm_options($deadline));
        if (empty($res['ok'])) throw ai_runner_error($res);
        $cp['content'] = rtrim($partA) . "\n" . (string) $res['content'];
        $cp['model'] = (string) ($res['model'] ?? $cp['model'] ?? '');
        ai_job_checkpoint($job, $cp, 'seo', ai_runner_result($job, $cp));
    }
}

if (!function_exists('ai_write_finalize')) {
    /**
     * Stage 'save': chèn ảnh vào nội dung, cập nhật post (title/slug/nội dung/SEO/status),
     * đồng bộ category + tags, ghi audit. Chạy trong transaction của ai_job_transaction.
     */
    function ai_write_finalize(array &$job): void
    {
        $cp = $job['checkpoint'];
        $content = trim((string) ($cp['content'] ?? ''));
        if ($content === '' || empty($job['entity_id'])) {
            throw new AiJobException('invalid_output', 'The generated article is empty.');
        }
        $images = is_array($cp['images'] ?? null) ? $cp['images'] : [];
        if ($images && function_exists('embed_images_in_content')) {
            // Chuẩn convention site: đường dẫn trong content có leading slash.
            $images = array_map(static fn($p) => '/' . ltrim((string) $p, '/'), $images);
            $content = embed_images_in_content($content, $images, (string) ($cp['title'] ?? ''));
            $cp['content'] = $content;
        }
        $statusTarget = (int) ($cp['status_target'] ?? 0);
        if (!in_array($statusTarget, [0, 1, 2], true)) $statusTarget = 0;
        $categoryId = (int) ($cp['category_id'] ?? 0);

        ai_job_transaction($job, static function (PDO $pdo) use (&$job, $cp, $statusTarget, $categoryId): void {
            $current = ai_runner_load_entity($pdo, 'post', (int) $job['entity_id'], true);
            ai_runner_assert_snapshot($job, $current, 'post', $pdo);

            $title = mb_substr(trim(strip_tags((string) ($cp['title'] ?? ''))), 0, 255, 'UTF-8');
            if ($title === '') $title = (string) $current['title'];

            // Đổi slug theo tiêu đề AI mới (bài đang nháp, chưa public nên an toàn).
            $slug = (string) ($current['slug'] ?? '');
            if ($title !== (string) $current['title']) {
                $base = create_slug($title);
                if ($base !== '') {
                    $base = mb_substr($base, 0, 200, 'UTF-8');
                    if ($base !== $slug) {
                        $candidate = $base;
                        $lookup = $pdo->prepare('SELECT id FROM posts WHERE slug = ? AND id <> ? LIMIT 1');
                        for ($attempt = 0; $attempt < 5; $attempt++) {
                            $lookup->execute([$candidate, (int) $job['entity_id']]);
                            if (!$lookup->fetchColumn()) break;
                            $candidate = $base . '-' . bin2hex(random_bytes(6));
                        }
                        $slug = $candidate;
                    }
                }
            }

            $summary = trim((string) ($cp['description'] ?? ''));
            $pdo->prepare('UPDATE posts SET title=?, slug=?, summary=?, content=?, meta_title=?, meta_description=?, meta_keywords=?, focus_keyword=?, status=?, updated_at=NOW() WHERE id=?')
                ->execute([
                    $title, $slug, $summary, (string) $cp['content'],
                    (string) ($cp['meta_title'] ?? ''), (string) ($cp['meta_description'] ?? ''),
                    (string) ($cp['meta_keywords'] ?? ''), (string) ($cp['focus_keyword'] ?? ''),
                    $statusTarget, (int) $job['entity_id'],
                ]);

            if ($categoryId > 0 && function_exists('blog_sync_post_categories')) {
                $primary = blog_sync_post_categories($pdo, (int) $job['entity_id'], [$categoryId]);
                if ($primary !== null) {
                    $pdo->prepare('UPDATE posts SET primary_category_id=? WHERE id=?')->execute([$primary, (int) $job['entity_id']]);
                }
            }
            if (!empty($cp['meta_keywords']) && function_exists('blog_sync_post_tags')) {
                blog_sync_post_tags($pdo, (int) $job['entity_id'], (string) $cp['meta_keywords']);
            }
            $pdo->prepare("INSERT INTO audit_logs (user_id,username,action,resource_type,resource_id,details,ip_address,user_agent) VALUES (?, 'AI Worker', 'ai_write_saved', 'post', ?, ?, '', '')")
                ->execute([(int) $job['owner_id'], (int) $job['entity_id'], 'status=' . $statusTarget]);

            $stored = ai_runner_load_entity($pdo, 'post', (int) $job['entity_id']);
            $cp['row'] = $stored;
            $cp['title'] = $title;
            $ideaId = (int) ($job['payload']['idea_id'] ?? 0);
            if ($ideaId > 0) {
                ai_write_idea_touch($pdo, $ideaId, [
                    'status' => 'done', 'post_id' => (int) $job['entity_id'],
                    'message' => 'Đã viết xong.',
                ]);
            }
            $cp['snapshot'] = ai_entity_snapshot($stored, 'post');
            $cp['tags_snapshot'] = ai_runner_post_tags_snapshot($pdo, (int) $job['entity_id']);
            $cp['dirty'] = true;
            $cp['cache_invalidated'] = false;
            $cp['persisted'] = true;
            $job['checkpoint'] = $cp;
            $job['stage'] = 'cache';
            $result = ai_runner_result($job, $cp);
            $result['url'] = '/' . ltrim($slug, '/') . '/';
            $result['edit_url'] = 'edit.php?id=' . (int) $job['entity_id'];
            $result['message'] = 'Đã viết xong bài "' . $title . '".';
            $job['result'] = $result;
        });
    }
}

if (!function_exists('ai_run_write_stage')) {
    /** Dispatcher cho kind='write'; 'seo'/'cache' tái dùng stage chung của runner. */
    function ai_run_write_stage(array &$job, float $deadline): void
    {
        switch ((string) $job['stage']) {
            case 'prepare':
                ai_write_prepare($job);
                return;
            case 'write_thumb':
                ai_write_thumbnail($job, $deadline);
                return;
            case 'write_article':
                ai_write_article($job, $deadline);
                return;
            case 'write_article_b':
                ai_write_article_b($job, $deadline);
                return;
            case 'seo':
                ai_runner_run_seo($job, $deadline);
                return;
            case 'save':
                ai_write_finalize($job);
                return;
            case 'cache':
                ai_runner_cache($job);
                return;
        }
        if (preg_match('/^write_img_(\d+)$/', (string) $job['stage'], $m)) {
            ai_write_image_step($job, $deadline, (int) $m[1]);
            return;
        }
        throw new AiJobException('invalid_input', 'The write job stage is not supported.');
    }
}
