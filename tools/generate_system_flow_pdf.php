<?php
declare(strict_types=1);

/**
 * Generates docs/Kasuga_Residences_System_Flow_Descriptive.pdf from docs/system_flow_descriptive.html
 * Run: php tools/generate_system_flow_pdf.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$htmlPath = $root . '/docs/system_flow_descriptive.html';
$pdfPath = $root . '/docs/Kasuga_Residences_System_Flow_Descriptive.pdf';

if (!is_readable($htmlPath)) {
    fwrite(STDERR, "Missing template: {$htmlPath}\n");
    exit(1);
}

$html = file_get_contents($htmlPath);
if ($html === false) {
    fwrite(STDERR, "Could not read: {$htmlPath}\n");
    exit(1);
}

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dir = dirname($pdfPath);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$out = $dompdf->output();
if (file_put_contents($pdfPath, $out) === false) {
    fwrite(STDERR, "Could not write: {$pdfPath}\n");
    exit(1);
}

echo "Wrote: {$pdfPath}\n";
