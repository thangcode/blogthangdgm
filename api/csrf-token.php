<?php
/**
 * api/csrf-token.php
 * Trả CSRF token của phiên hiện tại (JSON) để client nạp vào trang khi Page Cache bật.
 * - HTML cache dùng chung KHÔNG chứa token thật của từng phiên, nên client gọi endpoint
 *   này (cùng cookie phiên) để lấy token đúng rồi điền vào meta + các input ẩn.
 * - Endpoint động, không bao giờ được cache ở trình duyệt/CDN.
 */
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo json_encode(['token' => generate_csrf_token()], JSON_UNESCAPED_UNICODE);
