<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';
require_login();

if (is_post() && verify_csrf($_POST['csrf'] ?? null) && !empty($_POST['abandon_checkout_draft'])) {
    booking_checkout_draft_clear();
    $proofKeyEarly = 'checkout_payment_proof_code';
    if (!empty($_SESSION[$proofKeyEarly]) && is_array($_SESSION[$proofKeyEarly])) {
        unset($_SESSION[$proofKeyEarly]['_draft']);
    }
    $_SESSION['flash_success'] = 'Checkout discarded. No reservation was created.';
    redirect('/Apartment%20system/my_bookings.php');
}

$checkoutProofSessKey = 'checkout_payment_proof_code';
$draftKey = booking_checkout_draft_session_key();

$bookingId = 0;
if (is_post() && empty($_POST['confirm_checkout_draft'])) {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
}
if ($bookingId < 1) {
    $bookingId = (int) ($_GET['booking_id'] ?? 0);
}
if ($bookingId < 1 && !empty($_SESSION['pending_checkout_booking_id'])) {
    $bookingId = (int) $_SESSION['pending_checkout_booking_id'];
}

if ($bookingId >= 1) {
    booking_checkout_draft_clear();
}

$draft = booking_checkout_draft_peek();
$fromDraft = false;
$booking = null;
$draftBlocked = false;

if ($bookingId >= 1) {
    $stmt = db()->prepare("
        SELECT b.*, r.title AS room_title, r.description AS room_description,
               r.monthly_rate AS room_monthly_rate, r.capacity AS room_capacity
        FROM bookings b
        JOIN rooms r ON r.id = b.room_id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->execute([$bookingId, (int) current_user()['id']]);
    $booking = $stmt->fetch() ?: null;
} elseif ($draft !== null) {
    $fromDraft = true;
    $gallerySql = sql_room_gallery_concat_column();
    $roomStmt = db()->prepare("SELECT r.*, {$gallerySql} FROM rooms r WHERE r.id = ? AND r.is_active = 1");
    $roomStmt->execute([(int) $draft['room_id']]);
    $roomRow = $roomStmt->fetch();
    if (!$roomRow || !room_is_open_for_booking($roomRow)) {
        booking_checkout_draft_clear();
        $_SESSION['flash_error'] = 'This room is no longer available. Start again from the rooms catalog.';
        redirect('/Apartment%20system/rooms.php');
    }
    $overlap = db()->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE room_id = ?
          AND status IN ('pending', 'confirmed')
          AND NOT (COALESCE(early_check_out_date, check_out) <= ? OR check_in >= ?)
    ");
    $overlap->execute([(int) $draft['room_id'], (string) $draft['check_in'], (string) $draft['check_out']]);
    if ((int) $overlap->fetchColumn() > 0) {
        $draftBlocked = true;
    }
    $nightsDraft = booking_nights_count((string) $draft['check_in'], (string) $draft['check_out']);
    $totalDraft = booking_total_with_guest_fee(
        (float) $roomRow['monthly_rate'],
        $nightsDraft,
        (int) $draft['guest_count'],
        (int) $roomRow['capacity']
    );
    $booking = [
        'id' => 0,
        'room_id' => (int) $draft['room_id'],
        'guest_count' => (int) $draft['guest_count'],
        'check_in' => (string) $draft['check_in'],
        'check_out' => (string) $draft['check_out'],
        'check_in_time' => $draft['check_in_time'] ?? null,
        'check_out_time' => $draft['check_out_time'] ?? null,
        'notes' => (string) $draft['notes'],
        'total_amount' => $totalDraft,
        'status' => 'preview',
        'payment_submitted_at' => null,
        'created_at' => null,
        'early_check_out_date' => null,
        'room_title' => (string) $roomRow['title'],
        'room_description' => (string) ($roomRow['description'] ?? ''),
        'room_monthly_rate' => (float) $roomRow['monthly_rate'],
        'room_capacity' => (int) $roomRow['capacity'],
        'paid_amount' => null,
        'payment_choice' => (string) $draft['payment_choice'],
    ];
} else {
    unset($_SESSION['flash_success'], $_SESSION['pending_checkout_booking_id']);
    $_SESSION['flash_error'] = 'No reservation in progress. Choose a room to book or open My reservations.';
    redirect('/Apartment%20system/my_bookings.php');
}

if (!$booking) {
    unset($_SESSION['flash_success'], $_SESSION['pending_checkout_booking_id']);
    $_SESSION['flash_error'] = 'Booking not found. If you have a stay in progress, open My reservations and use “Finish checkout”.';
    redirect('/Apartment%20system/my_bookings.php');
}

$roomDescription = trim((string) ($booking['room_description'] ?? ''));
unset($_SESSION['pending_checkout_booking_id']);

$nights = booking_nights_count((string) $booking['check_in'], (string) $booking['check_out']);
$monthly = (float) ($booking['room_monthly_rate'] ?? 0);
$roomCapacity = (int) ($booking['room_capacity'] ?? 0);
$guestCount = (int) ($booking['guest_count'] ?? 1);
$nightly = $monthly > 0 ? booking_nightly_rate_from_monthly($monthly) : 0.0;
$baseTotal = $monthly > 0 ? booking_total_from_monthly_and_nights($monthly, $nights) : 0.0;
$calcTotal = $monthly > 0 ? booking_total_with_guest_fee($monthly, $nights, $guestCount, $roomCapacity) : 0.0;
$storedTotal = (float) ($booking['total_amount'] ?? 0);
$amountDue = $monthly > 0 ? $calcTotal : $storedTotal;

if (!$fromDraft && $monthly > 0 && $booking['status'] === 'pending' && abs($storedTotal - $amountDue) > 0.005) {
    $syncTotal = db()->prepare('UPDATE bookings SET total_amount = ? WHERE id = ? AND user_id = ?');
    $syncTotal->execute([$amountDue, $bookingId, (int) current_user()['id']]);
}

$hasProof = !empty($booking['payment_submitted_at']);
$deferredPaymentChoices = ['pay_later', 'pay_cashier'];
$isPayLaterDraft = $fromDraft && in_array((string) ($booking['payment_choice'] ?? ''), $deferredPaymentChoices, true);
$isCashierDraft = $fromDraft && (($booking['payment_choice'] ?? '') === 'pay_cashier');
$canPay = !$draftBlocked
    && (
        ($fromDraft && !$isPayLaterDraft)
        || (!$fromDraft && $booking['status'] === 'pending' && !$hasProof)
    );
$canSubmitPayLater = $fromDraft && $isPayLaterDraft && !$draftBlocked;

$proofStorageKey = $fromDraft ? '_draft' : $bookingId;
$paymentProofCode = '';
$checkoutQrPayload = '';
if ($canPay) {
    if (empty($_SESSION[$checkoutProofSessKey]) || !is_array($_SESSION[$checkoutProofSessKey])) {
        $_SESSION[$checkoutProofSessKey] = [];
    }
    if (empty($_SESSION[$checkoutProofSessKey][$proofStorageKey])) {
        $suffix = $fromDraft ? 'NEW' : (string) $bookingId;
        $_SESSION[$checkoutProofSessKey][$proofStorageKey] = 'KSR-' . $suffix . '-' . strtoupper(bin2hex(random_bytes(10)));
    }
    $paymentProofCode = (string) $_SESSION[$checkoutProofSessKey][$proofStorageKey];
    $checkoutQrPayload = APP_NAME . "\n"
        . ($fromDraft ? "New reservation (submit to confirm)\n" : ('Booking #' . $bookingId . "\n"))
        . 'Code: ' . $paymentProofCode . "\n"
        . 'Amount due (PHP): ' . number_format($amountDue, 2);
}

$errors = [];
if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } elseif (!empty($_POST['confirm_checkout_draft'])) {
        if (!$fromDraft || $draft === null) {
            $errors[] = 'This checkout session is no longer valid. Please start again from the book page.';
        } elseif ($draftBlocked) {
            $errors[] = 'These dates are no longer available. Go back to rooms and pick another schedule.';
        } else {
            $pdo = db();
            $gallerySql = sql_room_gallery_concat_column();
            $roomStmt = $pdo->prepare("SELECT r.*, {$gallerySql} FROM rooms r WHERE r.id = ? AND r.is_active = 1");
            $pdo->beginTransaction();
            try {
                $roomStmt->execute([(int) $draft['room_id']]);
                $roomRow = $roomStmt->fetch();
                if (!$roomRow || !room_is_open_for_booking($roomRow)) {
                    $pdo->rollBack();
                    booking_checkout_draft_clear();
                    $errors[] = 'This room is no longer available.';
                } else {
                    $overlap = $pdo->prepare("
                        SELECT COUNT(*) FROM bookings
                        WHERE room_id = ?
                          AND status IN ('pending', 'confirmed')
                          AND NOT (COALESCE(early_check_out_date, check_out) <= ? OR check_in >= ?)
                    ");
                    $overlap->execute([(int) $draft['room_id'], (string) $draft['check_in'], (string) $draft['check_out']]);
                    if ((int) $overlap->fetchColumn() > 0) {
                        $pdo->rollBack();
                        $errors[] = 'Selected schedule is no longer available.';
                    } else {
                        $nightsIns = booking_nights_count((string) $draft['check_in'], (string) $draft['check_out']);
                        $totalIns = booking_total_with_guest_fee(
                            (float) $roomRow['monthly_rate'],
                            $nightsIns,
                            (int) $draft['guest_count'],
                            (int) $roomRow['capacity']
                        );
                        $payChoice = (string) $draft['payment_choice'];
                        if ($payChoice === 'pay_now') {
                            $paidAmountRaw = trim((string) ($_POST['paid_amount'] ?? ''));
                            $paidAmount = is_numeric($paidAmountRaw) ? (float) $paidAmountRaw : 0.0;
                            if ($paidAmount <= 0) {
                                $errors[] = 'Please enter the amount you paid.';
                            }
                            $receiptRef = '';
                            if (!empty($_SESSION[$checkoutProofSessKey][$proofStorageKey]) && is_string($_SESSION[$checkoutProofSessKey][$proofStorageKey])) {
                                $receiptRef = trim($_SESSION[$checkoutProofSessKey][$proofStorageKey]);
                            }
                            if (mb_strlen($receiptRef) < 12 || mb_strlen($receiptRef) > 255) {
                                $errors[] = 'Payment verification is missing or expired. Refresh this page to generate a new QR code.';
                            }
                            if ($errors === []) {
                                $ins = $pdo->prepare("
                                    INSERT INTO bookings (user_id, room_id, guest_count, check_in, check_in_time, check_out, check_out_time, total_amount, paid_amount, receipt_reference, payment_submitted_at, status, notes, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?)
                                ");
                                $ins->execute([
                                    (int) current_user()['id'],
                                    (int) $draft['room_id'],
                                    (int) $draft['guest_count'],
                                    (string) $draft['check_in'],
                                    $draft['check_in_time'] ?: null,
                                    (string) $draft['check_out'],
                                    $draft['check_out_time'] ?: null,
                                    $totalIns,
                                    $paidAmount,
                                    $receiptRef,
                                    db_timestamp(),
                                    (string) $draft['notes'],
                                    db_timestamp(),
                                ]);
                                $pdo->commit();
                                booking_checkout_draft_clear();
                                unset($_SESSION[$checkoutProofSessKey]['_draft']);
                                sync_room_occupancy_from_stays((int) $draft['room_id']);
                                $_SESSION['flash_success'] = 'Reservation confirmed. Payment proof was submitted successfully.';
                                redirect('/Apartment%20system/my_bookings.php');
                            }
                            $pdo->rollBack();
                        } elseif ($payChoice === 'pay_later' || $payChoice === 'pay_cashier') {
                            $notesForDb = trim((string) $draft['notes']);
                            if ($payChoice === 'pay_cashier') {
                                $notesForDb = trim('[Payment: at cashier] ' . $notesForDb);
                            }
                            $ins = $pdo->prepare("
                                INSERT INTO bookings (user_id, room_id, guest_count, check_in, check_in_time, check_out, check_out_time, total_amount, status, notes, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
                            ");
                            $ins->execute([
                                (int) current_user()['id'],
                                (int) $draft['room_id'],
                                (int) $draft['guest_count'],
                                (string) $draft['check_in'],
                                $draft['check_in_time'] ?: null,
                                (string) $draft['check_out'],
                                $draft['check_out_time'] ?: null,
                                $totalIns,
                                $notesForDb,
                                db_timestamp(),
                            ]);
                            $pdo->commit();
                            booking_checkout_draft_clear();
                            sync_room_occupancy_from_stays((int) $draft['room_id']);
                            $_SESSION['flash_success'] = $payChoice === 'pay_cashier'
                                ? 'Reservation saved. Pay at the cashier when the property instructs you—your booking stays pending until staff confirm.'
                                : 'Reservation request saved. You can finish payment later from My reservations.';
                            redirect('/Apartment%20system/my_bookings.php');
                        } else {
                            $pdo->rollBack();
                            $errors[] = 'Invalid payment option.';
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Could not complete reservation. Please try again.';
            }
        }
    } elseif ($bookingId > 0) {
        if (!$canPay) {
            $errors[] = 'This reservation is no longer awaiting payment.';
        } else {
            $paidAmountRaw = trim((string) ($_POST['paid_amount'] ?? ''));
            $paidAmount = is_numeric($paidAmountRaw) ? (float) $paidAmountRaw : 0.0;
            $errors = [];
            if ($paidAmount <= 0) {
                $errors[] = 'Please enter the amount you paid.';
            }
            $receiptRef = '';
            if (!empty($_SESSION[$checkoutProofSessKey][$bookingId]) && is_string($_SESSION[$checkoutProofSessKey][$bookingId])) {
                $receiptRef = trim($_SESSION[$checkoutProofSessKey][$bookingId]);
            }
            if (mb_strlen($receiptRef) < 12 || mb_strlen($receiptRef) > 255) {
                $errors[] = 'Payment verification is missing or expired. Refresh this page to generate a new QR code.';
            }

            if ($errors === []) {
                $update = db()->prepare("
                    UPDATE bookings
                    SET paid_amount = ?, receipt_reference = ?, payment_submitted_at = ?, status = 'confirmed'
                    WHERE id = ? AND user_id = ? AND status = 'pending'
                ");
                $update->execute([$paidAmount, $receiptRef, db_timestamp(), $bookingId, (int) current_user()['id']]);
                if ($update->rowCount() === 0) {
                    $_SESSION['flash_error'] = 'Payment proof could not be submitted (it may have been updated).';
                    redirect('/Apartment%20system/my_bookings.php');
                }
                unset($_SESSION[$checkoutProofSessKey][$bookingId]);
                $_SESSION['flash_success'] = 'Payment received. Your reservation is now confirmed.';
                redirect('/Apartment%20system/my_bookings.php');
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';

$checkoutHeadline = $isPayLaterDraft ? 'Confirm reservation' : 'Complete payment';
$checkoutSub = $isCashierDraft
    ? 'Review your stay, then submit to save your reservation. You chose to pay in person at the cashier—no online payment on this page.'
    : ($isPayLaterDraft
        ? 'Review your stay details, then submit to save your reservation request. No payment is taken yet.'
        : 'Review your stay and room details on the left, then submit payment proof on the right to confirm your reservation.');
?>
<div class="panel checkout-page">
    <header class="page-title checkout-page__title">
        <p class="page-title__kicker">Secure checkout</p>
        <h1><?= h($checkoutHeadline) ?></h1>
        <p><?= h($checkoutSub) ?></p>
    </header>
    <div class="checkout-page__grid">
        <div class="checkout-page__details">
            <div class="summary-strip" role="group" aria-label="Booking summary">
                <p class="summary-strip__row"><span>Room</span><strong><?= h($booking['room_title']) ?></strong></p>
                <p class="summary-strip__row"><span>Guests</span><strong><?= $guestCount ?></strong></p>
                <p class="summary-strip__row"><span>Check-in</span><strong><?= h(format_booking_date_time((string) $booking['check_in'], $booking['check_in_time'] ?? null)) ?></strong></p>
                <p class="summary-strip__row"><span>Check-out</span><strong><?= h(format_booking_date_time((string) $booking['check_out'], $booking['check_out_time'] ?? null)) ?></strong></p>
                <?php if (!$fromDraft && !empty($booking['early_check_out_date'])): ?>
                <p class="summary-strip__row"><span>Early checkout</span><strong><?= h((string) $booking['early_check_out_date']) ?></strong> <span class="table-muted">(set by property)</span></p>
                <?php endif; ?>
                <p class="summary-strip__row"><span>Stay length</span><strong><?= (int) $nights ?> night<?= $nights === 1 ? '' : 's' ?></strong></p>
                <?php if ($monthly > 0): ?>
                <p class="summary-strip__row"><span>Nightly rate</span><strong>PHP <?= number_format($nightly, 2) ?></strong></p>
                <p class="summary-strip__row"><span>Room total</span><strong>PHP <?= number_format($baseTotal, 2) ?></strong></p>
                <p class="summary-strip__row"><span>Total</span><strong>PHP <?= number_format($amountDue, 2) ?></strong></p>
                <?php endif; ?>
                <?php if ($fromDraft): ?>
                <p class="summary-strip__row"><span>Status</span><strong>Not reserved yet</strong></p>
                <p class="summary-strip__row"><span>Reserved on</span><strong>—</strong></p>
                <?php else: ?>
                <p class="summary-strip__row"><span>Status</span><strong><?= h((string) $booking['status']) ?></strong></p>
                <?php if (!empty($booking['payment_submitted_at'])): ?>
                <p class="summary-strip__row"><span>Payment proof</span><strong>Submitted <?= h(format_booking_datetime($booking['payment_submitted_at'] ?? null)) ?></strong></p>
                <?php endif; ?>
                <p class="summary-strip__row"><span>Reserved on</span><strong><?= h(format_booking_datetime($booking['created_at'] ?? null)) ?></strong></p>
                <?php endif; ?>
            </div>
            <?php if ($draftBlocked): ?>
            <div class="alert error" role="status">Someone else may have booked these dates. Go back to <a href="/Apartment%20system/rooms.php">Rooms</a> and choose different dates.</div>
            <?php endif; ?>
            <?php if ($roomDescription !== ''): ?>
            <div class="book-room-desc checkout-page__room-desc" aria-label="Room description">
                <h2 class="h3-like book-room-desc__title">About this unit</h2>
                <p class="book-room-desc__text"><?= nl2br(h($roomDescription)) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <div class="checkout-page__receipt">
            <?php if ($fromDraft): ?>
            <form method="post" class="checkout-abandon-form" action="<?= h(APP_BASE . '/checkout.php') ?>" onsubmit="return confirm('Discard this checkout? Your details will not be saved as a reservation.');">
                <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                <input type="hidden" name="abandon_checkout_draft" value="1">
                <button type="submit" class="btn btn--cancel btn--block">Discard checkout</button>
            </form>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
            <?php if (!$canPay && !$canSubmitPayLater): ?>
                <h2 class="h3-like checkout-page__receipt-heading">Reservation status</h2>
                <div class="alert success" role="status">
                    <?php if ($fromDraft && $draftBlocked): ?>
                        These dates are no longer available. Return to the rooms page to pick another stay.
                    <?php elseif ($booking['status'] === 'confirmed'): ?>
                        This stay is already confirmed. No further payment is needed.
                    <?php elseif (!empty($booking['payment_submitted_at']) && $booking['status'] === 'pending'): ?>
                        Your payment proof was submitted.
                    <?php elseif ($booking['status'] === 'checked_out'): ?>
                        This stay is marked as checked out. No further payment is needed.
                    <?php elseif ($booking['status'] === 'cancelled'): ?>
                        This reservation was cancelled. Start a new booking from the rooms page if you still need a stay.
                    <?php else: ?>
                        This reservation cannot be paid online in its current state.
                    <?php endif; ?>
                </div>
                <p><a class="btn btn--primary" href="/Apartment%20system/my_bookings.php">Back to my reservations</a></p>
            <?php elseif ($canSubmitPayLater): ?>
                <h2 class="h3-like checkout-page__receipt-heading"><?= $isCashierDraft ? 'Pay at cashier' : 'Reservation request' ?></h2>
                <p class="checkout-page__receipt-intro"><?= $isCashierDraft
                    ? 'Submit once to save your pending reservation. Payment will be collected in person at the cashier; staff will confirm your booking after payment.'
                    : 'Submit once to save your pending reservation. You can complete payment later from My reservations.' ?></p>
                <form class="form form--wide checkout-page__form" method="post" autocomplete="off" action="<?= h(APP_BASE . '/checkout.php') ?>">
                    <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                    <input type="hidden" name="confirm_checkout_draft" value="1">
                    <button type="submit" class="btn btn--primary btn--block"><?= $isCashierDraft ? 'Submit reservation (pay at cashier)' : 'Submit reservation request' ?></button>
                </form>
            <?php else: ?>
                <h2 class="h3-like checkout-page__receipt-heading">Payment proof</h2>
                <p class="checkout-page__receipt-intro">Enter the amount you paid. Your verification code is generated below—show the QR to staff or keep the code handy; it is saved when you submit and your reservation is created.</p>
                <form class="form form--wide checkout-page__form" method="post" autocomplete="off" action="<?= h(APP_BASE . '/checkout.php' . ($fromDraft ? '' : '?booking_id=' . (int) $bookingId)) ?>">
                    <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                    <?php if ($fromDraft): ?>
                    <input type="hidden" name="confirm_checkout_draft" value="1">
                    <?php else: ?>
                    <input type="hidden" name="booking_id" value="<?= (int) $bookingId ?>">
                    <?php endif; ?>
                    <label for="paid_amount">Amount paid (PHP)</label>
                    <input id="paid_amount" name="paid_amount" type="number" step="0.01" min="0.01" value="<?= h((string) $amountDue) ?>" required>
                    <div class="checkout-qr-block">
                        <p class="checkout-qr-block__label">Payment verification QR</p>
                        <div class="checkout-qr-wrap" role="img" aria-label="QR code containing booking verification details">
                            <div id="checkout-qrcode" class="checkout-qr-el"></div>
                        </div>
                        <p class="checkout-qr-block__code"><span class="checkout-qr-block__code-label">Code</span> <code class="checkout-qr-block__code-value"><?= h($paymentProofCode) ?></code></p>
                        <p class="form-note">Scanning encodes <?= h(APP_NAME) ?>, this stay, the code above, and amount due.</p>
                    </div>
                    <button type="submit" class="btn btn--primary btn--block">Submit payment proof</button>
                </form>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
                <script>
                (function () {
                    var payload = <?= json_encode($checkoutQrPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    var el = document.getElementById('checkout-qrcode');
                    function draw() {
                        if (!el || typeof QRCode === 'undefined') return;
                        el.innerHTML = '';
                        new QRCode(el, {
                            text: payload,
                            width: 200,
                            height: 200,
                            colorDark: '#0c121c',
                            colorLight: '#ffffff',
                            correctLevel: QRCode.CorrectLevel.M
                        });
                    }
                    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', draw);
                    else draw();
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
