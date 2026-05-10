<?php
declare(strict_types=1);
$page_head_extras = static fn (): string =>
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">';
$page_scripts = static fn (): string =>
    '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>'
    . '<script src="' . h(asset_url('map.js')) . '" defer></script>';
require_once __DIR__ . '/includes/header.php';
?>
<header class="page-title">
    <p class="page-title__kicker">Contact</p>
    <h1>Leasing office &amp; building address</h1>
    <p>For tours, monthly leases, partnerships, or urgent in-stay issues—use the channel that fits. We reply during business hours and prioritize current residents.</p>
</header>

<section class="panel">
    <div class="section-head">
        <span class="section-kicker">Direct lines</span>
        <h2>Reach the team</h2>
    </div>
    <p><strong>Reservations &amp; leasing</strong><br><a href="mailto:stay@kasuga.local">stay@kasuga.local</a></p>
    <p><strong>Property operations</strong><br><a href="mailto:ops@kasuga.local">ops@kasuga.local</a></p>
    <p><strong>Voice</strong><br>0912 345 6789 · Mon–Sat 09:00–20:00 (GMT+8)</p>
    <p><strong>Campus</strong><br>Kasuga Residences, Northline District, Metro Manila</p>

    <div class="map-embed" aria-label="Map location">
        <div
            class="map-embed__frame"
            data-leaflet-map
            data-lat="<?= h((string)PROPERTY_LAT) ?>"
            data-lng="<?= h((string)PROPERTY_LNG) ?>"
            data-zoom="<?= h((string)PROPERTY_MAP_ZOOM) ?>"
            data-title="<?= h(PROPERTY_NAME) ?>"
            data-address="<?= h(PROPERTY_ADDRESS) ?>"
            tabindex="0"
            role="application"
            aria-label="<?= h(PROPERTY_NAME) ?> map"
        ></div>
    </div>
</section>

<section class="feature-grid">
    <article class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">✉</span>
        <h3>Email-first routing</h3>
        <p>Clear subject lines help us attach your thread to the right unit or booking ID automatically.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">⌁</span>
        <h3>On-site visits</h3>
        <p>Schedule a walkthrough from the rooms page—availability syncs with the same engine guests use to book.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">⚡</span>
        <h3>Urgent access</h3>
        <p>Locked out or safety issue? Call the voice line and reference your stay dates for priority escalation.</p>
    </article>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
