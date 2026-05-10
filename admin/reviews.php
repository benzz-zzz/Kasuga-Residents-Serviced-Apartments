<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/rating_stars.php';

$reviews = db()->query("
    SELECT v.id, v.rating, v.comment, v.created_at,
           u.full_name, u.email,
           r.room_code, r.title AS room_title
    FROM reviews v
    JOIN users u ON u.id = v.user_id
    JOIN rooms r ON r.id = v.room_id
    ORDER BY v.created_at DESC
")->fetchAll();

$adminPageTitle = 'Reviews';
$adminNav = 'reviews';
require_once __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
    <h2>Guest feedback</h2>
    <div class="admin-table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Room</th>
                    <th scope="col">Guest</th>
                    <th scope="col">Rating</th>
                    <th scope="col">Comment</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reviews as $r): ?>
                <tr>
                    <td style="font-size:0.85rem;white-space:nowrap"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
                    <td><strong><?= h($r['room_code']) ?></strong><br><span class="admin-muted admin-muted--sm"><?= h($r['room_title']) ?></span></td>
                    <td><?= h($r['full_name']) ?><br><span class="admin-muted admin-muted--sm"><?= h($r['email']) ?></span></td>
                    <td><?= rating_stars_display((int)$r['rating']) ?></td>
                    <td style="max-width:280px"><?= h($r['comment']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($reviews)): ?>
                <tr><td colspan="5">No reviews yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
