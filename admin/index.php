<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$stats = [
    'tenants' => (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'tenant'")->fetchColumn(),
    'rooms' => (int)db()->query('SELECT COUNT(*) FROM rooms WHERE is_active = 1')->fetchColumn(),
    'bookings' => (int)db()->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
    'reviews' => (int)db()->query('SELECT COUNT(*) FROM reviews')->fetchColumn(),
    'announcements' => (int)db()->query('SELECT COUNT(*) FROM announcements WHERE is_active = 1')->fetchColumn(),
];
$recent = db()->query("
    SELECT b.id, b.check_in, b.check_in_time, b.check_out, b.check_out_time, b.paid_amount, b.payment_submitted_at, b.status, u.full_name, r.room_code, b.created_at
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    JOIN rooms r ON r.id = b.room_id
    ORDER BY b.created_at DESC
    LIMIT 8
")->fetchAll();

$arrivals = db()->query("
    SELECT b.id, b.check_in, b.check_in_time, b.check_out, b.check_out_time, b.early_check_out_date, b.status, u.full_name, u.email, r.room_code
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    JOIN rooms r ON r.id = b.room_id
    WHERE b.check_in >= CURDATE()
      AND b.check_in <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND b.status IN ('confirmed', 'pending')
    ORDER BY b.check_in ASC, b.id ASC
    LIMIT 20
")->fetchAll();

$roomRentingRows = db()->query("
    SELECT
        r.id AS room_id,
        r.room_code,
        COUNT(b.id) AS renter_count,
        GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR '||') AS renter_names
    FROM rooms r
    LEFT JOIN bookings b
        ON b.room_id = r.id
       AND b.status = 'confirmed'
       AND CURDATE() >= b.check_in
       AND CURDATE() < COALESCE(b.early_check_out_date, b.check_out)
    LEFT JOIN users u ON u.id = b.user_id
    WHERE r.is_active = 1
    GROUP BY r.id, r.room_code
    ORDER BY r.room_code ASC
")->fetchAll();

$roomChartCategories = [];
$roomChartCounts = [];
$roomChartRenterNames = [];
foreach ($roomRentingRows as $row) {
    $roomChartCategories[] = (string)($row['room_code'] ?? '');
    $roomChartCounts[] = (int)($row['renter_count'] ?? 0);
    $namesRaw = trim((string)($row['renter_names'] ?? ''));
    $roomChartRenterNames[] = $namesRaw === '' ? [] : explode('||', $namesRaw);
}

$monthlyRentRows = db()->query("
    SELECT
        DATE_FORMAT(b.check_in, '%Y-%m') AS month_key,
        DATE_FORMAT(b.check_in, '%b %Y') AS month_label,
        COUNT(DISTINCT b.user_id) AS renter_count
    FROM bookings b
    WHERE b.status IN ('confirmed', 'checked_out')
    GROUP BY DATE_FORMAT(b.check_in, '%Y-%m'), DATE_FORMAT(b.check_in, '%b %Y')
    ORDER BY month_key ASC
")->fetchAll();

$monthlyRentLabels = [];
$monthlyRentCounts = [];
foreach ($monthlyRentRows as $row) {
    $monthlyRentLabels[] = (string)($row['month_label'] ?? '');
    $monthlyRentCounts[] = (int)($row['renter_count'] ?? 0);
}

$adminPageTitle = 'Dashboard';
$adminNav = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>
<div class="admin-stat-row" aria-label="Summary">
    <div class="admin-stat">
        <p class="admin-stat__label">Tenants</p>
        <p class="admin-stat__value"><?= (int)$stats['tenants'] ?></p>
    </div>
    <div class="admin-stat">
        <p class="admin-stat__label">Active rooms</p>
        <p class="admin-stat__value"><?= (int)$stats['rooms'] ?></p>
    </div>
    <div class="admin-stat">
        <p class="admin-stat__label">Bookings</p>
        <p class="admin-stat__value"><?= (int)$stats['bookings'] ?></p>
    </div>
    <div class="admin-stat">
        <p class="admin-stat__label">Reviews</p>
        <p class="admin-stat__value"><?= (int)$stats['reviews'] ?></p>
    </div>
    <div class="admin-stat">
        <p class="admin-stat__label">Live notices</p>
        <p class="admin-stat__value"><?= (int)$stats['announcements'] ?></p>
    </div>
</div>

<p style="margin:0 0 1rem;display:flex;flex-wrap:wrap;gap:0.5rem">
    <a class="admin-btn" href="<?= h(admin_url('announcements.php')) ?>">Manage announcements</a>
    <a class="admin-btn admin-btn--ghost" href="<?= h(admin_url('export_bookings.php')) ?>">Export bookings CSV</a>
</p>

<div class="admin-card">
    <h2>Arrivals in the next 7 days</h2>
    <p class="admin-muted" style="font-size:0.9rem;margin-top:0">Front-desk view: who is landing soon, with contact on file.</p>
    <div class="admin-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Check-in</th>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($arrivals as $row): ?>
                <tr>
                    <td>
                        <?= h(format_booking_date_time((string)$row['check_in'], $row['check_in_time'] ?? null)) ?><br>
                        <span class="admin-muted admin-muted--sm">→ <?= h(format_booking_date_time((string)$row['check_out'], $row['check_out_time'] ?? null)) ?></span>
                        <?php if (!empty($row['early_check_out_date'])): ?>
                            <br><span class="admin-muted admin-muted--sm"><strong>Early</strong> <?= h((string)$row['early_check_out_date']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($row['full_name']) ?><br><span class="admin-muted admin-muted--sm"><?= h($row['email']) ?></span></td>
                    <td><?= h($row['room_code']) ?></td>
                    <td><span class="status-pill status-pill--<?= h($row['status']) ?>"><?= h($row['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($arrivals)): ?>
                <tr><td colspan="4">No scheduled arrivals in this window.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-card">
    <h2>Current renters by room</h2>
    <p class="admin-muted" style="font-size:0.9rem;margin-top:0">Admin-only snapshot of how many tenants are currently renting each active room.</p>
    <div id="admin-room-rent-chart" class="admin-chart" role="img" aria-label="Current renters per room"></div>
</div>

<div class="admin-card">
    <h2>Monthly renters</h2>
    <p class="admin-muted" style="font-size:0.9rem;margin-top:0">Tracks how many unique users rented each month.</p>
    <div id="admin-monthly-rent-chart" class="admin-chart" role="img" aria-label="Monthly renter count"></div>
</div>

<div class="admin-card">
    <h2>Recent bookings</h2>
    <div class="admin-table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Guest</th>
                    <th scope="col">Room</th>
                    <th scope="col">Stay</th>
                    <th scope="col">Booked</th>
                    <th scope="col">Amount paid</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $b): ?>
                <tr>
                    <td><?= (int)$b['id'] ?></td>
                    <td><?= h($b['full_name']) ?></td>
                    <td><?= h($b['room_code']) ?></td>
                    <td>
                        <?= h(format_booking_date_time((string)$b['check_in'], $b['check_in_time'] ?? null)) ?><br>
                        <span class="admin-muted admin-muted--sm"><?= h(format_booking_date_time((string)$b['check_out'], $b['check_out_time'] ?? null)) ?></span>
                    </td>
                    <td><span class="admin-muted admin-muted--sm"><?= h(format_booking_datetime($b['created_at'] ?? null)) ?></span></td>
                    <td>
                        <?php if (!empty($b['payment_submitted_at']) && (float)($b['paid_amount'] ?? 0) > 0): ?>
                            PHP <?= number_format((float)$b['paid_amount'], 2) ?>
                        <?php else: ?>
                            <span class="admin-muted admin-muted--sm">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="status-pill status-pill--<?= h($b['status']) ?>"><?= h($b['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recent)): ?>
                <tr><td colspan="7">No bookings yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p style="margin:1rem 0 0"><a class="admin-btn" href="<?= h(admin_url('bookings.php')) ?>">View all bookings</a></p>
</div>
<script src="https://code.highcharts.com/highcharts.js"></script>
<script>
(function () {
    var chartNode = document.getElementById('admin-room-rent-chart');
    var monthlyNode = document.getElementById('admin-monthly-rent-chart');
    if ((!chartNode && !monthlyNode) || typeof Highcharts === 'undefined') return;
    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    var categories = <?= json_encode($roomChartCategories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var counts = <?= json_encode($roomChartCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var renterNames = <?= json_encode($roomChartRenterNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var monthlyLabels = <?= json_encode($monthlyRentLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var monthlyCounts = <?= json_encode($monthlyRentCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    if (chartNode) {
        Highcharts.chart('admin-room-rent-chart', {
            chart: {
                type: 'column',
                backgroundColor: 'transparent'
            },
            title: { text: null },
            xAxis: {
                categories: categories,
                title: { text: 'Room' }
            },
            yAxis: {
                min: 0,
                allowDecimals: false,
                title: { text: 'Current renters' }
            },
            tooltip: {
                useHTML: true,
                formatter: function () {
                    var index = this.point.index;
                    var names = Array.isArray(renterNames[index]) ? renterNames[index] : [];
                    var list = names.length > 0 ? names.map(function (name) {
                        return '- ' + escapeHtml(name);
                    }).join('<br>') : 'No current renter';
                    return '<strong>' + escapeHtml(this.x) + '</strong><br>' +
                        'Renters: <strong>' + this.y + '</strong><br>' + list;
                }
            },
            series: [{
                name: 'Renters',
                data: counts,
                color: '#00d4b3'
            }],
            credits: { enabled: false },
            legend: { enabled: false }
        });
    }

    if (monthlyNode) {
        Highcharts.chart('admin-monthly-rent-chart', {
            chart: {
                type: 'line',
                backgroundColor: 'transparent'
            },
            title: { text: null },
            xAxis: {
                categories: monthlyLabels,
                title: { text: 'Month' }
            },
            yAxis: {
                min: 0,
                allowDecimals: false,
                title: { text: 'Unique renters' }
            },
            tooltip: {
                shared: true,
                valueSuffix: ' renter(s)'
            },
            series: [{
                name: 'Monthly renters',
                data: monthlyCounts,
                color: '#56b4ff'
            }],
            credits: { enabled: false },
            legend: { enabled: false }
        });
    }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
