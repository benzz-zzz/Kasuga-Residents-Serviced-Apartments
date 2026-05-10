<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/announcements.php';
require_login();

if (is_post() && verify_csrf($_POST['csrf'] ?? null)) {
    $intent = (string) ($_POST['intent'] ?? '');
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    if ($intent === 'remove_done' && $bookingId > 0) {
        $rBefore = db()->prepare("SELECT room_id FROM bookings WHERE id = ? AND user_id = ? AND status IN ('checked_out', 'cancelled')");
        $rBefore->execute([$bookingId, (int) current_user()['id']]);
        $roomIdRm = (int) ($rBefore->fetchColumn() ?: 0);
        $del = db()->prepare("DELETE FROM bookings WHERE id = ? AND user_id = ? AND status IN ('checked_out', 'cancelled')");
        $del->execute([$bookingId, (int) current_user()['id']]);
        if ($del->rowCount() > 0) {
            if ($roomIdRm > 0) {
                sync_room_occupancy_from_stays($roomIdRm);
            }
            $_SESSION['flash_success'] = 'Reservation removed from your list.';
        } else {
            $_SESSION['flash_error'] = 'Only checked out or cancelled reservations can be removed.';
        }
    } elseif ($intent === 'cancel_booking' && $bookingId > 0) {
        $own = db()->prepare('SELECT room_id FROM bookings WHERE id = ? AND user_id = ?');
        $own->execute([$bookingId, (int) current_user()['id']]);
        $roomRow = $own->fetch();
        if (!$roomRow) {
            $_SESSION['flash_error'] = 'Reservation not found.';
        } else {
            $roomIdCancel = (int) $roomRow['room_id'];
            $upd = db()->prepare("
                UPDATE bookings SET status = 'cancelled'
                WHERE id = ? AND user_id = ?
                  AND (
                    status = 'pending'
                    OR (status = 'confirmed' AND check_in > CURDATE())
                  )
            ");
            $upd->execute([$bookingId, (int) current_user()['id']]);
            if ($upd->rowCount() > 0) {
                sync_room_occupancy_from_stays($roomIdCancel);
                $_SESSION['flash_success'] = 'Reservation cancelled.';
            } else {
                $_SESSION['flash_error'] = 'This reservation cannot be cancelled online. Contact the property if you need help.';
            }
        }
    } elseif ($intent !== '') {
        $_SESSION['flash_error'] = 'Invalid request.';
    }
    redirect('/Apartment%20system/my_bookings.php');
}

$stmt = db()->prepare("
    SELECT b.*, r.title AS room_title, r.room_code
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
");
$stmt->execute([(int)current_user()['id']]);
$bookings = $stmt->fetchAll();

$announcements = active_announcements_for('tenant');
$today = date('Y-m-d');
$nextStay = null;
foreach ($bookings as $b) {
    if ($b['check_in'] >= $today && in_array($b['status'], ['confirmed', 'pending'], true)) {
        if ($nextStay === null || $b['check_in'] < $nextStay['check_in']) {
            $nextStay = $b;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<header class="page-title">
    <p class="page-title__kicker">Resident portal</p>
    <h1>Your stays &amp; arrivals</h1>
    <p>Complete payment for pending stays to confirm instantly, then leave a star review after your stay. Property notices from the team appear below.</p>
</header>

<?php if ($nextStay): ?>
<section class="panel panel--highlight" aria-label="Next stay">
    <h2 class="h3-like">Next on your calendar</h2>
    <p><strong><?= h($nextStay['room_title']) ?></strong> (<?= h($nextStay['room_code']) ?>)</p>
    <p class="table-muted">Check-in: <?= h(format_booking_date_time((string)$nextStay['check_in'], $nextStay['check_in_time'] ?? null)) ?> · Check-out: <?= h(format_booking_date_time((string)$nextStay['check_out'], $nextStay['check_out_time'] ?? null)) ?><?php if (!empty($nextStay['early_check_out_date'])): ?> · <strong>Early out</strong> <?= h((string)$nextStay['early_check_out_date']) ?><?php endif; ?></p>
    <p class="table-muted">Booked: <?= h(format_booking_datetime($nextStay['created_at'] ?? null)) ?> · Status: <?= h($nextStay['status']) ?> · Total PHP <?= number_format((float)$nextStay['total_amount'], 2) ?></p>
    <?php
    $nextCanCancel = $nextStay['status'] === 'pending'
        || ($nextStay['status'] === 'confirmed' && (string) $nextStay['check_in'] > $today);
    ?>
    <?php if ($nextStay['status'] === 'pending' && empty($nextStay['payment_submitted_at'])): ?>
        <a class="btn btn--primary" href="/Apartment%20system/checkout.php?booking_id=<?= (int)$nextStay['id'] ?>">Finish checkout</a>
    <?php elseif ($nextStay['status'] === 'pending' && !empty($nextStay['payment_submitted_at'])): ?>
        <span class="table-muted">Payment proof submitted.</span>
    <?php endif; ?>
    <?php if ($nextCanCancel): ?>
        <form method="post" class="booking-actions__form booking-actions__form--next-cancel" onsubmit="return confirm('Cancel this reservation? You can book again later if dates are still open.');" aria-label="Cancel reservation">
            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
            <input type="hidden" name="intent" value="cancel_booking">
            <input type="hidden" name="booking_id" value="<?= (int) $nextStay['id'] ?>">
            <button type="submit" class="btn btn--cancel">Cancel reservation</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($announcements)): ?>
<section class="panel" aria-label="Messages from property">
    <div class="section-head">
        <span class="section-kicker">From the property team</span>
        <h2>Updates for guests</h2>
    </div>
    <ul class="announcement-list">
        <?php foreach ($announcements as $a): ?>
            <li>
                <strong><?= h($a['title']) ?></strong>
                <p><?= nl2br(h($a['body'])) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<div class="data-table-wrap">
    <table>
        <thead>
            <tr>
                <th scope="col">Room</th>
                <th scope="col">Stay</th>
                <th scope="col">Booked</th>
                <th scope="col">Paid</th>
                <th scope="col">Status</th>
                <th scope="col">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($bookings as $booking): ?>
            <tr>
                <td><?= h($booking['room_title']) ?> <span class="table-muted">(<?= h($booking['room_code']) ?>)</span></td>
                <td>
                    <strong>In</strong> <?= h(format_booking_date_time((string)$booking['check_in'], $booking['check_in_time'] ?? null)) ?><br>
                    <strong>Out</strong> <?= h(format_booking_date_time((string)$booking['check_out'], $booking['check_out_time'] ?? null)) ?>
                    <br><span class="table-muted"><strong>Guests</strong> <?= (int)($booking['guest_count'] ?? 1) ?></span>
                    <?php if (!empty($booking['early_check_out_date'])): ?>
                        <br><span class="table-muted"><strong>Early checkout</strong> <?= h((string)$booking['early_check_out_date']) ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="table-muted"><?= h(format_booking_datetime($booking['created_at'] ?? null)) ?></span></td>
                <td>
                    <?php if (!empty($booking['payment_submitted_at']) && (float)($booking['paid_amount'] ?? 0) > 0): ?>
                        PHP <?= number_format((float)$booking['paid_amount'], 2) ?><br>
                        <span class="table-muted">Code: <?= h((string)($booking['receipt_reference'] ?? '')) ?></span>
                    <?php else: ?>
                        <span class="table-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="status-pill status-pill--<?= h($booking['status']) ?>"><?= h($booking['status']) ?></span></td>
                <td>
                    <div class="booking-actions">
                    <?php
                    $canCancel = $booking['status'] === 'pending'
                        || ($booking['status'] === 'confirmed' && (string) $booking['check_in'] > $today);
                    ?>
                    <?php if ($booking['status'] === 'pending' && empty($booking['payment_submitted_at'])): ?>
                        <a class="btn btn--primary" href="/Apartment%20system/checkout.php?booking_id=<?= (int)$booking['id'] ?>">Checkout</a>
                    <?php elseif ($booking['status'] === 'pending' && !empty($booking['payment_submitted_at'])): ?>
                        <span class="table-muted">Payment proof submitted</span>
                    <?php elseif ($booking['status'] === 'checked_out'): ?>
                        <a class="btn btn--ghost" href="/Apartment%20system/review.php?booking_id=<?= (int)$booking['id'] ?>">Review</a>
                        <form method="post" class="booking-actions__form" onsubmit="return confirm('Remove this completed reservation from your list?');" aria-label="Remove completed reservation">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="intent" value="remove_done">
                            <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                            <button type="submit" class="btn btn--ghost">Remove</button>
                        </form>
                    <?php elseif ($booking['status'] === 'cancelled'): ?>
                        <form method="post" class="booking-actions__form" onsubmit="return confirm('Remove this cancelled reservation from your list?');" aria-label="Remove cancelled reservation">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="intent" value="remove_done">
                            <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                            <button type="submit" class="btn btn--ghost">Remove</button>
                        </form>
                    <?php elseif ($booking['status'] === 'confirmed'): ?>
                        <a class="btn btn--ghost" href="/Apartment%20system/review.php?booking_id=<?= (int)$booking['id'] ?>">Review</a>
                    <?php else: ?>
                        <span class="table-muted">—</span>
                    <?php endif; ?>
                    <?php if ($canCancel): ?>
                        <form method="post" class="booking-actions__form" onsubmit="return confirm('Cancel this reservation?');" aria-label="Cancel reservation">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="intent" value="cancel_booking">
                            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <button type="submit" class="btn btn--cancel">Cancel</button>
                        </form>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($bookings)): ?>
            <tr><td colspan="6">No reservations found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
