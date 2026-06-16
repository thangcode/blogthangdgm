<?php
// admin/short-links/stats.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect('index.php');
}

// Fetch link
$stmt = $pdo->prepare("SELECT * FROM short_links WHERE id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();

if (!$link) {
    redirect('index.php');
}

$current_page = 'short-links';
require_once '../includes/header.php';

// Pagination for stats
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Date Filtering
$filter = $_GET['filter'] ?? '30days';
$start_date = '';
$end_date = date('Y-m-d');

if ($filter === 'today') {
    $start_date = date('Y-m-d');
} elseif ($filter === 'yesterday') {
    $start_date = date('Y-m-d', strtotime('-1 day'));
    $end_date = $start_date;
} elseif ($filter === '7days') {
    $start_date = date('Y-m-d', strtotime('-6 days'));
} elseif ($filter === '30days') {
    $start_date = date('Y-m-d', strtotime('-29 days'));
} elseif ($filter === 'custom') {
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-29 days'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
} else {
    $start_date = date('Y-m-d', strtotime('-29 days'));
    $filter = '30days';
}

$start_date_query = $start_date . ' 00:00:00';
$end_date_query = $end_date . ' 23:59:59';

$whereClause = "short_link_id = ? AND clicked_at BETWEEN ? AND ?";
$params = [$id, $start_date_query, $end_date_query];

// Fetch total stats within range
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM short_link_clicks WHERE $whereClause");
$countStmt->execute($params);
$totalClicks = $countStmt->fetchColumn();
$totalPages = ceil($totalClicks / $limit);

// Fetch stats details
$statsStmt = $pdo->prepare("SELECT * FROM short_link_clicks WHERE $whereClause ORDER BY clicked_at DESC LIMIT $limit OFFSET $offset");
$statsStmt->execute($params);
$clicks = $statsStmt->fetchAll();

// Fetch chart data
$chartQuery = "SELECT DATE(clicked_at) as click_date, COUNT(*) as total_clicks, COUNT(DISTINCT ip_address) as unique_clicks 
               FROM short_link_clicks 
               WHERE $whereClause 
               GROUP BY DATE(clicked_at) 
               ORDER BY click_date ASC";
$chartStmt = $pdo->prepare($chartQuery);
$chartStmt->execute($params);
$chartDataRaw = $chartStmt->fetchAll();

// Fill missing dates
$chartData = [];
try {
    $currentDate = new DateTime($start_date);
    $endDateObj = new DateTime($end_date);
    while ($currentDate <= $endDateObj) {
        $dateStr = $currentDate->format('Y-m-d');
        $chartData[$dateStr] = ['total' => 0, 'unique' => 0];
        $currentDate->modify('+1 day');
    }
} catch (Exception $e) {}

foreach ($chartDataRaw as $row) {
    if (isset($chartData[$row['click_date']])) {
        $chartData[$row['click_date']]['total'] = (int)$row['total_clicks'];
        $chartData[$row['click_date']]['unique'] = (int)$row['unique_clicks'];
    }
}

// Convert formats for chart.js
$labels = [];
foreach (array_keys($chartData) as $dateStr) {
    $labels[] = date('d/m/Y', strtotime($dateStr));
}
$labelsJson = json_encode($labels);
$totalClicksJson = json_encode(array_values(array_column($chartData, 'total')));
$uniqueClicksJson = json_encode(array_values(array_column($chartData, 'unique')));

?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="container-fluid mb-5">
    <!-- Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 mb-1">Thống Kê Link Rút Gọn</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Rút gọn Link</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo e($link['title']); ?></li>
                </ol>
            </nav>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="edit.php?id=<?php echo $link['id']; ?>" class="btn btn-primary me-2">
                <i class="bi bi-pencil"></i> Chỉnh sửa Link
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Trở về
            </a>
        </div>
    </div>

    <!-- Quick Info -->
    <div class="card shadow-sm border-0 mb-4 bg-light">
        <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="text-muted small fw-bold me-2"><i class="bi bi-link"></i> Link Name:</span>
                <span class="fw-bold"><?php echo e($link['title']); ?></span>
            </div>
            
            <div class="d-flex align-items-center">
                <span class="text-muted small fw-bold me-2"><i class="bi bi-globe"></i> Shortened URL:</span>
                <?php $short_url = BASE_URL . $link['slug']; ?>
                <a href="<?php echo $short_url; ?>" target="_blank" class="fw-semibold text-decoration-none me-2"><?php echo e($short_url); ?></a>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1 border-0" onclick="navigator.clipboard.writeText('<?php echo $short_url; ?>'); AdminPopup.success('Đã copy link!');" title="Copy">
                    <i class="bi bi-clipboard"></i>
                </button>
            </div>
            
            <div class="text-truncate" style="max-width: 350px;">
                <span class="text-muted small fw-bold me-2"><i class="bi bi-box-arrow-up-right"></i> Target URL:</span>
                <a href="<?php echo e($link['target_url']); ?>" target="_blank" class="text-muted text-decoration-none" title="<?php echo e($link['target_url']); ?>">
                    <?php echo e($link['target_url']); ?>
                </a>
            </div>
            
            <div>
                <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill"><i class="bi bi-mouse-fill me-1"></i> <?php echo number_format($totalClicks); ?> Clicks</span>
            </div>
        </div>
    </div>

    <!-- Filter and Chart -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="fw-bold m-0"><i class="bi bi-bar-chart-fill text-primary"></i> Biểu đồ Click</h5>
            
            <!-- Filters -->
            <form method="GET" action="" class="d-flex align-items-center gap-2" id="filterForm">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                
                <select name="filter" class="form-select form-select-sm" style="width: 150px;" onchange="toggleCustomDate(this.value)">
                    <option value="today" <?php echo $filter === 'today' ? 'selected' : ''; ?>>Hôm nay</option>
                    <option value="yesterday" <?php echo $filter === 'yesterday' ? 'selected' : ''; ?>>Hôm qua</option>
                    <option value="7days" <?php echo $filter === '7days' ? 'selected' : ''; ?>>7 ngày qua</option>
                    <option value="30days" <?php echo $filter === '30days' ? 'selected' : ''; ?>>30 ngày qua</option>
                    <option value="custom" <?php echo $filter === 'custom' ? 'selected' : ''; ?>>Tùy chỉnh</option>
                </select>

                <div id="customDateRange" class="d-flex gap-2" style="<?php echo $filter === 'custom' ? '' : 'display: none !important;'; ?>">
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo e($start_date); ?>">
                    <span class="text-muted">-</span>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo e($end_date); ?>">
                </div>

                <button type="submit" class="btn btn-sm btn-primary">Lọc</button>
            </form>
        </div>
        <div class="card-body p-4">
            <div style="height: 350px;">
                <canvas id="clicksChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Detail Table -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold m-0">Lịch sử Click Chi tiết</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0 p-3" style="min-width: 900px;">
                <thead class="bg-light">
                    <tr>
                        <th scope="col" class="border-0 px-4 py-3">Browser</th>
                        <th scope="col" class="border-0 px-4 py-3">IP Address</th>
                        <th scope="col" class="border-0 px-4 py-3">Timestamp</th>
                        <th scope="col" class="border-0 px-4 py-3">Referrer</th>
                        <th scope="col" class="border-0 px-4 py-3">OS</th>
                        <th scope="col" class="border-0 px-4 py-3 text-center">Device</th>
                    </tr>
                </thead>
                <tbody class="border-top-0">
                    <?php if (empty($clicks)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-mouse fw-bold fs-1 d-block mb-3 text-light"></i>
                                Chưa có dữ liệu click nào được ghi nhận trong thời gian này.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clicks as $click): ?>
                            <tr>
                                <td class="px-4 fw-semibold text-secondary">
                                    <?php
                                        $browserIcon = 'bi-globe';
                                        $bLow = strtolower($click['browser']);
                                        if (strpos($bLow, 'chrome') !== false) $browserIcon = 'bi-google text-danger';
                                        elseif (strpos($bLow, 'safari') !== false) $browserIcon = 'bi-compass text-primary';
                                        elseif (strpos($bLow, 'firefox') !== false) $browserIcon = 'bi-browser-firefox text-warning';
                                        elseif (strpos($bLow, 'edge') !== false) $browserIcon = 'bi-browser-edge text-info';
                                        elseif (strpos($bLow, 'opera') !== false) $browserIcon = 'bi-circle text-danger';
                                    ?>
                                    <i class="bi <?php echo $browserIcon; ?> me-2 fs-5 align-middle"></i>
                                    <?php echo e($click['browser']); ?>
                                </td>
                                <td class="px-4"><code><?php echo e($click['ip_address'] ?? 'Unknown'); ?></code></td>
                                <td class="px-4 text-muted"><?php echo e($click['clicked_at']); ?></td>
                                <td class="px-4">
                                    <?php if ($click['referrer']): ?>
                                        <div style="max-width:350px; word-break: break-all;" class="bg-light p-2 rounded text-dark small font-monospace">
                                            <?php echo e($click['referrer']); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">Direct / None</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 text-secondary"><?php echo e($click['os']); ?></td>
                                <td class="px-4 text-center">
                                    <?php
                                        $devLow = strtolower($click['device']);
                                        $devIcon = 'bi-display text-primary';
                                        if ($devLow == 'mobile') $devIcon = 'bi-phone text-secondary';
                                        elseif ($devLow == 'tablet') $devIcon = 'bi-tablet text-info';
                                    ?>
                                    <i class="bi <?php echo $devIcon; ?> fs-5" title="<?php echo e($click['device']); ?>"></i>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Phân trang -->
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white border-top-0 pt-0 pb-4">
                <ul class="pagination justify-content-center m-0">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                            <?php 
                                $queryParams = array_merge($_GET, ['page' => $i]);
                                $queryString = http_build_query($queryParams);
                            ?>
                            <a class="page-link" href="?<?php echo $queryString; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleCustomDate(val) {
    if (val === 'custom') {
        document.getElementById('customDateRange').style.setProperty('display', 'flex', 'important');
    } else {
        document.getElementById('customDateRange').style.setProperty('display', 'none', 'important');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('clicksChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    // Gradient for Total Clicks
    const gradientTotal = ctx.createLinearGradient(0, 0, 0, 350);
    gradientTotal.addColorStop(0, 'rgba(253, 126, 20, 0.4)'); // Orange
    gradientTotal.addColorStop(1, 'rgba(253, 126, 20, 0.05)');
    
    // Gradient for Unique Clicks
    const gradientUnique = ctx.createLinearGradient(0, 0, 0, 350);
    gradientUnique.addColorStop(0, 'rgba(111, 66, 193, 0.4)'); // Purple
    gradientUnique.addColorStop(1, 'rgba(111, 66, 193, 0.05)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo $labelsJson; ?>,
            datasets: [
                {
                    label: 'Total Clicks',
                    data: <?php echo $totalClicksJson; ?>,
                    borderColor: '#fd7e14', // Orange
                    backgroundColor: gradientTotal,
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#fd7e14',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Unique Clicks',
                    data: <?php echo $uniqueClicksJson; ?>,
                    borderColor: '#6f42c1', // Purple
                    backgroundColor: gradientUnique,
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#6f42c1',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    titleColor: '#1e293b',
                    bodyColor: '#475569',
                    borderColor: '#e2e8f0',
                    borderWidth: 1,
                    padding: 12,
                    boxPadding: 6,
                    usePointStyle: true
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        maxTicksLimit: 15
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#f1f5f9',
                        drawBorder: false
                    },
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
