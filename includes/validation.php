<?php
declare(strict_types=1);

function validate_registration(array $input): array
{
    $errors = [];
    $fieldErrors = validate_registration_fields($input);
    foreach ($fieldErrors as $messages) {
        foreach ($messages as $msg) {
            $errors[] = $msg;
        }
    }

    return $errors;
}

/** @return array{full_name:list<string>,email:list<string>,phone:list<string>,password:list<string>} */
function validate_registration_fields(array $input): array
{
    $errors = [
        'full_name' => [],
        'email' => [],
        'phone' => [],
        'password' => [],
    ];
    $fullName = normalize_spaces((string)($input['full_name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $phone = preg_replace('/\D+/', '', (string)($input['phone'] ?? '')) ?? '';
    $password = (string)($input['password'] ?? '');

    if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 80) {
        $errors['full_name'][] = 'Full name must be 3 to 80 characters.';
    }
    if ($fullName !== '' && !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $fullName)) {
        $errors['full_name'][] = 'Full name may only contain letters, spaces, dot, apostrophe, and hyphen.';
    }
    if (preg_match('/\s{2,}/', $fullName)) {
        $errors['full_name'][] = 'Full name cannot contain repeated spaces.';
    }

    if (!is_strict_email($email)) {
        $errors['email'][] = 'Please enter a valid email address.';
    }

    if (!preg_match('/^09\d{9}$/', $phone)) {
        $errors['phone'][] = 'Phone must start with 09 and contain 11 digits.';
    }

    $pwdErr = validate_password_strength($password);
    if ($pwdErr !== null) {
        $errors['password'][] = $pwdErr;
    }

    return $errors;
}

/** @return array{full_name:string,email:string,phone:string,password:string} */
function sanitize_registration_input(array $input): array
{
    return [
        'full_name' => normalize_spaces((string)($input['full_name'] ?? '')),
        'email' => strtolower(trim((string)($input['email'] ?? ''))),
        'phone' => preg_replace('/\D+/', '', (string)($input['phone'] ?? '')) ?? '',
        'password' => (string)($input['password'] ?? ''),
    ];
}

/** @return string|null Error message, or null if the password meets policy. */
function validate_password_strength(string $password): ?string
{
    $ok = preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,64}$/', $password);

    return $ok ? null : 'Password must be 8-64 chars with uppercase, lowercase, number, and symbol.';
}

function validate_booking(array $input, ?int $maxGuests = null): array
{
    $errors = [];
    $checkInAt = trim((string)($input['check_in_at'] ?? ''));
    $checkOutAt = trim((string)($input['check_out_at'] ?? ''));
    $checkIn = (string)($input['check_in'] ?? '');
    $checkOut = (string)($input['check_out'] ?? '');
    $checkInTimeRaw = trim((string)($input['check_in_time'] ?? ''));
    $checkOutTimeRaw = trim((string)($input['check_out_time'] ?? ''));
    $guestCountRaw = trim((string)($input['guest_count'] ?? ''));

    if ($checkInAt !== '') {
        $dtIn = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $checkInAt);
        if (!$dtIn) {
            $errors[] = 'Please provide a valid check-in date and time.';
        } else {
            $checkIn = $dtIn->format('Y-m-d');
            $checkInTimeRaw = $dtIn->format('H:i');
        }
    }
    if ($checkOutAt !== '') {
        $dtOut = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $checkOutAt);
        if (!$dtOut) {
            $errors[] = 'Please provide a valid check-out date and time.';
        } else {
            $checkOut = $dtOut->format('Y-m-d');
            $checkOutTimeRaw = $dtOut->format('H:i');
        }
    }

    $inDate = DateTime::createFromFormat('Y-m-d', $checkIn);
    $outDate = DateTime::createFromFormat('Y-m-d', $checkOut);

    if (!$inDate || !$outDate) {
        return ['Please provide valid check-in and check-out schedule.'];
    }

    $today = new DateTime('today');
    if ($inDate < $today) {
        $errors[] = 'Check-in date cannot be in the past.';
    }

    if ($outDate <= $inDate) {
        $errors[] = 'Check-out date must be later than check-in date.';
    }

    if ((int)$inDate->diff($outDate)->days > 365) {
        $errors[] = 'Booking duration cannot exceed 365 days.';
    }

    foreach (['check_in_time' => 'Check-in time', 'check_out_time' => 'Check-out time'] as $field => $label) {
        $raw = $field === 'check_in_time' ? $checkInTimeRaw : $checkOutTimeRaw;
        if ($raw === '') {
            continue;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $raw)) {
            $errors[] = $label . ' must use 24-hour HH:MM (optional seconds).';
        }
    }

    if ($guestCountRaw === '') {
        $errors[] = 'Please enter the number of guests.';
    } else {
        $guestCount = filter_var($guestCountRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($guestCount === false) {
            $errors[] = 'Number of guests must be a whole number greater than 0.';
        } elseif ($maxGuests !== null && $maxGuests > 0 && $guestCount > $maxGuests) {
            $errors[] = 'Number of guests cannot exceed this room capacity (' . $maxGuests . ').';
        }
    }

    return $errors;
}

/** Normalize HTML time input to MySQL TIME string, or null if empty. */
function booking_time_for_db(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw)) {
        return $raw . ':00';
    }
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $raw)) {
        return $raw;
    }

    return null;
}

/** Error message or null if early checkout is valid (strictly before booked check-out, not before check-in). */
function validate_early_check_out_date(string $checkIn, string $checkOut, string $early): ?string
{
    $in = DateTimeImmutable::createFromFormat('Y-m-d', $checkIn);
    $out = DateTimeImmutable::createFromFormat('Y-m-d', $checkOut);
    $earlyD = DateTimeImmutable::createFromFormat('Y-m-d', $early);
    if (!$in || !$out || !$earlyD) {
        return 'Invalid date format.';
    }
    if ($earlyD < $in) {
        return 'Early checkout cannot be before check-in.';
    }
    if ($earlyD >= $out) {
        return 'Early checkout must be before the booked check-out date.';
    }

    return null;
}

function validate_payment(array $input): array
{
    $errors = [];
    $cardName = trim($input['card_name'] ?? '');
    $cardNumber = preg_replace('/\s+/', '', (string)($input['card_number'] ?? ''));
    $expiry = trim($input['expiry'] ?? '');
    $cvv = trim($input['cvv'] ?? '');

    if (mb_strlen($cardName) < 3 || mb_strlen($cardName) > 80) {
        $errors[] = 'Card name must be 3-80 characters.';
    }
    if (!preg_match('/^\d{16}$/', $cardNumber)) {
        $errors[] = 'Card number must be 16 digits.';
    }
    if (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry)) {
        $errors[] = 'Expiry must be in MM/YY format.';
    }
    if (!preg_match('/^\d{3,4}$/', $cvv)) {
        $errors[] = 'CVV must be 3 or 4 digits.';
    }

    return $errors;
}

function normalize_spaces(string $value): string
{
    return trim((string)(preg_replace('/\s+/', ' ', $value) ?? $value));
}

function is_strict_email(string $email): bool
{
    if ($email === '' || mb_strlen($email) > 254 || preg_match('/[\r\n]/', $email)) {
        return false;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    // Enforce common "formal" format: local@domain.tld
    // - local part starts/ends alnum, allows ._%+- inside
    // - domain labels start/end alnum, hyphens allowed inside
    // - TLD letters only, at least 2 chars
    $strict = '/^[A-Za-z0-9](?:[A-Za-z0-9._%+\-]{0,62}[A-Za-z0-9])?@(?:[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/';

    return preg_match($strict, $email) === 1;
}

