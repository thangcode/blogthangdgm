<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();
ensure_media_library_table($pdo);

header('Content-Type: application/json; charset=UTF-8');

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = (int) ($_GET['per_page'] ?? 24);
if ($per_page < 1) {
    $per_page = 24;
}
if ($per_page > 100) {
    $per_page = 100;
}
$offset = ($page - 1) * $per_page;
$q = trim((string) ($_GET['q'] ?? ''));
$filter_date = trim((string) ($_GET['filter_date'] ?? ''));

$where_clauses = [];
$params = [];

if ($q !== '') {
    $where_clauses[] = "(original_name LIKE ? OR stored_name LIKE ?)";
    $keyword = '%' . $q . '%';
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($filter_date !== '') {
    // Expect YYYY-MM
    $where_clauses[] = "created_at LIKE ?";
    $params[] = $filter_date . '%';
}

$where = '';
if (!empty($where_clauses)) {
    $where = 'WHERE ' . implode(' AND ', $where_clauses);
}

$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM media_library {$where}");
$stmt_count->execute($params);
$total = (int) $stmt_count->fetchColumn();

$stmt = $pdo->prepare("SELECT id, original_name, stored_name, file_path, mime_type, extension, file_size, width, height, uploaded_by, created_at
                       FROM media_library
                       {$where}
                       ORDER BY id DESC
                       LIMIT {$per_page} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id' => (int) $row['id'],
        'original_name' => $row['original_name'],
        'stored_name' => $row['stored_name'],
        'file_path' => $row['file_path'],
        'url' => BASE_URL . $row['file_path'],
        'mime_type' => $row['mime_type'],
        'extension' => $row['extension'],
        'file_size' => (int) $row['file_size'],
        'width' => $row['width'] !== null ? (int) $row['width'] : null,
        'height' => $row['height'] !== null ? (int) $row['height'] : null,
        'uploaded_by' => $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null,
        'created_at' => $row['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'page' => $page,
    'per_page' => $per_page,
    'total' => $total,
    'total_pages' => (int) ceil($total / $per_page),
    'items' => $items
], JSON_UNESCAPED_UNICODE);
exit;

