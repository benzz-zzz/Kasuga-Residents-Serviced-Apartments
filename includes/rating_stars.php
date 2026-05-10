<?php
declare(strict_types=1);

if (!function_exists('h')) {
    require_once dirname(__DIR__) . '/config.php';
}

/**
 * Accessible read-only 1–5 star row (★ characters).
 */
function rating_stars_display(int $rating): string
{
    $r = max(0, min(5, $rating));
    $label = $r . ' out of 5 stars';
    $html = '<span class="rating-stars-display" role="img" aria-label="' . h($label) . '">';
    for ($i = 1; $i <= 5; $i++) {
        $fill = $i <= $r ? ' rating-stars-display__star--fill' : '';
        $html .= '<span class="rating-stars-display__star' . $fill . '" aria-hidden="true">★</span>';
    }
    $html .= '</span>';
    return $html;
}
