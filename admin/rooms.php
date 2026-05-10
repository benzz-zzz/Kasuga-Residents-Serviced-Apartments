<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_post() && verify_csrf($_POST['csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');
    $roomId = (int)($_POST['room_id'] ?? 0);

    if ($action === 'toggle' && $roomId > 0) {
        $t = db()->prepare('UPDATE rooms SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?');
        $t->execute([$roomId]);
        $_SESSION['flash_success'] = 'Room visibility updated.';
    } elseif ($action === 'occupancy' && $roomId > 0) {
        $occ = (string)($_POST['occupancy_status'] ?? '');
        if (!in_array($occ, room_occupancy_statuses(), true)) {
            $_SESSION['flash_error'] = 'Invalid availability.';
        } else {
            db()->prepare('UPDATE rooms SET occupancy_status = ? WHERE id = ?')->execute([$occ, $roomId]);
            $_SESSION['flash_success'] = 'Room set to: ' . room_occupancy_label($occ) . '.';
        }
    } else {
        $_SESSION['flash_error'] = 'Invalid action.';
    }
    redirect(admin_url('rooms.php'));
}

sync_all_rooms_occupancy_from_stays();
$rooms = db()->query('SELECT * FROM rooms ORDER BY room_code ASC')->fetchAll();

$adminPageTitle = 'Rooms';
$adminNav = 'rooms';
require_once __DIR__ . '/includes/header.php';
?>
<p style="margin:0 0 1rem">
    <a class="admin-btn" href="<?= h(admin_url('room_edit.php')) ?>">+ Add room</a>
</p>
<div class="admin-card">
    <h2>Apartment catalog</h2>
    <p class="admin-muted" style="font-size:0.9rem;margin-top:0"><strong>Open</strong> = guests can book (unless dates clash with an existing reservation). <strong>Occupied</strong> = always shows <em>Reserved</em> and blocks booking. <strong>Maintenance</strong> = shows <em>Unavailable</em> and blocks booking. <strong>Reservation status is updated automatically</strong> to <em>Occupied</em> while a guest is in-house (pending or confirmed, today between check-in and check-out) and back to <em>Open</em> when not; <strong>Maintenance</strong> is left as you set. Use <a href="<?= h(admin_url('bookings.php')) ?>">Bookings</a> → <strong>Checked out</strong> when they have vacated so the room can return to <em>Open</em>.</p>
    <div class="admin-table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Title</th>
                    <th scope="col">Rate / mo</th>
                    <th scope="col">Cap.</th>
                    <th scope="col">On website</th>
                    <th scope="col">Reservation status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rooms as $r): ?>
                <?php $occ = (string)($r['occupancy_status'] ?? 'vacant'); ?>
                <tr>
                    <td><strong><?= h($r['room_code']) ?></strong></td>
                    <td><?= h($r['title']) ?></td>
                    <td>PHP <?= number_format((float)$r['monthly_rate'], 2) ?></td>
                    <td><?= (int)$r['capacity'] ?></td>
                    <td><?= (int)$r['is_active'] ? 'Yes' : 'No' ?></td>
                    <td>
                        <span class="admin-muted admin-muted--sm" style="display:block;margin-bottom:0.35rem"><?= h(room_occupancy_label($occ)) ?></span>
                        <form method="post" class="admin-room-occ-actions" aria-label="Set room availability">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="action" value="occupancy">
                            <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" name="occupancy_status" value="vacant" class="admin-btn admin-btn--compact<?= $occ === 'vacant' ? '' : ' admin-btn--ghost' ?>"<?= $occ === 'vacant' ? ' disabled' : '' ?>>Open</button>
                            <button type="submit" name="occupancy_status" value="occupied" class="admin-btn admin-btn--compact<?= $occ === 'occupied' ? '' : ' admin-btn--ghost' ?>"<?= $occ === 'occupied' ? ' disabled' : '' ?>>Occupied</button>
                            <button type="submit" name="occupancy_status" value="maintenance" class="admin-btn admin-btn--compact<?= $occ === 'maintenance' ? '' : ' admin-btn--ghost' ?>"<?= $occ === 'maintenance' ? ' disabled' : '' ?>>Maintenance</button>
                        </form>
                    </td>
                    <td style="white-space:nowrap">
                        <a class="admin-btn admin-btn--ghost" style="min-height:36px;padding:0.35rem 0.7rem" href="<?= h(admin_url('room_edit.php?id=' . (int)$r['id'])) ?>">Edit</a>
                        <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Toggle listing visibility for this room?');">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="admin-btn admin-btn--ghost" style="min-height:36px;padding:0.35rem 0.7rem">
                                <?= (int)$r['is_active'] ? 'Hide' : 'Show' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rooms)): ?>
                <tr><td colspan="7">No rooms. <a href="<?= h(admin_url('room_edit.php')) ?>">Add one</a>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
