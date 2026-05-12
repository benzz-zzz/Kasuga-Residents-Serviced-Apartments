<?php
declare(strict_types=1);

/**
 * Lightweight health endpoint for Railway / load balancers (no DB, no Composer).
 */
header('Content-Type: text/plain; charset=UTF-8');
http_response_code(200);
echo "ok\n";
