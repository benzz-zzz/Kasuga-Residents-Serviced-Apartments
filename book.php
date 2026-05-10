<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';
require_login();

$roomId = (int) ($_GET['room_id'] ?? 0);
$gallerySql = sql_room_gallery_concat_column();
$stmt = db()->prepare("SELECT r.*, {$gallerySql} FROM rooms r WHERE r.id = ? AND r.is_active = 1");
$stmt->execute([$roomId]);
$room = $stmt->fetch();

if (!$room) {
    $_SESSION['flash_error'] = 'Room not found.';
    redirect('/Apartment%20system/rooms.php');
}

if (!room_is_open_for_booking($room)) {
    $_SESSION['flash_error'] = 'This apartment is reserved or not open for reservation right now.';
    redirect('/Apartment%20system/rooms.php');
}

$errors = [];
if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } else {
        $checkInAt = trim((string)($_POST['check_in_at'] ?? ''));
        $checkOutAt = trim((string)($_POST['check_out_at'] ?? ''));
        if ($checkInAt !== '') {
            $dtIn = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $checkInAt);
            if ($dtIn) {
                $_POST['check_in'] = $dtIn->format('Y-m-d');
                $_POST['check_in_time'] = $dtIn->format('H:i');
            }
        }
        if ($checkOutAt !== '') {
            $dtOut = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $checkOutAt);
            if ($dtOut) {
                $_POST['check_out'] = $dtOut->format('Y-m-d');
                $_POST['check_out_time'] = $dtOut->format('H:i');
            }
        }

        $errors = validate_booking($_POST, (int)($room['capacity'] ?? 0));
        $checkIn = $_POST['check_in'] ?? '';
        $checkOut = $_POST['check_out'] ?? '';
        $guestCount = (int)($_POST['guest_count'] ?? 0);
        $paymentChoice = (string)($_POST['payment_choice'] ?? 'pay_now');
        if (!in_array($paymentChoice, ['pay_now', 'pay_later', 'pay_cashier'], true)) {
            $errors[] = 'Please choose a payment option.';
        }

        if (!$errors) {
            $dupStmt = db()->prepare("
                SELECT id, payment_submitted_at FROM bookings
                WHERE user_id = ? AND room_id = ? AND check_in = ? AND check_out = ?
                  AND status = 'pending'
                LIMIT 1
            ");
            $dupStmt->execute([(int) current_user()['id'], $roomId, $checkIn, $checkOut]);
            $dupRow = $dupStmt->fetch();
            if ($dupRow) {
                $existingId = (int) $dupRow['id'];
                if (!empty($dupRow['payment_submitted_at'])) {
                    $_SESSION['flash_success'] = 'You already submitted payment for this reservation. Track it under My reservations.';
                    redirect('/Apartment%20system/my_bookings.php');
                }
                if ($paymentChoice === 'pay_later' || $paymentChoice === 'pay_cashier') {
                    unset($_SESSION['pending_checkout_booking_id']);
                    booking_checkout_draft_clear();
                    $_SESSION['flash_success'] = 'You already have a pending reservation for these dates. Use My reservations to finish checkout when you are ready.';
                    redirect('/Apartment%20system/my_bookings.php');
                }
                $_SESSION['pending_checkout_booking_id'] = $existingId;
                booking_checkout_draft_clear();
                $_SESSION['flash_success'] = 'You already have a reservation for these dates. Continue to checkout.';
                redirect('/Apartment%20system/checkout.php?booking_id=' . $existingId);
            }

            $overlap = db()->prepare("
                SELECT COUNT(*) FROM bookings
                WHERE room_id = ?
                  AND status IN ('pending', 'confirmed')
                  AND NOT (COALESCE(early_check_out_date, check_out) <= ? OR check_in >= ?)
            ");
            $overlap->execute([$roomId, $checkIn, $checkOut]);
            if ((int) $overlap->fetchColumn() > 0) {
                $errors[] = 'Selected schedule is not available for this room.';
            } else {
                $inT = booking_time_for_db((string) ($_POST['check_in_time'] ?? ''));
                $outT = booking_time_for_db((string) ($_POST['check_out_time'] ?? ''));
                $_SESSION[booking_checkout_draft_session_key()] = [
                    'user_id' => (int) current_user()['id'],
                    'room_id' => $roomId,
                    'guest_count' => $guestCount,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'check_in_time' => $inT,
                    'check_out_time' => $outT,
                    'notes' => trim($_POST['notes'] ?? ''),
                    'payment_choice' => $paymentChoice,
                    'saved_at' => time(),
                ];
                unset($_SESSION['pending_checkout_booking_id']);
                if ($paymentChoice === 'pay_later') {
                    $_SESSION['flash_success'] = 'Review your request on the next screen, then submit to save your reservation.';
                    redirect('/Apartment%20system/checkout.php');
                }
                if ($paymentChoice === 'pay_cashier') {
                    $_SESSION['flash_success'] = 'Review your stay on the next screen, then submit. You will pay in person at the cashier.';
                    redirect('/Apartment%20system/checkout.php');
                }
                $_SESSION['flash_success'] = 'Review your stay and submit payment to confirm your reservation.';
                redirect('/Apartment%20system/checkout.php');
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
$pv = static function (string $key, string $default = ''): string {
    if (!is_post()) {
        return $default;
    }
    $v = $_POST[$key] ?? $default;

    return is_string($v) ? $v : $default;
};
?>
<div class="panel book-page">
    <header class="page-title book-page__title">
        <p class="page-title__kicker"><?= h($room['room_code']) ?></p>
        <h1>Book: <?= h($room['title']) ?></h1>
        <p class="book-page__headline-price">Room price: <strong>PHP <?= h(number_format((float) $room['monthly_rate'], 2)) ?> / day</strong></p>
        <p>Choose your stay dates and preferred times. Availability is checked on the dates you pick; times help the front desk plan your arrival. <strong>Your total is room amount × number of nights</strong> between your check-in and check-out dates.</p>
    </header>
    <div class="book-page__grid">
        <div class="book-page__aside">
            <?php $bookPhotos = room_gallery_public_preview($room); ?>
            <?php if ($bookPhotos !== []): ?>
                <div class="book-room-gallery" data-count="<?= count($bookPhotos) ?>" role="group" aria-label="Photos of this unit">
                    <?php foreach ($bookPhotos as $bi => $bsrc): ?>
                        <img class="book-room-gallery__img<?= $bi === 0 ? ' is-active' : '' ?>" src="<?= h($bsrc) ?>" alt="<?= $bi === 0 ? h($room['title']) . ' — photo ' . ($bi + 1) : '' ?>" loading="lazy" width="480" height="360" decoding="async">
                    <?php endforeach; ?>
                    <?php if (count($bookPhotos) > 1): ?>
                        <button type="button" class="gallery-prev-btn" data-gallery-prev aria-label="Previous photo"></button>
                        <button type="button" class="gallery-next-btn" data-gallery-next aria-label="Next photo"></button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="book-room-desc">
                <h2 class="h3-like book-room-desc__title">About this unit</h2>
                <p class="book-room-desc__price"><span class="book-room-desc__capacity-label">Room price</span> PHP <?= h(number_format((float) $room['monthly_rate'], 2)) ?> / day</p>
                <?php $cap = (int) $room['capacity']; ?>
                <p class="book-room-desc__capacity"><span class="book-room-desc__capacity-label">Capacity</span> Up to <?= $cap ?> guest<?= $cap === 1 ? '' : 's' ?>.</p>
                <p class="book-room-desc__text"><?= nl2br(h((string) $room['description'])) ?></p>
            </div>
        </div>
        <div class="book-page__form-col">
            <div class="summary-strip" aria-label="Room pricing">
                <p class="summary-strip__row">
                    <span>Room amount</span>
                    <strong>PHP <?= h(number_format((float) $room['monthly_rate'], 2)) ?> / day</strong>
                </p>
            </div>
            <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
            <form class="form form--wide book-page__form" method="post">
        <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
        <div class="book-page__schedule-fields">
            <div class="row row--single">
                <div>
                    <label for="check_in_at">Check-in schedule</label>
                    <input
                        id="check_in_at"
                        type="datetime-local"
                        name="check_in_at"
                        required
                        value="<?= h($pv('check_in_at', ($pv('check_in') !== '' ? ($pv('check_in') . 'T' . ($pv('check_in_time', '12:00') !== '' ? substr($pv('check_in_time', '12:00'), 0, 5) : '12:00')) : ''))) ?>"
                    >
                </div>
            </div>
            <div class="row row--single">
                <div>
                    <label for="check_out_at">Check-out schedule</label>
                    <input
                        id="check_out_at"
                        type="datetime-local"
                        name="check_out_at"
                        required
                        value="<?= h($pv('check_out_at', ($pv('check_out') !== '' ? ($pv('check_out') . 'T' . ($pv('check_out_time', '12:00') !== '' ? substr($pv('check_out_time', '12:00'), 0, 5) : '12:00')) : ''))) ?>"
                    >
                </div>
            </div>
        </div>
        <label for="guest_count">Number of guests</label>
        <input id="guest_count" type="number" name="guest_count" min="1" max="<?= (int)($room['capacity'] ?? 1) ?>" required value="<?= h($pv('guest_count', '1')) ?>">
        <p class="form-note">Room capacity: up to <?= (int)$room['capacity'] ?> guest<?= (int)$room['capacity'] === 1 ? '' : 's' ?>. If this is exceeded, booking cannot proceed.</p>
        <label for="payment_choice">Payment option</label>
        <select id="payment_choice" name="payment_choice" required>
            <option value="pay_now"<?= $pv('payment_choice', 'pay_now') === 'pay_now' ? ' selected' : '' ?>>Pay now</option>
            <option value="pay_later"<?= $pv('payment_choice', 'pay_now') === 'pay_later' ? ' selected' : '' ?>>Pay later (reserve request)</option>
            <option value="pay_cashier"<?= $pv('payment_choice', 'pay_now') === 'pay_cashier' ? ' selected' : '' ?>>Pay at cashier (in person)</option>
        </select>
        <p class="form-note">Pay at cashier: your booking stays <strong>pending</strong> until staff confirm payment. Bring ID and your reservation details.</p>
        <label for="notes">Notes (optional)</label>
        <textarea id="notes" name="notes" maxlength="300" rows="3" placeholder="Special requests, parking, etc."><?= h($pv('notes')) ?></textarea>
        <p class="form-note">Nights = calendar days from check-in up to the day before check-out (e.g. Apr 27–30 is 3 nights). Total is computed as room amount multiplied by nights.</p>
        <button type="submit" class="btn btn--primary btn--block">Continue to checkout</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
