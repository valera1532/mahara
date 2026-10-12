<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/payments.php';
require_once dirname(__DIR__) . '/server/notifications.php';
try {
    if (($argv[1] ?? '') !== 'send' || count($argv) > 2) { fwrite(STDERR, "Usage: php tools/notifications.php send\n"); exit(2); }
    echo json_encode(mahara_notifications_work(3), JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) { fwrite(STDERR, "Mail worker failed. Check private configuration; no credentials are printed.\n"); exit(1); }
