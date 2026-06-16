<?php
// includes/totp.php
// TOTP (RFC 6238) thuần PHP cho 2FA Google Authenticator / Authy.
// Không cần thư viện ngoài. Dùng SHA1, 6 chữ số, chu kỳ 30 giây.

if (!function_exists('totp_base32_encode')) {
    function totp_base32_encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $bits = 0;
        $val = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $val = ($val << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $out .= $alphabet[($val >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= $alphabet[($val << (5 - $bits)) & 31];
        }
        return $out;
    }
}

if (!function_exists('totp_base32_decode')) {
    function totp_base32_decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        $bits = 0;
        $val = 0;
        $out = '';
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $idx = strpos($alphabet, $b32[$i]);
            if ($idx === false) {
                continue;
            }
            $val = ($val << 5) | $idx;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($val >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }
        return $out;
    }
}

if (!function_exists('totp_hotp')) {
    /** HOTP cho 1 counter (RFC 4226). $secretBin là khóa nhị phân. */
    function totp_hotp(string $secretBin, int $counter): int
    {
        $binCounter = pack('J', $counter); // unsigned 64-bit big-endian
        $hash = hash_hmac('sha1', $binCounter, $secretBin, true);
        $offset = ord($hash[19]) & 0x0F;
        $part = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return $part % 1000000;
    }
}

if (!function_exists('totp_code_at')) {
    function totp_code_at(string $secretBase32, int $timestamp): string
    {
        $secretBin = totp_base32_decode($secretBase32);
        if ($secretBin === '') {
            return '';
        }
        $counter = (int) floor($timestamp / 30);
        return str_pad((string) totp_hotp($secretBin, $counter), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('totp_verify')) {
    /** Xác thực mã 6 số, cho phép lệch ±$window chu kỳ (mặc định ±1 = ±30s). */
    function totp_verify(string $secretBase32, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', (string) $code);
        if (strlen($code) !== 6) {
            return false;
        }
        $secretBin = totp_base32_decode($secretBase32);
        if ($secretBin === '') {
            return false;
        }
        $counter = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = str_pad((string) totp_hotp($secretBin, $counter + $i), 6, '0', STR_PAD_LEFT);
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('totp_generate_secret')) {
    function totp_generate_secret(int $bytes = 20): string
    {
        return totp_base32_encode(random_bytes($bytes));
    }
}

if (!function_exists('totp_provisioning_uri')) {
    function totp_provisioning_uri(string $secret, string $label, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&period=30&digits=6&algorithm=SHA1';
    }
}
