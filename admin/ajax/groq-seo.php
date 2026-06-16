<?php
/**
 * admin/ajax/groq-seo.php
 * Calls Groq API to generate SEO data from page title/description/content.
 * Returns JSON: { meta_title, meta_description, focus_keyword, meta_keywords[] }
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

require_valid_csrf_token(true);

// Cấu hình LLM dùng chung (Settings -> tab AI / LLM)
$api_key = trim((string) get_setting('llm_api_key', ''));
$model   = trim((string) get_setting('llm_model', 'llama-3.3-70b-versatile'));

if ($api_key === '') {
    echo json_encode(['success' => false, 'error' => 'no_key']);
    exit;
}

// Inputs
$title = trim(strip_tags($_POST['title'] ?? ''));
$desc = trim(strip_tags($_POST['description'] ?? ''));
$content = trim(strip_tags($_POST['content'] ?? ''));

if ($title === '') {
    echo json_encode(['success' => false, 'error' => 'no_title']);
    exit;
}

$seo = ai_generate_seo($title, $desc, $content);
if (empty($seo['ok'])) {
    echo json_encode(['success' => false, 'error' => $seo['error'] ?? 'api_error']);
    exit;
}

echo json_encode([
    'success' => true,
    'meta_title' => $seo['meta_title'],
    'meta_description' => $seo['meta_description'],
    'focus_keyword' => $seo['focus_keyword'],
    'meta_keywords' => $seo['meta_keywords'],
    'model' => $seo['model'] ?? $model,
], JSON_UNESCAPED_UNICODE);
