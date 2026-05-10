<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_post() && verify_csrf($_POST['csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'purge' && ($id = (int)($_POST['id'] ?? 0)) > 0) {
        db()->prepare('DELETE FROM booking_removal_archive WHERE id = ?')->execute([$id]);
        $_SESSION['flash_success'] = 'That archived record was permanently deleted.';
    }
    redirect(admin_url('archive.php'));
}

$list = db()->query('
    SELECT a.*, u.full_name AS archived_by_name
    FROM booking_removal_archive a
    LEFT JOIN users u ON u.id = a.archived_by
    ORDER BY a.archived_at DESC, a.id DESC
')->fetchAll();

$adminPageTitle = 'Archive';
$adminNav = 'archive';
require_once __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
    <h2>Archived snapshots</h2>
    <?php if ($list === []): ?>
        <p class="admin-muted">No removed bookings yet. Completed rows you delete from Bookings will appear here.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-archive-table">
                <thead>
                    <tr>
                        <th scope="col">Original ID</th>
                        <th scope="col">Guest</th>
                        <th scope="col">Room</th>
                        <th scope="col">Stay</th>
                        <th scope="col">Status</th>
                        <th scope="col">Totals</th>
                        <th scope="col">Removed</th>
                        <th scope="col">By</th>
                        <th scope="col">Details</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($list as $row): ?>
                    <?php
                    $stayIn = format_booking_date_time((string) $row['check_in'], $row['check_in_time'] ?? null);
                    $stayOut = format_booking_date_time((string) $row['check_out'], $row['check_out_time'] ?? null);
                    $notesLine = trim((string) ($row['notes'] ?? '')) !== '' ? (string) $row['notes'] : '—';
                    $detail = 'Original booking #' . (int) $row['original_booking_id'] . "\n"
                        . 'Guest: ' . (string) $row['guest_full_name'] . ' <' . (string) $row['guest_email'] . ">\n"
                        . 'Room: ' . (string) $row['room_code'] . ' — ' . (string) $row['room_title'] . "\n"
                        . 'Guests: ' . (int) ($row['guest_count'] ?? 1) . "\n"
                        . 'Check-in: ' . $stayIn . "\n"
                        . 'Check-out: ' . $stayOut . "\n"
                        . 'Early out: ' . (!empty($row['early_check_out_date']) ? (string) $row['early_check_out_date'] : '—') . "\n"
                        . 'Status: ' . (string) $row['status'] . "\n"
                        . 'Total: PHP ' . number_format((float) $row['total_amount'], 2) . "\n"
                        . 'Paid: ' . ($row['paid_amount'] !== null && $row['paid_amount'] !== ''
                            ? 'PHP ' . number_format((float) $row['paid_amount'], 2) : '—') . "\n"
                        . 'Receipt ref: ' . (string) ($row['receipt_reference'] ?? '') . "\n"
                        . 'Payment submitted: ' . (!empty($row['payment_submitted_at'])
                            ? format_booking_datetime((string) $row['payment_submitted_at']) : '—') . "\n"
                        . 'Booked (created): ' . format_booking_datetime((string) $row['booking_created_at']) . "\n"
                        . "Notes:\n" . $notesLine;
                    ?>
                    <tr>
                        <td class="admin-bookings-td-id"><?= (int) $row['original_booking_id'] ?></td>
                        <td>
                            <strong><?= h((string) $row['guest_full_name']) ?></strong><br>
                            <span class="admin-muted admin-muted--sm"><?= h((string) $row['guest_email']) ?></span>
                        </td>
                        <td>
                            <span class="admin-bookings-room-code"><?= h((string) $row['room_code']) ?></span><br>
                            <span class="admin-muted admin-muted--sm"><?= h((string) $row['room_title']) ?></span>
                        </td>
                        <td class="admin-muted admin-muted--sm"><?= h($stayIn) ?><br><?= h($stayOut) ?></td>
                        <td><span class="status-pill status-pill--<?= h((string) $row['status']) ?>"><?= h((string) $row['status']) ?></span></td>
                        <td class="admin-muted admin-muted--sm">
                            <?= h(number_format((float) $row['total_amount'], 2)) ?> total<br>
                            <?php if ($row['paid_amount'] !== null && $row['paid_amount'] !== ''): ?>
                                <span class="admin-bookings-amount">PHP <?= h(number_format((float) $row['paid_amount'], 2)) ?></span> paid
                            <?php else: ?>
                                — paid
                            <?php endif; ?>
                        </td>
                        <td class="admin-muted admin-muted--sm"><?= h(format_booking_datetime($row['archived_at'] ?? null)) ?></td>
                        <td class="admin-muted admin-muted--sm"><?= h((string)($row['archived_by_name'] ?? '') ?: '—') ?></td>
                        <td>
                            <details class="admin-archive-details">
                                <summary class="admin-archive-summary">Show / hide</summary>
                                <pre class="admin-archive-body"><?= h($detail) ?></pre>
                            </details>
                        </td>
                        <td>
                            <form method="post" class="admin-archive-delete-form" onsubmit="return confirm('Permanently delete this archived snapshot? This cannot be undone.');">
                                <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                                <input type="hidden" name="action" value="purge">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="admin-btn admin-btn--ghost admin-btn--compact">Delete archive</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
