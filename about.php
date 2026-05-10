<?php
declare(strict_types=1);
$page_head_extras = function (): string {
    $url = app_public_base_url() . '/about.php';
    $img = app_public_base_url() . '/' . ltrim((string) asset_url('og-about.svg'), '/');
    $title = APP_NAME . ' — About';
    $desc = 'Apartment living, run with the discipline of a boutique hotel.';

    return '
<meta property="og:type" content="website">
<meta property="og:url" content="' . h($url) . '">
<meta property="og:title" content="' . h($title) . '">
<meta property="og:description" content="' . h($desc) . '">
<meta property="og:image" content="' . h($img) . '">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="' . h($title) . '">
<meta name="twitter:description" content="' . h($desc) . '">
<meta name="twitter:image" content="' . h($img) . '">
';
};
require_once __DIR__ . '/includes/header.php';
?>
<header class="page-title">
    <p class="page-title__kicker">About Kasuga</p>
    <h1>Apartment living, run with the discipline of a boutique hotel</h1>
    <p>Kasuga Residences combines well-planned suites, fair long-stay rates, and responsive building management—so comfort, clarity, and human support feel like one experience, not separate channels.</p>
</header>

<section class="about-hero-media" aria-label="Kasuga Residences preview">
    <img
        class="about-hero-media__img"
        src="<?= h(asset_url('og-about.svg')) ?>"
        alt="Kasuga Residences — modern extended-stay apartments"
        loading="eager"
        decoding="async"
        width="1200"
        height="630"
    >
</section>

<section class="panel">
    <div class="section-head">
        <span class="section-kicker">Mission</span>
        <h2>What we optimize every day</h2>
    </div>
    <ul>
        <li><strong>Comfort &amp; affordability</strong> — Thoughtful layouts, fair rates, and predictable billing so families can plan long-term.</li>
        <li><strong>Modern spaces</strong> — Lighting, airflow, storage, and connectivity designed for how people actually live and work from home.</li>
        <li><strong>Peace &amp; safety</strong> — Quiet hours, secure access patterns, and staff visibility when guests need a human.</li>
        <li><strong>Facility excellence</strong> — Housekeeping rhythms, preventive maintenance, and transparent communication when work happens.</li>
        <li><strong>Loyal communities</strong> — We earn renewals through service quality, not lock-in—reviews and ratings stay visible to everyone.</li>
    </ul>
</section>

<section class="feature-grid">
    <article class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">⬡</span>
        <h3>Transparent operations</h3>
        <p>Owners see arrivals, exports, and guest sentiment in one admin surface aligned with the public experience.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">⎔</span>
        <h3>Resident-first UX</h3>
        <p>Booking, checkout, and feedback are designed as a continuous journey—not a maze of PDFs and phone trees.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">◈</span>
        <h3>Ready to scale</h3>
        <p>A structured data model and modular interface so you can add payments, access control, and integrations as the portfolio grows.</p>
    </article>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
