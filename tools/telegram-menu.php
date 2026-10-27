<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/telegram.php';

$lock = null;
try {
    $command = $argv[1] ?? 'run';
    if (!in_array($command, ['run', 'show', 'setup'], true) || count($argv) > 2) {
        fwrite(STDERR, "Usage: php tools/telegram-menu.php run|show|setup\n"); exit(2);
    }
    if (!mahara_telegram_ready()) { echo json_encode(['enabled' => false]), "\n"; exit; }
    if ($command === 'setup') {
        mahara_telegram_request('setMyCommands', ['commands' => [
            ['command' => 'menu', 'description' => 'Заказы магазина'],
            ['command' => 'start', 'description' => 'Включить уведомления о заказах'],
            ['command' => 'stop', 'description' => 'Выключить уведомления'],
        ]]);
        mahara_telegram_request('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
        echo json_encode(['enabled' => true, 'commandsConfigured' => true], JSON_THROW_ON_ERROR), "\n"; exit;
    }
    if ($command === 'show') {
        $sent = 0; $failed = 0;
        foreach (mahara_telegram_subscribers() as $chat) {
            try { mahara_telegram_menu_send_home($chat); $sent++; }
            catch (Throwable) { $failed++; }
        }
        echo json_encode(['enabled' => true, 'menusSent' => $sent, 'failed' => $failed], JSON_THROW_ON_ERROR), "\n";
        exit($failed > 0 ? 1 : 0);
    }
    $lockPath = mahara_config()['data_dir'] . '/telegram-menu-worker.lock';
    if (is_link($lockPath)) throw new RuntimeException('Unsafe worker lock.');
    $lock = @fopen($lockPath, 'c');
    if (!is_resource($lock) || !chmod($lockPath, 0600)) throw new RuntimeException('Worker lock unavailable.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        echo json_encode(['enabled' => true, 'alreadyRunning' => true]), "\n"; exit;
    }
    mahara_telegram_subscribers();
    $deadline = hrtime(true) + 58_000_000_000; $polls = 0; $failed = 0;
    do {
        $wait = 1;
        try { mahara_telegram_poll(); $polls++; }
        catch (Throwable $error) {
            $failed++; $wait = min(8, 2 ** min(3, $failed));
            if ($error instanceof MaharaTelegramDeliveryError && $error->retryAfter > 0) $wait = max($wait, min(58, $error->retryAfter));
        }
        $remaining = (int)(($deadline - hrtime(true)) / 1_000_000_000);
        if ($remaining < 1) break;
        sleep(min($wait, $remaining));
    } while (hrtime(true) < $deadline);
    echo json_encode(['enabled' => true, 'polls' => $polls, 'failed' => $failed], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    fwrite(STDERR, "Telegram menu worker failed; no credentials or order data are printed.\n");
    $failedExit = true;
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
if (isset($failedExit)) exit(1);
