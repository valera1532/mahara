<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/payments.php';
require_once dirname(__DIR__) . '/server/notifications.php';
try {
    if (($argv[1] ?? '') !== 'send' || count($argv) > 2) { fwrite(STDERR, "Usage: php tools/notifications.php send\n"); exit(2); }
    $results = []; $failed = false;
    foreach (['mail' => 'mahara_notifications_work', 'telegram' => 'mahara_telegram_work'] as $channel => $worker) {
        try { $results[$channel] = $worker(3); }
        catch (Throwable) { $results[$channel] = ['error' => true]; $failed = true; }
    }
    echo json_encode($results, JSON_THROW_ON_ERROR), "\n";
    if ($failed) { fwrite(STDERR, "Notification worker failed for a channel. Check private configuration; no credentials are printed.\n"); exit(1); }
} catch (Throwable) { fwrite(STDERR, "Mail worker failed. Check private configuration; no credentials are printed.\n"); exit(1); }
