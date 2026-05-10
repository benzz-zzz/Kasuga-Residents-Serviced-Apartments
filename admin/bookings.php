<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/validation.php';

if (is_post() && verify_csrf($_POST['csrf'] ?? null)) {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $intent = (string)($_POST['intent'] ?? '');

    if ($intent === 'early_out' && $bookingId > 0) {
        $row = db()->prepare('SELECT id, check_in, check_out, status FROM bookings WHERE id = ?');
        $row->execute([$bookingId]);
        $b = $row->fetch();
        if (!$b) {
            $_SESSION['flash_error'] = 'Booking not found.';
        } elseif (in_array((string)($b['status'] ?? ''), ['cancelled', 'checked_out'], true)) {
            $_SESSION['flash_error'] = 'Early checkout cannot be set on a cancelled or checked-out reservation.';
        } elseif (!empty($_POST['early_clear'])) {
            db()->prepare('UPDATE bookings SET early_check_out_date = NULL WHERE id = ?')->execute([$bookingId]);
            $_SESSION['flash_success'] = 'Early checkout cleared for booking #' . $bookingId . '.';
        } else {
            $earlyRaw = trim((string)($_POST['early_check_out_date'] ?? ''));
            if ($earlyRaw === '') {
                $_SESSION['flash_error'] = 'Choose an early checkout date, or use Clear.';
            } else {
                $err = validate_early_check_out_date((string)$b['check_in'], (string)$b['check_out'], $earlyRaw);
                if ($err !== null) {
                    $_SESSION['flash_error'] = $err;
                } else {
                    db()->prepare('UPDATE bookings SET early_check_out_date = ? WHERE id = ?')->execute([$earlyRaw, $bookingId]);
                    $_SESSION['flash_success'] = 'Early checkout set to ' . $earlyRaw . ' for booking #' . $bookingId . '.';
                }
            }
        }
    } elseif ($intent === 'status' && in_array((string)($_POST['status'] ?? ''), ['pending', 'confirmed', 'cancelled', 'checked_out'], true) && $bookingId > 0) {
        $status = (string)$_POST['status'];
        $exists = db()->prepare('SELECT id, room_id, check_in, check_out, early_check_out_date, payment_submitted_at, paid_amount, receipt_reference, notes FROM bookings WHERE id = ?');
        $exists->execute([$bookingId]);
        $b = $exists->fetch();
        $cashierPayment = $b ? (strpos((string) ($b['notes'] ?? ''), '[Payment: at cashier]') !== false) : false;
        if (!$b) {
            $_SESSION['flash_error'] = 'Booking not found.';
        } elseif ($status === 'confirmed' && !$cashierPayment && (empty($b['payment_submitted_at']) || (float)($b['paid_amount'] ?? 0) <= 0 || trim((string)($b['receipt_reference'] ?? '')) === '')) {
            $_SESSION['flash_error'] = 'Cannot confirm yet: customer has not submitted amount paid and payment verification (QR code).';
        } elseif ($status === 'confirmed') {
            $conflict = db()->prepare("
                SELECT COUNT(*) FROM bookings
                WHERE room_id = ?
                  AND id <> ?
                  AND status = 'confirmed'
                  AND NOT (COALESCE(early_check_out_date, check_out) <= ? OR check_in >= ?)
            ");
            $conflict->execute([
                (int)$b['room_id'],
                $bookingId,
                (string)$b['check_in'],
                (string)$b['check_out'],
            ]);
            if ((int)$conflict->fetchColumn() > 0) {
                $_SESSION['flash_error'] = 'Cannot confirm this booking: the room is already confirmed for overlapping dates.';
            } else {
                db()->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$status, $bookingId]);
                $label = $status === 'checked_out' ? 'checked out (guest vacated)' : $status;
                $_SESSION['flash_success'] = 'Reservation set to ' . $label . '.';
            }
        } else {
            db()->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$status, $bookingId]);
            $label = $status === 'checked_out' ? 'checked out (guest vacated)' : $status;
            $_SESSION['flash_success'] = 'Reservation set to ' . $label . '.';
        }
    } elseif ($intent === 'remove' && $bookingId > 0) {
        $rmStmt = db()->prepare('SELECT room_id, status FROM bookings WHERE id = ?');
        $rmStmt->execute([$bookingId]);
        $row = $rmStmt->fetch();
        if (!$row) {
            $_SESSION['flash_error'] = 'Booking not found.';
        } elseif (!in_array((string) ($row['status'] ?? ''), ['checked_out', 'cancelled'], true)) {
            $_SESSION['flash_error'] = 'Only completed reservations (checked out or cancelled) can be removed.';
        } else {
            $roomIdDel = (int) ($row['room_id'] ?? 0);
            $archiverId = current_user()['id'] ?? null;
            $archiverId = $archiverId !== null ? (int) $archiverId : null;
            if (!insert_booking_removal_archive($bookingId, $archiverId)) {
                $_SESSION['flash_error'] = 'Could not archive this booking; it was not removed. Try again or check the database.';
            } else {
                db()->prepare('DELETE FROM bookings WHERE id = ?')->execute([$bookingId]);
                if ($roomIdDel > 0) {
                    sync_room_occupancy_from_stays($roomIdDel);
                }
                $_SESSION['flash_success'] = 'Booking #' . $bookingId . ' was removed from the active list. A copy is saved under Admin → Archive.';
            }
        }
    } else {
        $_SESSION['flash_error'] = 'Invalid request.';
    }
    $syncId = (int) ($_POST['booking_id'] ?? 0);
    if ($syncId > 0) {
        $sr = db()->prepare('SELECT room_id FROM bookings WHERE id = ?');
        $sr->execute([$syncId]);
        $roomSync = (int) ($sr->fetchColumn() ?: 0);
        if ($roomSync > 0) {
            sync_room_occupancy_from_stays($roomSync);
        }
    }
    redirect(admin_url('bookings.php'));
}

$bookings = db()->query("
    SELECT b.id, b.guest_count, b.check_in, b.check_in_time, b.check_out, b.check_out_time, b.early_check_out_date, b.total_amount, b.paid_amount, b.receipt_reference, b.payment_submitted_at, b.status, b.notes, b.created_at, u.full_name, u.email, r.room_code, r.title AS room_title
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    JOIN rooms r ON r.id = b.room_id
    ORDER BY b.created_at DESC
")->fetchAll();

$adminPageTitle = 'Bookings';
$adminNav = 'bookings';
require_once __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
    <h2>All booking requests</h2>
    <p class="admin-muted" style="font-size:0.9rem;margin-top:0"><strong>Rooms</strong> (catalog) can be set to Open / Occupied / Maintenance under <a href="<?= h(admin_url('rooms.php')) ?>">Rooms</a>. <strong>Checked out</strong> marks this guest as vacated so the public site no longer treats them as in-house for the “Reserved” badge. Use <strong>Early checkout</strong> when they leave before the booked check-out date (does not change billing automatically). <strong>Remove</strong> permanently deletes a finished record (status <em>Checked out</em> or <em>Cancelled</em> only).</p>
    <div class="admin-table-wrap admin-table-wrap--bookings">
        <table class="admin-bookings-table">
            <colgroup>
                <col class="admin-bookings-col admin-bookings-col--id" span="1">
                <col class="admin-bookings-col admin-bookings-col--guest" span="1">
                <col class="admin-bookings-col admin-bookings-col--room" span="1">
                <col class="admin-bookings-col admin-bookings-col--stay" span="1">
                <col class="admin-bookings-col admin-bookings-col--booked" span="1">
                <col class="admin-bookings-col admin-bookings-col--paid" span="1">
                <col class="admin-bookings-col admin-bookings-col--status" span="1">
                <col class="admin-bookings-col admin-bookings-col--proof" span="1">
                <col class="admin-bookings-col admin-bookings-col--early" span="1">
                <col class="admin-bookings-col admin-bookings-col--actions" span="1">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Guest</th>
                    <th scope="col">Room</th>
                    <th scope="col">Stay</th>
                    <th scope="col">Booked</th>
                    <th scope="col">Paid</th>
                    <th scope="col">Status</th>
                    <th scope="col">Proof</th>
                    <th scope="col">Early out</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $booking): ?>
                <?php
                $maxEarly = (new DateTimeImmutable((string)$booking['check_out']))->modify('-1 day')->format('Y-m-d');
                $canEarly = $booking['check_in'] <= $maxEarly;
                $hasProof = !empty($booking['payment_submitted_at']);
                ?>
                <tr id="booking-<?= (int) $booking['id'] ?>">
                    <td class="admin-bookings-td-id"><?= (int) $booking['id'] ?></td>
                    <td>
                        <strong><?= h($booking['full_name']) ?></strong><br>
                        <span class="admin-muted admin-muted--sm"><?= h($booking['email']) ?></span>
                    </td>
                    <td>
                        <span class="admin-bookings-room-code"><?= h($booking['room_code']) ?></span><br>
                        <span class="admin-muted admin-muted--sm"><?= h($booking['room_title']) ?></span>
                    </td>
                    <td>
                        <div class="admin-bookings-stay">
                            <div class="admin-bookings-stay__line">
                                <span class="admin-bookings-stay__label">In</span>
                                <span class="admin-bookings-stay__value"><?= h(format_booking_date_time((string) $booking['check_in'], $booking['check_in_time'] ?? null)) ?></span>
                            </div>
                            <div class="admin-bookings-stay__line">
                                <span class="admin-bookings-stay__label">Out</span>
                                <span class="admin-bookings-stay__value"><?= h(format_booking_date_time((string) $booking['check_out'], $booking['check_out_time'] ?? null)) ?></span>
                            </div>
                            <div class="admin-bookings-stay__line admin-bookings-stay__line--guests">
                                <span class="admin-bookings-stay__label">Guests</span>
                                <span class="admin-bookings-stay__value"><?= (int) ($booking['guest_count'] ?? 1) ?></span>
                            </div>
                            <?php if (!empty($booking['early_check_out_date'])): ?>
                            <div class="admin-bookings-stay__line">
                                <span class="admin-bookings-stay__label">Early</span>
                                <span class="admin-bookings-stay__value"><?= h((string) $booking['early_check_out_date']) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><span class="admin-muted admin-muted--sm admin-bookings-date"><?= h(format_booking_datetime($booking['created_at'] ?? null)) ?></span></td>
                    <td class="admin-bookings-td--paid">
                        <?php if ($hasProof && (float) ($booking['paid_amount'] ?? 0) > 0): ?>
                            <span class="admin-bookings-amount">PHP <?= number_format((float) $booking['paid_amount'], 2) ?></span>
                        <?php else: ?>
                            <span class="admin-muted admin-muted--sm">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="admin-bookings-td--status">
                        <form method="post" class="admin-booking-actions admin-booking-actions--status" aria-label="Set reservation status">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="intent" value="status">
                            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <select id="booking-status-<?= (int) $booking['id'] ?>" name="status" class="admin-booking-status-select" aria-label="Reservation status" onchange="this.form.submit()">
                                <option value="pending"<?= $booking['status'] === 'pending' ? ' selected' : '' ?>>Pending</option>
                                <option value="confirmed"<?= $booking['status'] === 'confirmed' ? ' selected' : '' ?>>Confirmed</option>
                                <option value="checked_out"<?= $booking['status'] === 'checked_out' ? ' selected' : '' ?>>Checked out</option>
                                <option value="cancelled"<?= $booking['status'] === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                            </select>
                        </form>
                    </td>
                    <td class="admin-bookings-td--proof">
                        <?php if ($hasProof): ?>
                            <div class="admin-proof-block">
                                <code class="admin-proof-code" title="<?= h((string) $booking['receipt_reference']) ?>"><?= h((string) $booking['receipt_reference']) ?></code>
                                <span class="admin-muted admin-muted--sm admin-proof-sent"><?= h(format_booking_datetime($booking['payment_submitted_at'] ?? null)) ?></span>
                            </div>
                        <?php else: ?>
                            <span class="admin-muted admin-muted--sm">No proof yet</span>
                        <?php endif; ?>
                    </td>
                    <td class="admin-bookings-td--early">
                        <?php if ($canEarly && !in_array((string) $booking['status'], ['cancelled', 'checked_out'], true)): ?>
                            <form method="post" class="admin-early-out-form" aria-label="Early checkout">
                                <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                                <input type="hidden" name="intent" value="early_out">
                                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                <input type="date" name="early_check_out_date" class="admin-early-out-date"
                                    min="<?= h((string) $booking['check_in']) ?>"
                                    max="<?= h($maxEarly) ?>"
                                    value="<?= h((string) ($booking['early_check_out_date'] ?? '')) ?>"
                                    aria-label="Early checkout date">
                                <div class="admin-early-out-actions">
                                    <button type="submit" class="admin-btn admin-btn--secondary admin-btn--compact">Save early out</button>
                                    <?php if (!empty($booking['early_check_out_date'])): ?>
                                        <button type="submit" name="early_clear" value="1" class="admin-btn admin-btn--ghost admin-btn--compact">Clear</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php elseif (in_array((string) $booking['status'], ['cancelled', 'checked_out'], true)): ?>
                            <span class="admin-muted admin-muted--sm">—</span>
                        <?php else: ?>
                            <span class="admin-muted admin-muted--sm">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td class="admin-bookings-td--actions">
                        <div class="admin-bookings-actions-stack">
                        <?php if (!in_array((string) $booking['status'], ['cancelled', 'checked_out'], true)): ?>
                        <form method="post" class="admin-booking-actions admin-booking-actions--row" onsubmit="return confirm('Set this reservation to Cancelled?');" aria-label="Cancel reservation">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="intent" value="status">
                            <input type="hidden" name="status" value="cancelled">
                            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <button type="submit" class="admin-btn admin-btn--danger admin-btn--compact">Cancel booking</button>
                        </form>
                        <?php endif; ?>
                        <?php if (in_array((string) $booking['status'], ['checked_out', 'cancelled'], true)): ?>
                        <form method="post" class="admin-booking-actions admin-booking-actions--row" onsubmit="return confirm('Delete this booking record permanently? This cannot be undone.');" aria-label="Remove completed booking">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="intent" value="remove">
                            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <button type="submit" class="admin-btn admin-btn--ghost admin-btn--compact">Remove record</button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($bookings)): ?>
                <tr><td colspan="10">No bookings found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
