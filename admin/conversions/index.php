<?php
// admin/conversions/index.php — Thống kê chuyển đổi (đọc từ conversion_logs).
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();
ensure_conversion_logs_table($pdo);

$current_page = 'conversions';

// Khoảng thời gian
$days = (int) ($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 30, 90, 365], true)) {
    $days = 7;
}
$since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

// Nhãn loại chuyển đổi
$typeLabels = [
    'contact'          => 'Liên hệ (form)',
    'consultation'     => 'Tư vấn dịch vụ',
    'registration'     => 'Đăng ký',
    'order'            => 'Đơn hàng',
    'contact_hotline'  => 'Gọi hotline',
    'click_hotline'    => 'Gọi hotline',
    'phone'            => 'Gọi hotline',
    'click_zalo'       => 'Chat Zalo',
    'zalo'             => 'Chat Zalo',
    'click_messenger'  => 'Messenger',
    'messenger'        => 'Messenger',
];
$typeLabel = function ($t) use ($typeLabels) {
    $t = (string) $t;
    return $typeLabels[$t] ?? ($t !== '' ? $t : '(không rõ)');
};

$total = 0;
$byType = [];
$bySource = [];
$recent = [];
$daily = [];
$loadError = '';

try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM conversion_logs WHERE created_at >= ?");
    $st->execute([$since]);
    $total = (int) $st->fetchColumn();

    $st = $pdo->prepare("SELECT type, COUNT(*) AS total FROM conversion_logs WHERE created_at >= ? GROUP BY type ORDER BY total DESC");
    $st->execute([$since]);
    $byType = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $st = $pdo->prepare("SELECT COALESCE(NULLIF(source_type,''),'(trực tiếp)') AS src, COALESCE(NULLIF(utm_source,''),'') AS utm, COUNT(*) AS total
                         FROM conversion_logs WHERE created_at >= ? GROUP BY src, utm ORDER BY total DESC LIMIT 15");
    $st->execute([$since]);
    $bySource = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $st = $pdo->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS total FROM conversion_logs WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d ASC");
    $st->execute([$since]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $daily[$r['d']] = (int) $r['total'];
    }

    $st = $pdo->prepare("SELECT type, page_url, referrer, source_type, source_name, utm_source, utm_medium, utm_campaign, ip_address, device_type, created_at
                         FROM conversion_logs WHERE created_at >= ? ORDER BY created_at DESC LIMIT 40");
    $st->execute([$since]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

// Tên sự kiện GTM cho các nút liên hệ (để gợi ý cách cấu hình trigger trong GTM)
$contactEvents = [
    ['label' => 'Gọi hotline', 'event' => get_setting('gtm_event_hotline', 'click_hotline'), 'log_type' => 'contact_hotline', 'icon' => 'bi-telephone-fill', 'color' => '#16a34a'],
    ['label' => 'Chat Zalo', 'event' => get_setting('gtm_event_zalo', 'click_zalo'), 'log_type' => 'contact_zalo', 'icon' => 'bi-chat-dots-fill', 'color' => '#2563eb'],
    ['label' => 'Messenger', 'event' => get_setting('gtm_event_messenger', 'click_messenger'), 'log_type' => 'contact_messenger', 'icon' => 'bi-messenger', 'color' => '#7c3aed'],
];

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-graph-up-arrow me-2"></i>Thống kê chuyển đổi</h1>
        <div class="btn-group">
            <?php foreach ([1 => 'Hôm nay', 7 => '7 ngày', 30 => '30 ngày', 90 => '90 ngày'] as $d => $lbl): ?>
                <a href="?days=<?php echo $d; ?>" class="btn btn-sm <?php echo $days === $d ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo $lbl; ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($loadError !== ''): ?>
        <div class="alert alert-warning">Không tải được dữ liệu chuyển đổi: <?php echo e($loadError); ?></div>
    <?php endif; ?>

    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-info-circle fs-5 mt-1"></i>
        <div class="small">
            Đo lường kết hợp: form gửi đi → <code>log_conversion()</code> (server) và click nút liên hệ → <code>api/log-contact.php</code>; đồng thời đẩy sự kiện vào Google Tag Manager.
            Quản lý mã GTM/GA/Pixel tại <a href="<?php echo BASE_URL; ?>admin/settings/index.php?tab=tracking">Cấu hình → Mã chèn / Tracking</a>.
        </div>
    </div>

    <div class="card shadow-sm mb-4 border-primary-subtle">
        <div class="card-header bg-white fw-bold d-flex align-items-center gap-2">
            <i class="bi bi-cursor-fill text-primary"></i> Tên sự kiện nút liên hệ (cấu hình trigger trong GTM)
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">Khi khách bấm nút liên hệ nổi và xác nhận, hệ thống đẩy sự kiện sau vào <code>dataLayer</code> của Google Tag Manager. Hãy tạo <b>Trigger → Custom Event</b> trong GTM với đúng <b>tên sự kiện</b> bên dưới để kích hoạt tag (GA4/Ads conversion).</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                    <thead class="table-light"><tr><th>Nút</th><th>Tên sự kiện GTM (event)</th><th>Loại lưu trong DB</th></tr></thead>
                    <tbody>
                        <?php foreach ($contactEvents as $ce): ?>
                            <tr>
                                <td><i class="bi <?php echo e($ce['icon']); ?> me-1" style="color:<?php echo e($ce['color']); ?>"></i><?php echo e($ce['label']); ?></td>
                                <td><code class="user-select-all"><?php echo e($ce['event']); ?></code></td>
                                <td><span class="badge bg-light text-dark border"><?php echo e($ce['log_type']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-text">Đổi tên các sự kiện này tại <a href="<?php echo BASE_URL; ?>admin/settings/index.php?tab=tracking">Cấu hình → Mã chèn / Tracking</a> (mục "Tên sự kiện nút liên hệ"). Ngoài ra mọi click vẫn được lưu vào bảng chuyển đổi theo "Loại lưu trong DB" dù bạn chưa cấu hình GTM.</div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small">Tổng chuyển đổi (<?php echo $days; ?> ngày)</div>
                <div class="h3 mb-0 fw-bold text-primary"><?php echo number_format($total); ?></div>
            </div></div>
        </div>
        <?php
        $highlight = ['contact' => 'Liên hệ form', 'consultation' => 'Tư vấn', 'phone' => 'Gọi', 'zalo' => 'Zalo'];
        $byTypeMap = [];
        foreach ($byType as $r) { $byTypeMap[$r['type']] = (int) $r['total']; }
        // gộp các biến thể click
        $callTotal = ($byTypeMap['phone'] ?? 0) + ($byTypeMap['contact_hotline'] ?? 0) + ($byTypeMap['click_hotline'] ?? 0);
        $zaloTotal = ($byTypeMap['zalo'] ?? 0) + ($byTypeMap['click_zalo'] ?? 0);
        $cards = [
            'Liên hệ form' => ($byTypeMap['contact'] ?? 0),
            'Gọi hotline'  => $callTotal,
            'Chat Zalo'    => $zaloTotal,
        ];
        foreach ($cards as $lbl => $val): ?>
            <div class="col-md-3 col-6">
                <div class="card shadow-sm h-100"><div class="card-body">
                    <div class="text-muted small"><?php echo e($lbl); ?></div>
                    <div class="h3 mb-0 fw-bold"><?php echo number_format($val); ?></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-bold">Theo loại chuyển đổi</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Loại</th><th class="text-end">Số lượt</th></tr></thead>
                        <tbody>
                            <?php if (empty($byType)): ?>
                                <tr><td colspan="2" class="text-center text-muted py-4">Chưa có dữ liệu trong kỳ này.</td></tr>
                            <?php else: foreach ($byType as $r): ?>
                                <tr><td><?php echo e($typeLabel($r['type'])); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $r['total']); ?></td></tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header bg-white fw-bold">Theo nguồn</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Nguồn</th><th>UTM source</th><th class="text-end">Số lượt</th></tr></thead>
                        <tbody>
                            <?php if (empty($bySource)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">Chưa có dữ liệu.</td></tr>
                            <?php else: foreach ($bySource as $r): ?>
                                <tr><td><?php echo e($r['src']); ?></td><td><?php echo e($r['utm'] !== '' ? $r['utm'] : '—'); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $r['total']); ?></td></tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-bold">Chuyển đổi gần đây</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0 align-middle">
                            <thead class="table-light"><tr><th>Thời gian</th><th>Loại</th><th>Trang</th><th>Nguồn</th><th>IP</th></tr></thead>
                            <tbody>
                                <?php if (empty($recent)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">Chưa có chuyển đổi nào trong kỳ này.</td></tr>
                                <?php else: foreach ($recent as $r): ?>
                                    <tr>
                                        <td class="text-muted small" style="white-space:nowrap;"><?php echo date('d/m H:i', strtotime($r['created_at'])); ?></td>
                                        <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?php echo e($typeLabel($r['type'])); ?></span></td>
                                        <td class="small" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($r['page_url']); ?>"><?php echo e($r['page_url'] !== '' ? $r['page_url'] : '—'); ?></td>
                                        <td class="small"><?php echo e($r['source_type'] !== '' ? $r['source_type'] : ($r['utm_source'] !== '' ? $r['utm_source'] : '—')); ?></td>
                                        <td class="small text-muted"><?php echo e($r['ip_address']); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
