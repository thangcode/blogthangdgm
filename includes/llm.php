<?php
/**
 * includes/llm.php
 * Helper gọi LLM tương thích OpenAI Chat Completions API (CLIProxy).
 * Đọc cấu hình từ bảng settings: llm_endpoint, llm_api_key, llm_model, llm_model_fallback,
 *   llm_temperature, llm_max_tokens.
 *
 * Cách dùng:
 *   $res = llm_chat([
 *       ['role' => 'system', 'content' => 'Bạn là biên tập viên...'],
 *       ['role' => 'user',   'content' => 'Viết lại tiêu đề: ...'],
 *   ]);
 *   if ($res['ok']) { echo $res['text']; }
 */

if (!function_exists('llm_chat')) {
    function llm_chat(array $messages, array $opts = []): array
    {
        $endpoint = (string) get_setting('llm_endpoint', '');
        $api_key  = (string) get_setting('llm_api_key', '');
        $model    = (string) ($opts['model'] ?? get_setting('llm_model', 'gpt-4o-mini'));
        $fallback = (string) get_setting('llm_model_fallback', '');

        if ($endpoint === '' || $api_key === '') {
            return ['ok' => false, 'text' => '', 'error' => 'Chưa cấu hình LLM endpoint hoặc API key.'];
        }

        $opts['temperature'] = $opts['temperature'] ?? (float) get_setting('llm_temperature', '0.6');
        $opts['max_tokens']  = $opts['max_tokens'] ?? (int) get_setting('llm_max_tokens', '1200');

        $models = array_values(array_unique(array_filter([$model, $fallback], 'strlen')));
        $last = ['ok' => false, 'text' => '', 'error' => 'Không gọi được LLM.'];
        foreach ($models as $m) {
            $res = llm_chat_raw($endpoint, $api_key, $m, $messages, $opts);
            if (!empty($res['ok'])) {
                return $res;
            }
            $last = $res;
        }
        return $last;
    }
}

if (!function_exists('llm_chat_raw')) {
    /**
     * Gọi trực tiếp 1 model với endpoint/key chỉ định (không đọc DB).
     */
    function llm_chat_raw(string $endpoint, string $api_key, string $model, array $messages, array $opts = []): array
    {
        $endpoint = rtrim($endpoint, '/');
        if ($endpoint === '' || $api_key === '' || $model === '') {
            return ['ok' => false, 'text' => '', 'error' => 'Thiếu endpoint, API key hoặc model.'];
        }
        $url = $endpoint . '/chat/completions';
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $opts['temperature'] ?? 0.6,
            'max_tokens'  => $opts['max_tokens'] ?? 1200,
        ];
        if (!empty($opts['response_format'])) {
            $body['response_format'] = $opts['response_format'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        // Bật xác thực TLS khi có CA bundle (bảo vệ API key khỏi MITM); fallback an toàn cho local.
        foreach ((function_exists('app_curl_ssl_opts') ? app_curl_ssl_opts() : [CURLOPT_SSL_VERIFYPEER => false]) as $optKey => $optVal) {
            curl_setopt($ch, $optKey, $optVal);
        }
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'text' => '', 'error' => 'cURL error: ' . $err];
        }
        $json = json_decode($resp, true);
        if ($http >= 200 && $http < 300 && is_array($json) && !empty($json['choices'][0]['message']['content'])) {
            return [
                'ok'         => true,
                'text'       => trim((string) $json['choices'][0]['message']['content']),
                'model_used' => $model,
                'usage'      => $json['usage'] ?? null,
            ];
        }
        return ['ok' => false, 'text' => '', 'error' => 'HTTP ' . $http . ' — ' . substr((string) $resp, 0, 300)];
    }
}

if (!function_exists('embed_images_in_content')) {
    /**
     * Chèn ảnh (URL tuyệt đối) vào nội dung HTML, phân bố đều sau các thẻ </p>. Tối đa 4 ảnh.
     */
    function embed_images_in_content(string $html, array $images, string $alt = ''): string
    {
        $urls = [];
        foreach ($images as $u) {
            $u = trim((string) $u);
            if ($u !== '' && !in_array($u, $urls, true)) {
                $urls[] = $u;
            }
        }
        $urls = array_slice($urls, 0, 4);
        if (empty($urls) || $html === '') {
            return $html;
        }
        $alt = trim($alt);
        $fig = function (string $url) use ($alt): string {
            // Chuẩn hóa: ảnh của chính site -> đường dẫn tương đối gốc để không dính domain (local/prod)
            $parsed = @parse_url($url);
            if (!empty($parsed['host']) && !empty($parsed['path']) && stripos($parsed['path'], '/uploads/') !== false) {
                $url = $parsed['path'];
            }
            $u = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $a = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
            return '<figure style="margin:1.5rem 0;text-align:center"><img src="' . $u . '" alt="' . $a . '" loading="lazy" style="max-width:100%;height:auto;border-radius:8px" /></figure>';
        };
        if (stripos($html, '</p>') === false) {
            $out = $html;
            foreach ($urls as $u) { $out .= $fig($u); }
            return $out;
        }
        $segments = preg_split('#</p>#i', $html);
        $para_count = count($segments) - 1;
        $n = count($urls);
        if ($para_count < 2) {
            $out = $html;
            foreach ($urls as $u) { $out .= $fig($u); }
            return $out;
        }
        $placement = [];
        for ($k = 0; $k < $n; $k++) {
            $pos = (int) round(($k + 1) * $para_count / ($n + 1));
            $pos = max(1, min($para_count - 1, $pos));
            while (isset($placement[$pos]) && $pos < $para_count - 1) { $pos++; }
            if (!isset($placement[$pos])) { $placement[$pos] = $k; }
        }
        $out = '';
        $placed = [];
        for ($i = 0; $i < count($segments); $i++) {
            $out .= $segments[$i];
            if ($i < $para_count) {
                $out .= '</p>';
                $para_no = $i + 1;
                if (isset($placement[$para_no])) {
                    $out .= $fig($urls[$placement[$para_no]]);
                    $placed[$placement[$para_no]] = true;
                }
            }
        }
        for ($k = 0; $k < $n; $k++) {
            if (empty($placed[$k])) { $out .= $fig($urls[$k]); }
        }
        return $out;
    }
}

if (!function_exists('ai_content_rules')) {
    function ai_content_rules(): string
    {
        return "Quy tắc bắt buộc:\n"
            . "- Viết tiếng Việt tự nhiên, không sao chép nguyên văn nguồn.\n"
            . "- Giữ đúng thông số kỹ thuật, không bịa thông tin.\n"
            . "- Không dùng từ 'chính hãng', 'chính thức', 'ủy quyền' trừ khi nguồn nêu rõ.\n"
            . "- Không hứa hẹn quá đà, không cam kết giá vì giá có thể đổi.\n"
            . "- Không nhắc tên sàn TMĐT cụ thể.";
    }
}

if (!function_exists('ai_generate_article')) {
    /**
     * Sinh đồng thời tiêu đề + mô tả ngắn + nội dung HTML (bắt đầu H2) + chèn ảnh.
     * @return array ['ok'=>bool, 'title','description','content','model','error']
     */
    function ai_generate_article(string $name, string $seedText = '', array $images = []): array
    {
        $rules = ai_content_rules();
        $system = "Bạn là chuyên gia copywriting + SEO thương mại điện tử người Việt. Dựa trên thông tin sản phẩm, viết lại đồng thời 3 phần và CHỈ trả về JSON object đúng định dạng:\n"
            . "{\n  \"title\": \"Tiêu đề ngắn gọn, chuẩn SEO, tối đa ~65 ký tự\",\n  \"description\": \"Mô tả ngắn 2-3 câu, chuẩn SEO, chứa từ khóa chính\",\n  \"content\": \"Nội dung chi tiết HTML, BẮT ĐẦU bằng thẻ <h2>\"\n}\n\n"
            . "YÊU CẦU TỪNG PHẦN:\n"
            . "1) title: Viết lại tên sản phẩm NGẮN GỌN, tự nhiên, dễ đọc, chuẩn SEO (~50-65 ký tự). Giữ thương hiệu và thông tin nhận diện chính; bỏ từ thừa, lặp và từ ngoại ngữ vô nghĩa. Ưu tiên gọn và đúng ngữ pháp tiếng Việt.\n"
            . "2) content: HTML thuần (KHÔNG markdown). PHẢI bắt đầu bằng thẻ <h2>, TUYỆT ĐỐI KHÔNG dùng <h1> (vì H1 tiêu đề đã render tự động). Viết bài CHI TIẾT, DÀI (khoảng 600-1000 từ), nhiều mục có chiều sâu.\n"
            . "   - Cấu trúc đề xuất (dùng <h2> cho mỗi mục, <h3> cho mục con): Giới thiệu tổng quan; Đặc điểm & thông số nổi bật; Lợi ích thực tế khi dùng; Đối tượng phù hợp / trường hợp sử dụng; Hướng dẫn/mẹo sử dụng; Câu hỏi thường gặp (FAQ).\n"
            . "   - Định dạng: <p> đoạn văn, <ul><li> liệt kê đặc điểm/thông số, <strong> nhấn mạnh ý chính. Có thể dùng <table> cho bảng thông số nếu hợp lý.\n"
            . "   - CHUẨN SEO: đoạn mở đầu chứa từ khóa chính; rải từ khóa + từ đồng nghĩa (LSI) tự nhiên trong các <h2>/<h3> và đoạn văn; không nhồi nhét.\n"
            . "   - CHUẨN GEO (tối ưu cho AI/answer engine như Google AI Overviews, ChatGPT, Perplexity): trả lời trực tiếp, rõ ràng ngay đầu mỗi mục; nêu dữ kiện cụ thể (số liệu, thông số, đơn vị) để AI dễ trích dẫn; nêu rõ tên sản phẩm/thương hiệu (thực thể); mục FAQ gồm 3-5 câu hỏi người dùng hay hỏi kèm câu trả lời ngắn gọn, đầy đủ.\n"
            . "3) description: 2-3 câu súc tích, chuẩn SEO, có chứa tên/từ khóa sản phẩm.\n\n"
            . $rules;
        $user = "Tên gốc: " . ($name !== '' ? $name : $seedText) . "\nNội dung/mô tả gốc:\n" . $seedText;
        $res = llm_chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], ['max_tokens' => 4000, 'temperature' => 0.7, 'response_format' => ['type' => 'json_object']]);

        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Lỗi gọi AI.'];
        }
        $parsed = json_decode($res['text'], true);
        if (!is_array($parsed) && preg_match('/\{.*\}/s', $res['text'], $mm)) {
            $parsed = json_decode($mm[0], true);
        }
        if (!is_array($parsed)) {
            return ['ok' => false, 'error' => 'AI trả về không đúng định dạng.'];
        }
        $content_html = trim((string) ($parsed['content'] ?? ''));
        $content_html = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content_html);
        $content_html = preg_replace('#<(/?)h1(\s[^>]*)?>#i', '<$1h2$2>', $content_html);
        $content_html = embed_images_in_content($content_html, $images, (string) ($parsed['title'] ?? $name));
        return [
            'ok'          => true,
            'title'       => trim((string) ($parsed['title'] ?? '')),
            'description' => trim((string) ($parsed['description'] ?? '')),
            'content'     => $content_html,
            'model'       => $res['model_used'] ?? '',
        ];
    }
}

if (!function_exists('seo_truncate')) {
    function seo_truncate(string $str, int $max): string
    {
        $str = trim($str);
        if (mb_strlen($str) <= $max) return $str;
        $cut = mb_substr($str, 0, $max);
        $last_space = mb_strrpos($cut, ' ');
        if ($last_space !== false) { $cut = mb_substr($cut, 0, $last_space); }
        return rtrim($cut, ' .,;:-');
    }
}

if (!function_exists('seo_normalize_text')) {
    function seo_normalize_text(string $str): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($str)));
    }
}

if (!function_exists('seo_extract_series_markers')) {
    function seo_extract_series_markers(string $title): array
    {
        $title = seo_normalize_text($title);
        $markers = [];

        if (preg_match_all('/\b20\d{2}\b/u', $title, $m)) {
            foreach ($m[0] as $year) {
                $markers[] = $year;
            }
        }

        if (preg_match_all('/\b(?:bài|bai|phần|phan|tập|tap|part|episode|ep)\s*[-#:]*\s*\d+[a-z]?/iu', $title, $m)) {
            foreach ($m[0] as $part) {
                $markers[] = seo_normalize_text($part);
            }
        }

        return array_values(array_unique($markers));
    }
}

if (!function_exists('seo_text_has_marker')) {
    function seo_text_has_marker(string $text, string $marker): bool
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower(seo_normalize_text($text), 'UTF-8') : strtolower(seo_normalize_text($text));
        $marker = function_exists('mb_strtolower') ? mb_strtolower(seo_normalize_text($marker), 'UTF-8') : strtolower(seo_normalize_text($marker));
        return $marker !== '' && mb_strpos($text, $marker) !== false;
    }
}

if (!function_exists('seo_compact_source_title')) {
    function seo_compact_source_title(string $title): string
    {
        $title = seo_normalize_text($title);
        $title = preg_replace('/^(hướng\s*dẫn|huong\s*dan)\s+/iu', '', $title);
        if (preg_match('/\b[a-z0-9]+\s+ads\b/iu', $title)) {
            $title = preg_replace('/^(quảng\s*cáo|quang\s*cao)\s+/iu', '', (string) $title);
        }
        return seo_normalize_text((string) $title);
    }
}

if (!function_exists('seo_apply_series_markers')) {
    function seo_apply_series_markers(string $sourceTitle, string $aiTitle, string $focusKeyword = '', int $max = 65): string
    {
        $sourceTitle = seo_normalize_text($sourceTitle);
        $aiTitle = seo_normalize_text($aiTitle);
        $markers = seo_extract_series_markers($sourceTitle);

        if (empty($markers)) {
            return seo_truncate($aiTitle !== '' ? $aiTitle : $sourceTitle, $max);
        }

        $sourceCompact = seo_compact_source_title($sourceTitle);
        if ($sourceCompact !== '' && mb_strlen($sourceCompact, 'UTF-8') <= $max) {
            return $sourceCompact;
        }

        $candidate = $aiTitle !== '' ? $aiTitle : $sourceCompact;
        foreach ($markers as $marker) {
            if (!seo_text_has_marker($candidate, $marker)) {
                $candidate .= ' - ' . $marker;
            }
        }
        $candidate = seo_normalize_text($candidate);
        if (mb_strlen($candidate, 'UTF-8') <= $max) {
            return $candidate;
        }

        if (preg_match('/\b[a-z0-9]+\s+ads\b/iu', $candidate)) {
            $candidate = preg_replace('/^(hướng\s*dẫn\s+)?(quảng\s*cáo|quang\s*cao)\s+/iu', '', $candidate);
            $candidate = seo_normalize_text((string) $candidate);
            if (mb_strlen($candidate, 'UTF-8') <= $max) {
                return $candidate;
            }
        }

        return seo_truncate($candidate, $max);
    }
}

if (!function_exists('ai_generate_seo')) {
    /**
     * Sinh dữ liệu SEO (meta_title, meta_description, focus_keyword, meta_keywords).
     * @return array ['ok'=>bool,'meta_title','meta_description','focus_keyword','meta_keywords','model','error']
     */
    function ai_generate_seo(string $title, string $desc = '', string $content = ''): array
    {
        $title = trim(strip_tags($title));
        if ($title === '') {
            return ['ok' => false, 'error' => 'no_title'];
        }
        $context = "TIÊU ĐỀ GỐC: $title\n";
        if ($desc !== '') $context .= "MÔ TẢ GỐC: " . mb_substr(strip_tags($desc), 0, 400) . "\n";
        if ($content !== '') $context .= "NỘI DUNG CHI TIẾT: " . mb_substr(strip_tags($content), 0, 800) . "\n";

        $system_prompt = "Bạn là chuyên gia SEO. Tạo dữ liệu Meta tiếng Việt chất lượng cao.\n"
            . "QUY TẮC:\n"
            . "1. focus_keyword: cụm 2-4 từ người dùng hay tìm, liên quan tiêu đề, ngắn tự nhiên, không ký tự đặc biệt () + .\n"
            . "2. meta_title: 52-58 ký tự, chứa tên sản phẩm + focus_keyword, kết thúc bằng từ hoàn chỉnh (không lơ lửng 'tại','với','và','để','của').\n"
            . "3. meta_description: 130-150 ký tự, kết thúc bằng câu hoàn chỉnh (có dấu . hoặc !), chứa chính xác focus_keyword. Cấu trúc: lợi ích + tính năng + CTA ngắn.\n"
            . "4. meta_keywords: mảng 6-8 từ khóa phụ liên quan.\n"
            . "CHỈ trả về JSON: {\"focus_keyword\":\"\",\"meta_title\":\"\",\"meta_description\":\"\",\"meta_keywords\":[]}. Không giải thích.";
        $system_prompt .= "\nSERIES RULE: If the source title contains a year, Bai/Phan/Tap/Part/Ep number, keep those markers in meta_title.";

        $res = llm_chat([
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => "Dữ liệu nguồn:\n$context\nHãy tạo ra kết quả SEO hoàn hảo nhất."],
        ], ['temperature' => 0.1, 'max_tokens' => 800, 'response_format' => ['type' => 'json_object']]);

        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'api_error'];
        }
        $seo = json_decode($res['text'], true);
        if (!is_array($seo) && preg_match('/\{.*\}/s', $res['text'], $mm)) {
            $seo = json_decode($mm[0], true);
        }
        if (!is_array($seo)) {
            return ['ok' => false, 'error' => 'api_error'];
        }
        $kw = $seo['meta_keywords'] ?? [];
        if (is_string($kw)) { $kw = array_filter(array_map('trim', explode(',', $kw))); }
        $focus = trim((string) ($seo['focus_keyword'] ?? ''));
        $metaTitle = seo_apply_series_markers($title, (string) ($seo['meta_title'] ?? ''), $focus, 65);
        return [
            'ok'               => true,
            'meta_title'       => $metaTitle,
            'meta_description' => seo_truncate((string) ($seo['meta_description'] ?? ''), 160),
            'focus_keyword'    => $focus,
            'meta_keywords'    => array_values((array) $kw),
            'model'            => $res['model_used'] ?? '',
        ];
    }
}

if (!function_exists('ai_auto_categorize')) {
    /**
     * Tự phân loại sản phẩm vào danh mục phù hợp nhất.
     * 1) Khớp từ khóa tên danh mục. 2) Hỏi LLM nếu fail. 3) Trả 0 nếu không xác định.
     */
    function ai_auto_categorize($pdo, string $name, string $description = ''): int
    {
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        try {
            $rows = $pdo->query("SELECT id, name, description FROM categories WHERE status = 1 AND slug <> 'chua-phan-loai' AND deleted_at IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            try {
                $rows = $pdo->query("SELECT id, name, description FROM categories WHERE status = 1 AND slug <> 'chua-phan-loai' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e2) {
                return 0;
            }
        } catch (Throwable $e) {
            return 0;
        }
        if (empty($rows)) {
            return 0;
        }
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($name . ' ' . $description, 'UTF-8') : strtolower($name . ' ' . $description);
        foreach ($rows as $c) {
            $needle = function_exists('mb_strtolower') ? mb_strtolower((string) $c['name'], 'UTF-8') : strtolower((string) $c['name']);
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return (int) $c['id'];
            }
        }
        $list = '';
        foreach ($rows as $c) {
            $list .= '- ' . (int) $c['id'] . ': ' . $c['name'];
            $cdesc = trim((string) ($c['description'] ?? ''));
            if ($cdesc !== '') {
                $list .= ' — ' . mb_substr($cdesc, 0, 160);
            }
            $list .= "\n";
        }
        $res = llm_chat([
            ['role' => 'system', 'content' => 'Bạn phân loại sản phẩm vào đúng 1 danh mục. CHỈ trả về SỐ id của danh mục phù hợp nhất, hoặc 0 nếu không danh mục nào phù hợp. Không giải thích, không thêm chữ.'],
            ['role' => 'user', 'content' => "Tên sản phẩm: $name\n\nDanh mục có sẵn:\n$list\nID phù hợp nhất:"],
        ], ['max_tokens' => 10, 'temperature' => 0]);
        if (!empty($res['ok']) && preg_match('/\d+/', (string) $res['text'], $mm)) {
            $cand = (int) $mm[0];
            foreach ($rows as $c) {
                if ((int) $c['id'] === $cand) {
                    return $cand;
                }
            }
        }
        return 0;
    }
}


if (!function_exists('ai_rewrite_blog_post')) {
    /**
     * Viết lại một bài BLOG hoàn chỉnh, chuẩn SEO + GEO từ ý tưởng/nội dung gốc.
     * Nội dung bắt đầu từ <h2> (H1 = tiêu đề đã render tự động). Giữ lại video YouTube nếu có.
     * @return array ['ok'=>bool,'content','description','model','error']
     */
    function ai_rewrite_blog_post(string $title, string $seed = '', string $youtubeId = ''): array
    {
        $title = trim(strip_tags($title));
        if ($title === '') {
            return ['ok' => false, 'error' => 'no_title'];
        }
        $system = "Bạn là biên tập viên blog kiêm chuyên gia SEO/GEO người Việt. Viết lại thành một BÀI BLOG hoàn chỉnh, hữu ích, chuẩn SEO và GEO. CHỈ trả về JSON object:\n"
            . "{\n  \"description\": \"Tóm tắt 2-3 câu, chứa từ khóa chính\",\n  \"content\": \"Nội dung HTML, BẮT ĐẦU bằng thẻ <h2>\"\n}\n\n"
            . "YÊU CẦU content:\n"
            . "- HTML thuần (KHÔNG markdown), BẮT ĐẦU bằng <h2>, TUYỆT ĐỐI KHÔNG dùng <h1> (vì H1 tiêu đề đã render tự động).\n"
            . "- Dài khoảng 700-1200 từ, mạch lạc, văn phong blog tự nhiên, không sáo rỗng.\n"
            . "- Cấu trúc: đoạn mở bài ngắn; nhiều mục <h2> (dùng <h3> cho mục con khi cần); <p> đoạn văn; <ul><li> liệt kê; <strong> nhấn mạnh; đoạn kết; mục <h2>Câu hỏi thường gặp với 3-5 câu hỏi (mỗi câu là <h3> + <p> trả lời).\n"
            . "- CHUẨN SEO: suy ra từ khóa chính từ tiêu đề, đặt vào đoạn mở đầu; rải từ khóa + từ đồng nghĩa (LSI) tự nhiên trong các <h2>/<h3> và đoạn văn; không nhồi nhét.\n"
            . "- CHUẨN GEO (tối ưu cho Google AI Overviews, ChatGPT, Perplexity): trả lời trực tiếp, rõ ràng ngay đầu mỗi mục; nêu dữ kiện cụ thể (số liệu, bước làm, ví dụ) để AI dễ trích dẫn; nêu rõ thực thể (tên công cụ, nền tảng, khái niệm).\n";
        if ($youtubeId !== '') {
            $system .= "- Nếu nội dung gốc lấy từ YouTube: chỉ khai thác ý chính và kiến thức trong video; bỏ qua phần liên hệ/cuối video như số điện thoại, Zalo, Facebook, email, link mua hàng, link khóa học, mã giảm giá, kêu gọi đăng ký kênh, lời chào/tạm biệt, lịch livestream hoặc thông tin quảng bá không cần thiết. Không đưa các thông tin liên hệ đó vào đoạn kết, FAQ, meta description hoặc CTA.\n"
                . "- Video YouTube sẽ được hệ thống chèn ở cuối bài, vì vậy KHÔNG tự tạo iframe, shortcode, URL video hoặc đoạn mời xem video trong nội dung AI trả về.\n";
        }
        $user = "TIÊU ĐỀ BÀI: $title\n";
        if (trim($seed) !== '') {
            $user .= "Ý TƯỞNG / NỘI DUNG GỐC (tham khảo, viết lại hoàn toàn bằng lời của bạn):\n" . mb_substr(strip_tags($seed), 0, 3000, 'UTF-8') . "\n";
        }
        $user .= "\nHãy viết bài blog hoàn chỉnh theo đúng yêu cầu.";

        $res = llm_chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], ['max_tokens' => 4000, 'temperature' => 0.7, 'response_format' => ['type' => 'json_object']]);

        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'api_error'];
        }
        $parsed = json_decode($res['text'], true);
        if (!is_array($parsed) && preg_match('/\{.*\}/s', $res['text'], $mm)) {
            $parsed = json_decode($mm[0], true);
        }
        if (!is_array($parsed)) {
            return ['ok' => false, 'error' => 'bad_format'];
        }
        $content = trim((string) ($parsed['content'] ?? ''));
        $content = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
        $content = preg_replace('#<(/?)h1(\s[^>]*)?>#i', '<$1h2$2>', $content); // ép H1 -> H2

        // Giữ lại video YouTube ở cuối bài nếu bài gốc có.
        if ($youtubeId !== '' && function_exists('blog_youtube_iframe')) {
            $content = rtrim($content) . "\n" . blog_youtube_iframe($youtubeId);
        }

        return [
            'ok'          => true,
            'content'     => $content,
            'description' => trim((string) ($parsed['description'] ?? '')),
            'model'       => $res['model_used'] ?? '',
        ];
    }
}
