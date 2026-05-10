<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bookings-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
if ($out === false) {
    exit;
}
fputcsv($out, ['id', 'guest', 'email', 'room_code', 'guest_count', 'check_in', 'check_in_time', 'check_out', 'check_out_time', 'early_check_out_date', 'total_php', 'paid_amount_php', 'receipt_reference', 'payment_submitted_at', 'status', 'created_at']);

$q = db()->query("
    SELECT b.id, u.full_name, u.email, r.room_code, b.guest_count, b.check_in, b.check_in_time, b.check_out, b.check_out_time, b.early_check_out_date, b.total_amount, b.paid_amount, b.receipt_reference, b.payment_submitted_at, b.status, b.created_at
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    JOIN rooms r ON r.id = b.room_id
    ORDER BY b.id ASC
");
while ($row = $q->fetch()) {
    fputcsv($out, [
        $row['id'],
        $row['full_name'],
        $row['email'],
        $row['room_code'],
        $row['guest_count'] ?? 1,
        $row['check_in'],
        $row['check_in_time'] ?? '',
        $row['check_out'],
        $row['check_out_time'] ?? '',
        $row['early_check_out_date'] ?? '',
        $row['total_amount'],
        $row['paid_amount'] ?? '',
        $row['receipt_reference'] ?? '',
        $row['payment_submitted_at'] ?? '',
        $row['status'],
        $row['created_at'],
    ]);
}
fclose($out);
exit;
