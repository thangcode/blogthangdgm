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

/* ============================================================================
 * TẦNG PROVIDER / MODEL (mới)
 * - ai_providers: nhà cung cấp (openai|anthropic), endpoint, api key (mã hóa)
 * - ai_models:    model thuộc provider (chat|image, can_vision)
 * - settings ai_<feature>_primary / ai_<feature>_fallback: gán model theo tính năng
 * Các hàm cũ (llm_chat/llm_chat_raw) vẫn giữ để tương thích ngược.
 * ========================================================================== */

if (!function_exists('llm_request_options')) {
    /** Options use seconds; deadline is an absolute microtime(true) timestamp shared by all attempts. */
    function llm_request_options(array $opts = [], string $feature = 'write'): array
    {
        $budget = ['write' => 150.0, 'seo' => 60.0, 'test' => 15.0, 'vision' => 60.0, 'image' => 150.0][$feature] ?? 150.0;
        $number = static function ($value, float $default): float {
            return is_numeric($value) && is_finite((float) $value) ? (float) $value : $default;
        };
        $opts['timeout'] = max(0.001, min(600.0, $number($opts['timeout'] ?? $budget, $budget)));
        $opts['connect_timeout'] = max(0.001, min(30.0, $number($opts['connect_timeout'] ?? 10.0, 10.0)));
        $opts['deadline'] = $number($opts['deadline'] ?? null, microtime(true) + $opts['timeout']);
        return $opts;
    }
}

if (!function_exists('llm_error')) {
    /** Public errors contain only stable codes and fixed messages, never raw provider responses. */
    function llm_error(string $code, bool $retryable = false, string $model = ''): array
    {
        $messages = [
            'timeout' => 'Yêu cầu AI đã hết thời gian chờ.',
            'transport_error' => 'Không thể kết nối dịch vụ AI.',
            'transport_unavailable' => 'Máy chủ chưa hỗ trợ kết nối AI.',
            'invalid_request' => 'Yêu cầu AI không hợp lệ.',
            'not_configured' => 'Chưa cấu hình tính năng AI.',
            'db_unavailable' => 'Không thể kết nối cơ sở dữ liệu.',
            'authentication_failed' => 'Xác thực dịch vụ AI thất bại.',
            'rate_limited' => 'Dịch vụ AI đang bận. Vui lòng thử lại sau.',
            'provider_unavailable' => 'Dịch vụ AI tạm thời không khả dụng.',
            'request_rejected' => 'Dịch vụ AI đã từ chối yêu cầu.',
            'truncated_output' => 'Nội dung AI bị cắt ngắn nên chưa được lưu. Vui lòng tăng giới hạn đầu ra hoặc thử lại.',
            'invalid_output' => 'Nội dung AI trả về không đầy đủ hoặc không hợp lệ.',
            'content_filtered' => 'Dịch vụ AI không thể hoàn tất nội dung này.',
        ];
        if (!isset($messages[$code])) {
            $code = 'request_rejected';
        }
        $result = ['ok' => false, 'text' => '', 'error' => $messages[$code], 'code' => $code, 'retryable' => $retryable];
        if ($model !== '') {
            $result['model_used'] = $model;
        }
        return $result;
    }
}

if (!function_exists('llm_is_local_host')) {
    /** Chỉ cho phép fallback TLS local trên các hostname phát triển rõ ràng. */
    function llm_is_local_host(): bool
    {
        if (PHP_SAPI === 'cli') {
            $cwd = str_replace('\\', '/', (string) getcwd());
            return stripos(PHP_OS, 'WIN') === 0 && preg_match('~/(?:xamp|xampp)/htdocs(?:/|$)~i', $cwd) === 1;
        }
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host);
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
            || substr($host, -5) === '.test' || substr($host, -6) === '.local';
    }
}

if (!function_exists('llm_curl_ssl_options')) {
    /** Reuse the application CA policy, but never disable TLS verification outside local development. */
    function llm_curl_ssl_options(): array
    {
        $ssl = function_exists('app_curl_ssl_opts')
            ? app_curl_ssl_opts()
            : [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        if (($ssl[CURLOPT_SSL_VERIFYPEER] ?? true) === false && !llm_is_local_host()) {
            $ssl[CURLOPT_SSL_VERIFYPEER] = true;
            $ssl[CURLOPT_SSL_VERIFYHOST] = 2;
            unset($ssl[CURLOPT_CAINFO]);
        }
        return $ssl;
    }
}

if (!function_exists('llm_http_json')) {
    /** One bounded JSON transport shared by all provider adapters; no automatic transport retry. */
    function llm_http_json(string $url, array $headers, array $body, array $opts = []): array
    {
        $opts = llm_request_options($opts);
        $remaining = min((float) $opts['timeout'], (float) $opts['deadline'] - microtime(true));
        if ($remaining <= 0) {
            return llm_error('timeout', true);
        }
        if (!function_exists('curl_init')) {
            return llm_error('transport_unavailable');
        }
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return llm_error('invalid_request');
        }
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return llm_error('invalid_request');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return llm_error('transport_error', true);
        }
        $response = '';
        $tooLarge = false;
        $errno = 0;
        $http = 0;
        try {
            curl_setopt_array($ch, llm_curl_ssl_options());
            $curlOpts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encoded,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT_MS => max(1, (int) floor($remaining * 1000)),
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) floor(min($remaining, (float) $opts['connect_timeout']) * 1000)),
                CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge, $opts): int {
                    if (microtime(true) >= (float) $opts['deadline']) {
                        return 0;
                    }
                    if (strlen($response) + strlen($chunk) > 24 * 1024 * 1024) {
                        $tooLarge = true;
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS')) {
                $curlOpts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            curl_setopt_array($ch, $curlOpts);
            $ok = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
        } catch (Throwable $e) {
            return llm_error('transport_error', true);
        } finally {
            curl_close($ch);
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT || microtime(true) >= (float) $opts['deadline']) {
            return llm_error('timeout', true);
        }
        if ($tooLarge) {
            return llm_error('invalid_output');
        }
        if ($ok === false) {
            return llm_error('transport_error', in_array($errno, [5, 6, 7, 18, 52, 55, 56], true));
        }
        if (in_array($http, [401, 403], true)) {
            return llm_error('authentication_failed');
        }
        if ($http === 429) {
            return llm_error('rate_limited', true);
        }
        if (in_array($http, [408, 504], true)) {
            return llm_error('timeout', true);
        }
        if ($http >= 500) {
            return llm_error('provider_unavailable', true);
        }
        if ($http < 200 || $http >= 300) {
            return llm_error('request_rejected');
        }
        $json = json_decode($response, true);
        return is_array($json) ? ['ok' => true, 'json' => $json] : llm_error('invalid_output');
    }
}

if (!function_exists('llm_pdo')) {
    /** Lấy PDO dùng chung; tự kết nối lại nếu chưa có. */
    function llm_pdo(): ?PDO
    {
        global $pdo;
        if ($pdo instanceof PDO) {
            try {
                if (function_exists('db_ensure_alive')) db_ensure_alive($pdo);
                return $pdo;
            } catch (Throwable $e) {
                return null;
            }
        }
        if (function_exists('db_connect')) {
            try { $pdo = db_connect(); return $pdo; } catch (Throwable $e) { return null; }
        }
        return null;
    }
}

if (!function_exists('llm_get_provider')) {
    /** Đọc 1 provider theo id, giải mã api_key -> khóa 'api_key'. */
    function llm_get_provider(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM ai_providers WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch();
        } catch (Throwable $e) { return null; }
        if (!$row) return null;
        $row['api_key'] = function_exists('app_decrypt') ? app_decrypt((string) ($row['api_key_enc'] ?? '')) : (string) ($row['api_key_enc'] ?? '');
        return $row;
    }
}

if (!function_exists('llm_get_model')) {
    /** Đọc 1 model theo id kèm provider (đã giải mã key) -> khóa 'provider'. */
    function llm_get_model(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM ai_models WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch();
        } catch (Throwable $e) { return null; }
        if (!$row) return null;
        $row['provider'] = llm_get_provider($pdo, (int) $row['provider_id']);
        return $row;
    }
}

if (!function_exists('llm_pick_enabled_model')) {
    /**
     * Chọn 1 model đang bật cùng loại làm dự phòng an toàn khi model gán bị thiếu/tắt.
     * $kind: 'chat'|'image'; $preferVision: ưu tiên can_vision=1 (cho tính năng vision).
     */
    function llm_pick_enabled_model(PDO $pdo, string $kind, bool $preferVision = false, array $excludeIds = []): ?array
    {
        try {
            $sql = "SELECT m.* FROM ai_models m
                    JOIN ai_providers p ON p.id = m.provider_id
                    WHERE m.status = 1 AND p.status = 1 AND m.kind = ?
                    ORDER BY " . ($preferVision ? "m.can_vision DESC, " : "") . "m.sort_order ASC, m.id ASC";
            $st = $pdo->prepare($sql);
            $st->execute([$kind]);
            while ($row = $st->fetch()) {
                if (in_array((int) $row['id'], array_map('intval', $excludeIds), true)) continue;
                $row['provider'] = llm_get_provider($pdo, (int) $row['provider_id']);
                $provider = $row['provider'] ?? [];
                $apiType = (string) ($provider['api_type'] ?? 'openai');
                if ($preferVision && empty($row['can_vision'])) continue;
                if ($kind === 'image' && $apiType !== 'openai') continue;
                if (trim((string) ($row['model_name'] ?? '')) === ''
                    || trim((string) ($provider['endpoint'] ?? '')) === ''
                    || trim((string) ($provider['api_key'] ?? '')) === '') continue;
                return $row;
            }
        } catch (Throwable $e) { return null; }
        return null;
    }
}

if (!function_exists('llm_resolve_feature')) {
    /**
     * Phân giải model chính + dự phòng cho 1 tính năng.
     * $feature: 'write'|'seo'|'vision'|'image'
     * Auto-fallback an toàn: model gán null/tắt -> lấy model bật cùng loại.
     * @return array{primary:?array,fallback:?array}
     */
    function llm_resolve_feature(PDO $pdo, string $feature): array
    {
        $kind = ($feature === 'image') ? 'image' : 'chat';
        $preferVision = ($feature === 'vision');

        $primaryId  = (int) get_setting('ai_' . $feature . '_primary', '0');
        $fallbackId = (int) get_setting('ai_' . $feature . '_fallback', '0');

        $isUsable = function (?array $m) use ($kind, $preferVision): bool {
            return $m && ($m['kind'] ?? 'chat') === $kind
                && (!$preferVision || !empty($m['can_vision']))
                && ($kind !== 'image' || (string) ($m['provider']['api_type'] ?? 'openai') === 'openai')
                && (int) ($m['status'] ?? 0) === 1
                && !empty($m['provider']) && (int) ($m['provider']['status'] ?? 0) === 1
                && trim((string) ($m['model_name'] ?? '')) !== ''
                && trim((string) ($m['provider']['endpoint'] ?? '')) !== ''
                && trim((string) ($m['provider']['api_key'] ?? '')) !== '';
        };

        $primary  = llm_get_model($pdo, $primaryId);
        $fallback = llm_get_model($pdo, $fallbackId);
        if (!$isUsable($primary))  { $primary  = null; }
        if (!$isUsable($fallback)) { $fallback = null; }

        // Nếu thiếu model chính, ưu tiên model dự phòng được cấu hình trước khi tự chọn.
        if (!$primary && $fallback) {
            $primary = $fallback;
            $fallback = null;
        }
        if (!$primary) {
            $primary = llm_pick_enabled_model($pdo, $kind, $preferVision);
            if (!$isUsable($primary)) $primary = null;
        }
        if ($primary && $fallback && (int) $primary['id'] === (int) $fallback['id']) $fallback = null;
        if (!$fallback && $primary) {
            $fallback = llm_pick_enabled_model($pdo, $kind, $preferVision, [(int) $primary['id']]);
            if (!$isUsable($fallback)) $fallback = null;
        }
        return ['primary' => $primary, 'fallback' => $fallback];
    }
}

if (!function_exists('llm_feature_available')) {
    /** Availability returns a boolean only; provider credentials never leave the backend. */
    function llm_feature_available(string $feature = 'write'): bool
    {
        if (!in_array($feature, ['write', 'seo', 'vision', 'image', 'test'], true)) {
            return false;
        }
        try {
            $pdo = llm_pdo();
            if ($pdo && !empty(llm_resolve_feature($pdo, $feature)['primary'])) {
                return true;
            }
            return $feature !== 'image' && function_exists('get_setting')
                && trim((string) get_setting('llm_endpoint', '')) !== ''
                && trim((string) get_setting('llm_api_key', '')) !== ''
                && trim((string) get_setting('llm_model', 'gpt-4o-mini')) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('llm_call_model')) {
    /**
     * Gọi 1 model đã phân giải (kèm provider). Route theo provider.api_type.
     * @return array ['ok','text','model_used','error','latency_ms','image_url'?]
     */
    function llm_call_model(array $model, array $messages, array $opts = []): array
    {
        $provider = $model['provider'] ?? null;
        if (!$provider) {
            return llm_error('not_configured');
        }
        $endpoint = rtrim((string) ($provider['endpoint'] ?? ''), '/');
        $apiKey   = (string) ($provider['api_key'] ?? '');
        $apiType  = (string) ($provider['api_type'] ?? 'openai');
        $modelName = (string) ($model['model_name'] ?? '');
        if ($endpoint === '' || $apiKey === '' || $modelName === '') {
            return llm_error('not_configured');
        }

        $t0 = microtime(true);
        if (($model['kind'] ?? 'chat') === 'image') {
            $res = llm_openai_image($endpoint, $apiKey, $modelName, $messages, $opts);
        } elseif ($apiType === 'anthropic') {
            $res = llm_anthropic_chat($endpoint, $apiKey, $modelName, $messages, $opts);
        } else {
            $res = llm_chat_raw($endpoint, $apiKey, $modelName, $messages, $opts);
        }
        $res['latency_ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $res;
    }
}

if (!function_exists('llm_call_feature')) {
    /**
     * Gọi model chính cho tính năng; nếu lỗi thì tự động gọi dự phòng.
     * $opts được bổ sung temperature/max_tokens mặc định từ settings nếu thiếu.
     */
    function llm_call_feature(string $feature, array $messages, array $opts = []): array
    {
        $opts = llm_request_options($opts, $feature);
        if ((float) $opts['deadline'] <= microtime(true)) {
            return llm_error('timeout', true);
        }
        try {
            $pdo = llm_pdo();
        } catch (Throwable $e) {
            return llm_error('db_unavailable', true);
        }
        if (!$pdo) {
            return llm_error('db_unavailable', true);
        }
        $opts['temperature'] = $opts['temperature'] ?? (float) get_setting('llm_temperature', '0.6');
        $opts['max_tokens']  = $opts['max_tokens'] ?? (int) get_setting('llm_max_tokens', '1200');
        if ($feature === 'image' && empty($opts['size'])) {
            $opts['size'] = (string) get_setting('ai_image_size', '1024x1024');
        }

        $r = llm_resolve_feature($pdo, $feature);
        $chain = array_values(array_filter([$r['primary'], $r['fallback']]));
        if (empty($chain)) {
            return llm_error('not_configured');
        }
        $last = ['ok' => false, 'text' => '', 'error' => 'Không gọi được LLM.'];
        foreach ($chain as $m) {
            $res = llm_call_model($m, $messages, $opts);
            if (!empty($res['ok']) && !empty($opts['parse']) && is_callable($opts['parse'])) {
                try {
                    $parsed = ($opts['parse'])((string) ($res['text'] ?? ''), $res);
                } catch (Throwable $e) {
                    $parsed = null;
                }
                if ($parsed === null || $parsed === false) {
                    $res = llm_error('invalid_output', false, (string) ($res['model_used'] ?? ''));
                } else {
                    $res['parsed'] = $parsed;
                }
            }
            if (!empty($res['ok'])) {
                return $res;
            }
            $last = $res;
            if (!empty($opts['single_attempt']) || microtime(true) >= (float) $opts['deadline']) {
                break;
            }
        }
        return $last;
    }
}

if (!function_exists('llm_chat')) {
    /**
     * Tương thích ngược. Mặc định dùng tính năng 'write' (viết bài) theo cấu hình mới.
     * Nếu $opts['feature'] có -> dùng feature đó. Nếu $opts['model'] có (chuỗi) ->
     * ép dùng cấu hình llm_* cũ để test override trực tiếp.
     */
    function llm_chat(array $messages, array $opts = []): array
    {
        $feature = (string) ($opts['feature'] ?? (!empty($opts['model']) ? 'test' : 'write'));
        $opts = llm_request_options($opts, $feature);
        if ((float) $opts['deadline'] <= microtime(true)) {
            return llm_error('timeout', true);
        }
        // Đường test override cũ: có model chuỗi + endpoint/key trong settings cũ.
        if (!empty($opts['model']) && is_string($opts['model'])) {
            $endpoint = (string) get_setting('llm_endpoint', '');
            $api_key  = (string) get_setting('llm_api_key', '');
            if ($endpoint !== '' && $api_key !== '') {
                $opts['temperature'] = $opts['temperature'] ?? (float) get_setting('llm_temperature', '0.6');
                $opts['max_tokens']  = $opts['max_tokens'] ?? (int) get_setting('llm_max_tokens', '1200');
                return llm_chat_raw($endpoint, $api_key, (string) $opts['model'], $messages, $opts);
            }
        }

        unset($opts['feature']);
        $res = llm_call_feature($feature, $messages, $opts);
        // Chỉ fallback legacy khi cấu hình provider/model mới chưa tồn tại.
        if (empty($res['ok']) && ($res['code'] ?? '') === 'not_configured') {
            return llm_chat_legacy($messages, $opts);
        }
        return $res;
    }
}

if (!function_exists('llm_chat_legacy')) {
    /** Đường gọi cũ dựa trên settings llm_endpoint/llm_api_key/llm_model (dự phòng khi chưa có provider). */
    function llm_chat_legacy(array $messages, array $opts = []): array
    {
        $endpoint = (string) get_setting('llm_endpoint', '');
        $api_key  = (string) get_setting('llm_api_key', '');
        $model    = (string) ($opts['model'] ?? get_setting('llm_model', 'gpt-4o-mini'));
        $fallback = (string) get_setting('llm_model_fallback', '');

        if ($endpoint === '' || $api_key === '') {
            return llm_error('not_configured');
        }

        $opts = llm_request_options($opts, (string) ($opts['feature'] ?? 'write'));
        $opts['temperature'] = $opts['temperature'] ?? (float) get_setting('llm_temperature', '0.6');
        $opts['max_tokens']  = $opts['max_tokens'] ?? (int) get_setting('llm_max_tokens', '1200');

        $models = array_values(array_unique(array_filter([$model, $fallback], 'strlen')));
        $last = ['ok' => false, 'text' => '', 'error' => 'Không gọi được LLM.'];
        foreach ($models as $m) {
            $res = llm_chat_raw($endpoint, $api_key, $m, $messages, $opts);
            if (!empty($res['ok']) && !empty($opts['parse']) && is_callable($opts['parse'])) {
                try {
                    $parsed = ($opts['parse'])((string) ($res['text'] ?? ''), $res);
                } catch (Throwable $e) {
                    $parsed = null;
                }
                if ($parsed === null || $parsed === false) {
                    $res = llm_error('invalid_output', false, (string) ($res['model_used'] ?? $m));
                } else {
                    $res['parsed'] = $parsed;
                }
            }
            if (!empty($res['ok'])) {
                return $res;
            }
            $last = $res;
            if (!empty($opts['single_attempt']) || microtime(true) >= (float) $opts['deadline']) {
                break;
            }
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
            return llm_error('invalid_request', false, $model);
        }
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $opts['temperature'] ?? 0.6,
            'max_tokens'  => $opts['max_tokens'] ?? 1200,
        ];
        if (!empty($opts['response_format'])) {
            $body['response_format'] = $opts['response_format'];
        }
        $http = llm_http_json($endpoint . '/chat/completions', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ], $body, $opts);
        if (empty($http['ok'])) {
            $http['model_used'] = $model;
            return $http;
        }
        $json = $http['json'];
        $choice = $json['choices'][0] ?? null;
        if (!is_array($choice)) {
            return llm_error('invalid_output', false, $model);
        }
        $finishReason = strtolower((string) ($choice['finish_reason'] ?? ''));
        if (in_array($finishReason, ['length', 'max_tokens'], true)) {
            return llm_error('truncated_output', false, $model);
        }
        if (in_array($finishReason, ['content_filter', 'content_filtered', 'safety'], true)) {
            return llm_error('content_filtered', false, $model);
        }
        $content = $choice['message']['content'] ?? '';
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $text .= (string) ($part['text'] ?? '');
                }
            }
            $content = $text;
        }
        $text = trim((string) $content);
        if ($text === '') {
            return llm_error('invalid_output', false, $model);
        }
        return [
            'ok'         => true,
            'text'       => $text,
            'model_used' => $model,
            'usage'      => $json['usage'] ?? null,
        ];
    }
}

if (!function_exists('llm_anthropic_chat')) {
    /**
     * Gọi Anthropic Messages API: POST {endpoint}/messages.
     * Tự tách 'system' khỏi messages; convert content (text + ảnh base64 block).
     * Hỗ trợ response_format json_object bằng cách nhắc trong system (Anthropic không có tham số này).
     */
    function llm_anthropic_chat(string $endpoint, string $api_key, string $model, array $messages, array $opts = []): array
    {
        $endpoint = rtrim($endpoint, '/');
        $url = $endpoint . '/messages';

        $systemParts = [];
        $conv = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $content = $m['content'] ?? '';
            if ($role === 'system') {
                $systemParts[] = is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE);
                continue;
            }
            $conv[] = ['role' => ($role === 'assistant' ? 'assistant' : 'user'), 'content' => llm_anthropic_content($content)];
        }
        if (!empty($opts['response_format']['type']) && $opts['response_format']['type'] === 'json_object') {
            $systemParts[] = 'CHỈ trả về JSON hợp lệ, không thêm giải thích, không dùng code fence.';
        }

        $body = [
            'model'      => $model,
            'max_tokens' => (int) ($opts['max_tokens'] ?? 1200),
            'messages'   => $conv,
        ];
        if (!empty($systemParts)) {
            $body['system'] = implode("\n", $systemParts);
        }
        if (isset($opts['temperature'])) {
            $body['temperature'] = (float) $opts['temperature'];
        }

        $http = llm_http_json($url, [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ], $body, $opts);
        if (empty($http['ok'])) {
            $http['model_used'] = $model;
            return $http;
        }
        $json = $http['json'];
        $stopReason = strtolower((string) ($json['stop_reason'] ?? ''));
        if ($stopReason === 'max_tokens') {
            return llm_error('truncated_output', false, $model);
        }
        if (in_array($stopReason, ['refusal', 'safety', 'content_filter', 'content_filtered'], true)) {
            return llm_error('content_filtered', false, $model);
        }
        if (!in_array($stopReason, ['', 'end_turn', 'stop_sequence'], true)) {
            return llm_error('invalid_output', false, $model);
        }
        $text = '';
        foreach (($json['content'] ?? []) as $blk) {
            if (is_array($blk) && ($blk['type'] ?? '') === 'text') {
                $text .= (string) ($blk['text'] ?? '');
            }
        }
        $text = trim($text);
        if ($text === '') {
            return llm_error('invalid_output', false, $model);
        }
        return [
            'ok'         => true,
            'text'       => $text,
            'model_used' => $model,
            'usage'      => $json['usage'] ?? null,
        ];
    }
}

if (!function_exists('llm_anthropic_content')) {
    /** Convert content OpenAI-style -> Anthropic content blocks (text / image base64). */
    function llm_anthropic_content($content): array
    {
        if (is_string($content)) {
            return [['type' => 'text', 'text' => $content]];
        }
        if (!is_array($content)) {
            return [['type' => 'text', 'text' => (string) $content]];
        }
        $blocks = [];
        foreach ($content as $part) {
            $type = $part['type'] ?? '';
            if ($type === 'text') {
                $blocks[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')];
            } elseif ($type === 'image_url') {
                $u = is_array($part['image_url'] ?? null) ? (string) ($part['image_url']['url'] ?? '') : (string) ($part['image_url'] ?? '');
                if (strncmp($u, 'data:', 5) === 0 && preg_match('#^data:([^;]+);base64,(.*)$#s', $u, $mm)) {
                    $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mm[1], 'data' => $mm[2]]];
                } elseif ($u !== '') {
                    $blocks[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $u]];
                }
            }
        }
        return $blocks ?: [['type' => 'text', 'text' => '']];
    }
}

if (!function_exists('llm_openai_image')) {
    /**
     * Tạo ảnh qua OpenAI-compatible: POST {endpoint}/images/generations.
     * $messages: lấy prompt từ message user cuối (text). $opts['size'] = '1024x1024'.
     * Nếu $opts['image_base64'] có -> dùng /images/edits (multipart).
     * @return array ['ok','text'(prompt),'image_url','model_used','error']
     */
    function llm_openai_image(string $endpoint, string $api_key, string $model, array $messages, array $opts = []): array
    {
        $endpoint = rtrim($endpoint, '/');
        $prompt = '';
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? '') !== 'system') {
                $c = $m['content'] ?? '';
                $prompt = is_string($c) ? $c : (string) ($c[0]['text'] ?? '');
                if ($prompt !== '') break;
            }
        }
        if ($prompt === '') {
            return llm_error('invalid_request', false, $model);
        }
        $size = (string) ($opts['size'] ?? '1024x1024');
        $http = llm_http_json($endpoint . '/images/generations', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ], ['model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => 1], $opts);
        if (empty($http['ok'])) {
            $http['model_used'] = $model;
            return $http;
        }
        $json = $http['json'];
        $d = $json['data'][0] ?? null;
        if (!is_array($d)) {
            return llm_error('invalid_output', false, $model);
        }
        $imageUrl = !empty($d['url']) ? (string) $d['url']
            : (!empty($d['b64_json']) ? 'data:image/png;base64,' . $d['b64_json'] : '');
        if ($imageUrl === '') {
            return llm_error('invalid_output', false, $model);
        }
        return [
            'ok'         => true,
            'text'       => $prompt,
            'image_url'  => $imageUrl,
            'model_used' => $model,
        ];
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

if (!function_exists('llm_parse_json_object')) {
    function llm_parse_json_object(string $text): ?array
    {
        $parsed = json_decode($text, true);
        if (!is_array($parsed) && preg_match('/\{.*\}/s', $text, $mm)) {
            $parsed = json_decode($mm[0], true);
        }
        return is_array($parsed) ? $parsed : null;
    }
}

if (!function_exists('ai_generate_article')) {
    /**
     * Sinh đồng thời tiêu đề + mô tả ngắn + nội dung HTML (bắt đầu H2) + chèn ảnh.
     * @return array ['ok'=>bool, 'title','description','content','model','error']
     */
    function ai_generate_article(string $name, string $seedText = '', array $images = [], array $opts = []): array
    {
        if (trim($name) === '' && trim($seedText) === '') {
            return ['ok' => false, 'error' => 'no_content', 'code' => 'invalid_request', 'retryable' => false, 'model' => ''];
        }
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
        ], array_replace([
            'max_tokens' => 4000,
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'],
            'parse' => static fn(string $text): ?array => llm_parse_json_object($text),
        ], $opts));

        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'error' => $res['error'] ?? 'Lỗi gọi AI.',
                'code' => $res['code'] ?? 'request_rejected',
                'retryable' => !empty($res['retryable']),
                'model' => $res['model_used'] ?? '',
            ];
        }
        $parsed = is_array($res['parsed'] ?? null) ? $res['parsed'] : llm_parse_json_object((string) ($res['text'] ?? ''));
        if (!is_array($parsed)) {
            return [
                'ok' => false,
                'error' => llm_error('invalid_output')['error'],
                'code' => 'invalid_output',
                'retryable' => false,
                'model' => $res['model_used'] ?? '',
            ];
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
    function ai_generate_seo(string $title, string $desc = '', string $content = '', array $opts = []): array
    {
        $title = trim(strip_tags($title));
        if ($title === '') {
            return ['ok' => false, 'error' => 'no_title', 'code' => 'invalid_request', 'retryable' => false, 'model' => ''];
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
        ], array_replace([
            'feature' => 'seo',
            'temperature' => 0.1,
            'max_tokens' => 800,
            'response_format' => ['type' => 'json_object'],
            'parse' => static fn(string $text): ?array => llm_parse_json_object($text),
        ], $opts));

        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'error' => $res['error'] ?? 'api_error',
                'code' => $res['code'] ?? 'request_rejected',
                'retryable' => !empty($res['retryable']),
                'model' => $res['model_used'] ?? '',
            ];
        }
        $seo = is_array($res['parsed'] ?? null) ? $res['parsed'] : llm_parse_json_object((string) ($res['text'] ?? ''));
        if (!is_array($seo)) {
            return [
                'ok' => false,
                'error' => llm_error('invalid_output')['error'],
                'code' => 'invalid_output',
                'retryable' => false,
                'model' => $res['model_used'] ?? '',
            ];
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
    function ai_rewrite_blog_post(string $title, string $seed = '', string $youtubeId = '', array $opts = []): array
    {
        $title = trim(strip_tags($title));
        if ($title === '') {
            return ['ok' => false, 'error' => 'no_title', 'code' => 'invalid_request', 'retryable' => false, 'model' => ''];
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
        ], array_replace([
            'max_tokens' => 4000,
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'],
            'parse' => static fn(string $text): ?array => llm_parse_json_object($text),
        ], $opts));

        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'error' => $res['error'] ?? 'api_error',
                'code' => $res['code'] ?? 'request_rejected',
                'retryable' => !empty($res['retryable']),
                'model' => $res['model_used'] ?? '',
            ];
        }
        $parsed = is_array($res['parsed'] ?? null) ? $res['parsed'] : llm_parse_json_object((string) ($res['text'] ?? ''));
        if (!is_array($parsed)) {
            return [
                'ok' => false,
                'error' => llm_error('invalid_output')['error'],
                'code' => 'invalid_output',
                'retryable' => false,
                'model' => $res['model_used'] ?? '',
            ];
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

if (!function_exists('ai_generate_topic_article')) {
    /**
     * Viết MỚI một bài blog hoàn chỉnh từ tiêu đề/từ khóa (không có nguồn).
     * - $targetWords < 1500: một lần gọi trả đủ {title, description, content}.
     * - $targetWords >= 1500: lần 1 trả phần đầu bài (~55%), đặt needs_part_b=true;
     *   phần còn lại được viết bởi ai_continue_topic_article() ở stage kế tiếp.
     * @return array ['ok'=>bool,'title','description','content','model','needs_part_b','error','code','retryable']
     */
    function ai_generate_topic_article(string $topic, int $targetWords = 1200, array $opts = []): array
    {
        $topic = trim(strip_tags($topic));
        if ($topic === '') {
            return ['ok' => false, 'error' => 'no_topic', 'code' => 'invalid_request', 'retryable' => false, 'model' => '', 'needs_part_b' => false];
        }
        $targetWords = max(400, min(3000, $targetWords));
        $twoPass = $targetWords >= 1500;
        $partAWords = $twoPass ? (int) round($targetWords * 0.55) : $targetWords;

        $system = "Bạn là biên tập viên blog kiêm chuyên gia SEO/GEO người Việt. Viết MỚI một BÀI BLOG hoàn chỉnh, hữu ích, chuẩn SEO và GEO từ tiêu đề/từ khóa được cung cấp. CHỈ trả về JSON object:\n"
            . "{\n  \"title\": \"Tiêu đề cuối cùng chuẩn SEO, tự nhiên, 50-70 ký tự (dùng lại tiêu đề nhập nếu đã tốt)\",\n  \"description\": \"Tóm tắt 2-3 câu, chứa từ khóa chính\",\n  \"content\": \"Nội dung HTML, BẮT ĐẦU bằng thẻ <h2>\"\n}\n\n"
            . "YÊU CẦU content:\n"
            . "- HTML thuần (KHÔNG markdown), BẮT ĐẦU bằng <h2>, TUYỆT ĐỐI KHÔNG dùng <h1> (vì H1 tiêu đề đã render tự động).\n"
            . "- Dài khoảng {$partAWords} từ" . ($twoPass ? " — ĐÂY LÀ PHẦN ĐẦU của một bài dài {$targetWords} từ: viết mở bài và các mục đầu tiên, DỪNG ở cuối một mục <h2> hoàn chỉnh, TUYỆT ĐỐI chưa viết mục FAQ hay kết luận" : "") . ". Mạch lạc, văn phong blog tự nhiên, không sáo rỗng.\n"
            . "- Cấu trúc: đoạn mở bài ngắn; nhiều mục <h2> (dùng <h3> cho mục con khi cần); <p> đoạn văn; <ul><li> liệt kê; <strong> nhấn mạnh" . ($twoPass ? "." : "; đoạn kết; mục <h2>Câu hỏi thường gặp với 3-5 câu hỏi (mỗi câu là <h3> + <p> trả lời).") . "\n"
            . "- CHUẨN SEO: suy ra từ khóa chính từ tiêu đề, đặt vào đoạn mở đầu; rải từ khóa + từ đồng nghĩa (LSI) tự nhiên trong các <h2>/<h3> và đoạn văn; không nhồi nhét.\n"
            . "- CHUẨN GEO (tối ưu cho Google AI Overviews, ChatGPT, Perplexity): trả lời trực tiếp, rõ ràng ngay đầu mỗi mục; nêu dữ kiện cụ thể (số liệu, bước làm, ví dụ) để AI dễ trích dẫn; nêu rõ thực thể (tên công cụ, nền tảng, khái niệm).\n"
            . "- KHÔNG bịa thông tin kiểm chứng: không tự đặt số liệu giá cả/thống kê cụ thể nếu không chắc; nêu xu hướng/khung tham khảo thay vì con số tuyệt đối khi thiếu dữ kiện.\n"
            . ai_content_rules();
        $user = "TIÊU ĐỀ / TỪ KHÓA BÀI VIẾT: $topic\n\nHãy viết bài blog theo đúng yêu cầu.";

        $res = llm_chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], array_replace([
            'max_tokens' => 4000,
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'],
            'parse' => static fn(string $text): ?array => llm_parse_json_object($text),
        ], $opts));

        if (empty($res['ok'])) {
            return [
                'ok' => false, 'error' => $res['error'] ?? 'api_error',
                'code' => $res['code'] ?? 'request_rejected',
                'retryable' => !empty($res['retryable']),
                'model' => $res['model_used'] ?? '', 'needs_part_b' => $twoPass,
            ];
        }
        $parsed = is_array($res['parsed'] ?? null) ? $res['parsed'] : llm_parse_json_object((string) ($res['text'] ?? ''));
        if (!is_array($parsed)) {
            return [
                'ok' => false, 'error' => llm_error('invalid_output')['error'],
                'code' => 'invalid_output', 'retryable' => false,
                'model' => $res['model_used'] ?? '', 'needs_part_b' => $twoPass,
            ];
        }
        $content = trim((string) ($parsed['content'] ?? ''));
        $content = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
        $content = preg_replace('#<(/?)h1(\s[^>]*)?>#i', '<$1h2$2>', (string) $content);
        if ($content === '' || stripos((string) $content, '<h2') === false) {
            return [
                'ok' => false, 'error' => llm_error('invalid_output')['error'],
                'code' => 'invalid_output', 'retryable' => true,
                'model' => $res['model_used'] ?? '', 'needs_part_b' => $twoPass,
            ];
        }
        return [
            'ok'          => true,
            'title'       => seo_truncate(trim((string) ($parsed['title'] ?? '')) !== '' ? (string) $parsed['title'] : $topic, 70),
            'description' => trim((string) ($parsed['description'] ?? '')),
            'content'     => $content,
            'model'       => $res['model_used'] ?? '',
            'needs_part_b' => $twoPass,
        ];
    }
}

if (!function_exists('ai_continue_topic_article')) {
    /**
     * Pass 2 cho bài dài: viết tiếp phần còn lại (mục cuối + FAQ + kết luận).
     * @return array ['ok'=>bool,'content','model','error','code','retryable']
     */
    function ai_continue_topic_article(string $topic, string $title, string $partAHtml, int $targetWords, array $opts = []): array
    {
        $topic = trim(strip_tags($topic));
        $partAHtml = trim($partAHtml);
        if ($topic === '' || $partAHtml === '') {
            return ['ok' => false, 'error' => 'no_input', 'code' => 'invalid_request', 'retryable' => false, 'model' => ''];
        }
        $tail = preg_replace('/<h1[^>]*>.*?<\/h1>/i', '', $partAHtml);
        $tail = mb_substr(strip_tags((string) $tail), -1200, null, 'UTF-8');
        $plainA = trim(preg_replace('/\s+/u', ' ', strip_tags($partAHtml)) ?? '');
        $wordsA = $plainA !== '' ? count(preg_split('/ /u', $plainA)) : 0;
        $remain = max(400, $targetWords - $wordsA);

        $system = "Bạn là biên tập viên blog kiêm chuyên gia SEO/GEO người Việt. Viết TIẾP một bài blog đang dở. CHỈ trả về JSON object:\n"
            . "{\n  \"content\": \"Phần nội dung TIẾP THEO bằng HTML, bắt đầu bằng <h2> của một mục MỚI\"\n}\n\n"
            . "YÊU CẦU:\n"
            . "- Viết phần còn lại khoảng {$remain} từ, mạch lạc nối tiếp phần trước, KHÔNG lặp lại nội dung đã viết.\n"
            . "- HTML thuần, bắt đầu bằng <h2>, không dùng <h1>, không markdown.\n"
            . "- BẮT BUỘC kết thúc bằng: một mục <h2>Câu hỏi thường gặp</h2> gồm 3-5 câu hỏi (mỗi câu là <h3> + <p> trả lời ngắn gọn), rồi mục <h2> kết luận cuối cùng.\n"
            . "- Giữ chuẩn SEO/GEO như phần trước; KHÔNG chèn ảnh, iframe, link liên hệ hay thông tin quảng bá.\n"
            . ai_content_rules();
        $user = "TIÊU ĐỀ BÀI: " . ($title !== '' ? $title : $topic) . "\n"
            . "TỪ KHÓA GỐC: $topic\n"
            . "ĐOẠN CUỐI PHẦN ĐÃ VIẾT (tham khảo để nối tiếp, không lặp lại):\n\"$tail\"\n\nHãy viết phần còn lại của bài.";

        $res = llm_chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], array_replace([
            'max_tokens' => 4000,
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'],
            'parse' => static fn(string $text): ?array => llm_parse_json_object($text),
        ], $opts));

        if (empty($res['ok'])) {
            return [
                'ok' => false, 'error' => $res['error'] ?? 'api_error',
                'code' => $res['code'] ?? 'request_rejected',
                'retryable' => !empty($res['retryable']), 'model' => $res['model_used'] ?? '',
            ];
        }
        $parsed = is_array($res['parsed'] ?? null) ? $res['parsed'] : llm_parse_json_object((string) ($res['text'] ?? ''));
        $content = is_array($parsed) ? trim((string) ($parsed['content'] ?? '')) : '';
        $content = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
        $content = preg_replace('#<(/?)h1(\s[^>]*)?>#i', '<$1h2$2>', (string) $content);
        if ($content === '' || stripos((string) $content, '<h2') === false) {
            return [
                'ok' => false, 'error' => llm_error('invalid_output')['error'],
                'code' => 'invalid_output', 'retryable' => true, 'model' => $res['model_used'] ?? '',
            ];
        }
        return ['ok' => true, 'content' => $content, 'model' => $res['model_used'] ?? ''];
    }
}
