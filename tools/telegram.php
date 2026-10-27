<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/telegram.php';

try {
    $command = $argv[1] ?? 'status';
    if (!in_array($command, ['status', 'test'], true) || count($argv) > 2) {
        fwrite(STDERR, "Usage: php tools/telegram.php status|test\n"); exit(2);
    }
    $config = mahara_telegram_config();
    if ($command === 'status') {
        echo json_encode(['enabled' => $config['enabled'] === true, 'chatConfigured' => mahara_telegram_chat_valid($config['chat_id']), 'ready' => mahara_telegram_ready()], JSON_THROW_ON_ERROR), "\n";
        exit;
    }
    if (!mahara_telegram_ready()) throw new RuntimeException('Telegram is not ready.');
    $testId = 'TEST-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $text = "MAHARA — проверка уведомлений\nЭто тест настройки. Реального заказа и списания денег нет.\nИдентификатор: " . $testId
        . "\n\nПанель магазина: " . mahara_config()['origin'] . '/orders.html';
    $result = mahara_telegram_send($config['chat_id'], $text);
    echo json_encode(['sent' => true, 'test_id' => $testId, 'message_id' => $result['message_id']], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    fwrite(STDERR, "Telegram check failed. Check private settings; no credentials or recipient data are printed.\n"); exit(1);
}
