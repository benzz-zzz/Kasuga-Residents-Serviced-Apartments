<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/rating_stars.php';

sync_all_rooms_occupancy_from_stays();

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
    ORDER BY r.monthly_rate ASC
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>
<header class="page-title">
    <p class="page-title__kicker">Live catalog</p>
    <h1>Rooms &amp; suites</h1>
    <p>Capacity, monthly rate, and verified guest ratings—pick a unit and lock your dates in seconds.</p>
</header>
<div class="grid">
    <?php foreach ($rooms as $room): ?>
        <?php $occBadge = room_catalog_badge_text($room); ?>
        <article
            class="room-card room-card--interactive"
            tabindex="0"
            role="button"
            aria-haspopup="dialog"
        >
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
                <p class="room-card__desc"><?= h($room['description']) ?></p>
                <p class="room-card__capacity">Up to <strong><?= (int)$room['capacity'] ?></strong> guests</p>
                <?php if (!empty($room['review_count'])): ?>
                    <div class="room-card__rating">
                        <?= rating_stars_display((int)round((float)$room['avg_rating'])) ?>
                        <span class="room-card__rating-meta"><?= h((string)$room['avg_rating']) ?> average · <?= (int)$room['review_count'] ?> reviews</span>
                    </div>
                <?php endif; ?>
                <div class="room-card__meta">
                    <span class="room-card__price">PHP <?= number_format((float)$room['monthly_rate'], 2) ?> <small>per day</small></span>
                </div>
                <?php if (room_is_open_for_booking($room)): ?>
                    <a class="btn btn--primary" href="<?= h(app_url('book.php?room_id=' . (int)$room['id'])) ?>">Select this room</a>
                <?php else: ?>
                    <span class="btn btn--ghost btn--block" style="pointer-events:none;opacity:0.85">Not available to book</span>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<div class="room-modal" id="room-modal" hidden>
    <div class="room-modal__backdrop" data-room-modal-close tabindex="-1"></div>
    <div class="room-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="room-modal-title">
        <button type="button" class="room-modal__close" data-room-modal-close aria-label="Close room details"><span aria-hidden="true">×</span></button>
        <div class="room-modal__hero" id="room-modal-gallery">
            <div class="room-modal__image-frame">
                <img id="room-modal-image" class="room-modal__image" src="" alt="" hidden>
                <div class="room-modal__scrim" aria-hidden="true"></div>
            </div>
            <button type="button" class="gallery-prev-btn room-modal__nav room-modal__nav--prev" id="room-modal-gallery-prev" hidden aria-label="Previous photo"></button>
            <button type="button" class="gallery-next-btn room-modal__nav room-modal__nav--next" id="room-modal-gallery-next" hidden aria-label="Next photo"></button>
        </div>
        <div class="room-modal__sheet">
            <header class="room-modal__header">
                <p class="room-modal__code" id="room-modal-code"></p>
                <h2 id="room-modal-title"></h2>
            </header>
            <p class="room-modal__lead" id="room-modal-description"></p>
            <dl class="room-modal__facts">
                <div class="room-modal__fact">
                    <dt class="room-modal__fact-label">Capacity</dt>
                    <dd class="room-modal__fact-value" id="room-modal-capacity"></dd>
                </div>
                <div class="room-modal__fact room-modal__fact--accent">
                    <dt class="room-modal__fact-label">From</dt>
                    <dd class="room-modal__fact-value" id="room-modal-price"></dd>
                </div>
            </dl>
            <div class="room-modal__rating" id="room-modal-rating" hidden>
                <p class="room-modal__rating-label">Guest ratings</p>
                <div class="room-modal__rating-inner" id="room-modal-rating-inner"></div>
            </div>
            <div class="room-modal__cta">
                <a id="room-modal-book" class="btn btn--primary btn--block room-modal__book" href="#">Select this room</a>
                <p class="room-modal__unavailable" id="room-modal-unavailable" hidden>This unit is not open for booking right now.</p>
            </div>
        </div>
    </div>
</div>
<script>
(() => {
    const cards = Array.from(document.querySelectorAll('.room-card--interactive'));
    const modal = document.getElementById('room-modal');
    if (!cards.length || !modal) return;

    const titleEl = document.getElementById('room-modal-title');
    const codeEl = document.getElementById('room-modal-code');
    const descEl = document.getElementById('room-modal-description');
    const capacityEl = document.getElementById('room-modal-capacity');
    const priceEl = document.getElementById('room-modal-price');
    const imageEl = document.getElementById('room-modal-image');
    const galleryPrevBtn = document.getElementById('room-modal-gallery-prev');
    const galleryNextBtn = document.getElementById('room-modal-gallery-next');
    const bookEl = document.getElementById('room-modal-book');
    const unavailableEl = document.getElementById('room-modal-unavailable');
    const ratingHost = document.getElementById('room-modal-rating');
    const ratingInner = document.getElementById('room-modal-rating-inner');
    let lastFocused = null;
    let modalPhotos = [];
    let modalPhotoIndex = 0;
    let modalPhotoTitle = '';

    const showModalPhotoAt = (index) => {
        if (!modalPhotos.length) return;
        modalPhotoIndex = (index + modalPhotos.length) % modalPhotos.length;
        imageEl.src = modalPhotos[modalPhotoIndex];
        imageEl.alt = `${modalPhotoTitle} — photo ${modalPhotoIndex + 1}`;
    };

    const setModalImage = (photos, title) => {
        const validPhotos = Array.isArray(photos)
            ? photos.map((src) => String(src || '').trim()).filter(Boolean)
            : [];

        modalPhotos = validPhotos;
        modalPhotoTitle = title;
        modalPhotoIndex = 0;

        if (!validPhotos.length) {
            imageEl.hidden = true;
            imageEl.removeAttribute('src');
            imageEl.alt = '';
            if (galleryPrevBtn) galleryPrevBtn.hidden = true;
            if (galleryNextBtn) galleryNextBtn.hidden = true;
            return;
        }
        imageEl.hidden = false;
        showModalPhotoAt(0);
        const multi = validPhotos.length > 1;
        if (galleryPrevBtn) galleryPrevBtn.hidden = !multi;
        if (galleryNextBtn) galleryNextBtn.hidden = !multi;
    };

    galleryPrevBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        showModalPhotoAt(modalPhotoIndex - 1);
    });
    galleryNextBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        showModalPhotoAt(modalPhotoIndex + 1);
    });

    const openModal = (card) => {
        lastFocused = document.activeElement;
        const title = card.querySelector('h3')?.textContent?.trim() || 'Room details';
        const code = card.querySelector('.room-card__code')?.textContent?.trim() || '';
        const description = card.querySelector('.room-card__desc')?.textContent?.trim()
            || card.querySelector('.room-card__body > p')?.textContent?.trim() || '';
        const capacity = card.querySelector('.room-card__capacity strong')?.textContent?.trim() || '';
        const priceText = card.querySelector('.room-card__price')?.textContent?.replace(/\s+/g, ' ')?.trim() || '';
        const bookHref = card.querySelector('a.btn.btn--primary')?.getAttribute('href') || '';
        const photos = Array.from(card.querySelectorAll('.room-card__gallery-img'))
            .map((img) => img.getAttribute('src') || '')
            .map((src) => src.trim())
            .filter(Boolean);

        titleEl.textContent = title;
        codeEl.textContent = code;
        descEl.textContent = description;
        capacityEl.textContent = capacity ? `Up to ${capacity} guest${capacity === '1' ? '' : 's'}` : '';
        priceEl.textContent = priceText || '—';

        if (ratingInner && ratingHost) {
            ratingInner.innerHTML = '';
            const ratingSrc = card.querySelector('.room-card__rating');
            if (ratingSrc) {
                ratingHost.hidden = false;
                ratingInner.appendChild(ratingSrc.cloneNode(true));
            } else {
                ratingHost.hidden = true;
            }
        }

        setModalImage(photos, title);

        if (bookHref) {
            bookEl.hidden = false;
            bookEl.href = bookHref;
            if (unavailableEl) unavailableEl.hidden = true;
        } else {
            bookEl.hidden = true;
            bookEl.removeAttribute('href');
            if (unavailableEl) unavailableEl.hidden = false;
        }

        modal.hidden = false;
        document.body.classList.add('modal-open');
        modal.querySelector('.room-modal__close')?.focus();
    };

    const closeModal = () => {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    };

    cards.forEach((card) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('[data-gallery-prev], [data-gallery-next]')) return;
            if (event.target.closest('a.btn.btn--primary')) {
                event.preventDefault();
            }
            openModal(card);
        });
        card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openModal(card);
            }
        });
    });

    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-room-modal-close]')) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
