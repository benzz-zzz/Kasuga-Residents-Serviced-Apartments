<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/announcements.php';
require_once __DIR__ . '/includes/rating_stars.php';

$announcements = active_announcements_for('public');

$activeGuestSql = sql_room_has_active_guest_column();
$gallerySql = sql_room_gallery_concat_column();
$rooms = db()->query("
    SELECT r.*, x.avg_rating, x.review_count, {$activeGuestSql}, {$gallerySql}
    FROM rooms r
    LEFT JOIN (
        SELECT room_id, ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS review_count
        FROM reviews
        GROUP BY room_id
    ) x ON x.room_id = r.id
    WHERE r.is_active = 1
    ORDER BY r.id DESC
    LIMIT 3
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>
<?php if (!empty($announcements)): ?>
<section class="announcement-strip" aria-label="Property notices">
    <?php foreach ($announcements as $a): ?>
        <article class="announcement-strip__item">
            <strong><?= h($a['title']) ?></strong>
            <span><?= h($a['body']) ?></span>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="hero hero--future" aria-labelledby="hero-heading">
    <p class="hero__eyebrow">Kasuga · Metro Manila</p>
    <h1 id="hero-heading">Apartments designed for longer stays—with hotel-grade clarity</h1>
    <p>Choose a suite, see real availability and monthly rates, and manage your reservation in one place. Built for professionals, relocating families, and anyone who values a calm, well-run building.</p>
    <div class="hero__actions">
        <a class="btn btn--primary" href="<?= h(app_url('rooms.php')) ?>">View available apartments</a>
        <a class="btn btn--ghost" href="<?= h(app_url('services.php')) ?>">Building amenities</a>
    </div>
</section>

<section class="feature-grid">
    <article class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">◇</span>
        <h3>Real-time availability</h3>
        <p>Live room status and rates stay in sync—so you are never promised a unit that is already reserved.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">◎</span>
        <h3>Resident-first service</h3>
        <p>Verified accounts, booking history, and guest reviews help us keep standards high and communication transparent.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">▣</span>
        <h3>Secure checkout</h3>
        <p>Payment details are validated with care—ready to connect to your preferred gateway when you go live.</p>
    </article>
</section>

<section class="panel panel--glass">
    <div class="section-head">
        <span class="section-kicker">For property teams</span>
        <h2>One calm system for listings, bookings, and residents</h2>
        <p>Behind the scenes, staff can manage rooms, arrivals, announcements, and exports from a single admin workspace—while residents see a polished, mobile-friendly experience.</p>
    </div>
</section>

<section>
    <div class="section-head">
        <span class="section-kicker">Available now</span>
        <h2>Featured apartments</h2>
        <p>Each listing shows verified guest ratings when reviews are available—so you know what neighbors already love about the space.</p>
    </div>
    <div class="grid">
        <?php foreach ($rooms as $room): ?>
            <?php $occBadge = room_catalog_badge_text($room); ?>
            <article class="room-card">
                <?php if ($occBadge !== null): ?>
                    <span class="room-card__status-badge <?= h(room_catalog_badge_css_class($room)) ?>"><?= h($occBadge) ?></span>
                <?php endif; ?>
                <?php $photos = room_gallery_public_preview($room); ?>
                <?php if ($photos !== []): ?>
                    <div class="room-card__gallery" data-count="<?= count($photos) ?>" role="group" aria-label="<?= h($room['title']) ?> — photos">
                        <?php foreach ($photos as $pi => $src): ?>
                            <img class="room-card__gallery-img<?= $pi === 0 ? ' is-active' : '' ?>" src="<?= h($src) ?>" alt="<?= $pi === 0 ? h($room['title']) . ' — photo ' . ($pi + 1) : '' ?>" loading="lazy" width="400" height="300" decoding="async">
                        <?php endforeach; ?>
                        <?php if (count($photos) > 1): ?>
                            <button type="button" class="gallery-prev-btn" data-gallery-prev aria-label="Previous photo"></button>
                            <button type="button" class="gallery-next-btn" data-gallery-next aria-label="Next photo"></button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="room-card__body">
                    <span class="room-card__code"><?= h($room['room_code']) ?></span>
                    <h3><?= h($room['title']) ?></h3>
                    <p><?= h($room['description']) ?></p>
                    <?php if (!empty($room['review_count'])): ?>
                        <div class="room-card__rating">
                            <?= rating_stars_display((int)round((float)$room['avg_rating'])) ?>
                            <span class="room-card__rating-meta"><?= h((string)$room['avg_rating']) ?> · <?= (int)$room['review_count'] ?> reviews</span>
                        </div>
                    <?php endif; ?>
                    <div class="room-card__meta">
                        <span class="room-card__price">PHP <?= number_format((float)$room['monthly_rate'], 2) ?> <small>per day</small></span>
                    </div>
                    <?php if (room_is_open_for_booking($room)): ?>
                        <a class="btn btn--primary" href="<?= h(app_url('book.php?room_id=' . (int)$room['id'])) ?>">Reserve this apartment</a>
                    <?php else: ?>
                        <span class="btn btn--ghost btn--block" style="pointer-events:none;opacity:0.85">Not available to book</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <div class="section-cta">
        <a class="btn btn--primary" href="<?= h(APP_BASE . '/rooms.php') ?>">View all apartments</a>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
