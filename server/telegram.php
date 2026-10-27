<?php
declare(strict_types=1);

require_once __DIR__ . '/payments.php';

final class MaharaTelegramDeliveryError extends RuntimeException {
    public function __construct(public readonly int $retryAfter = 0) {
        parent::__construct('Telegram delivery unavailable.');
    }
}

function mahara_telegram_config(): array {
    $defaults = ['enabled' => false, 'chat_id' => ''];
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_telegram_test_config'])) return array_merge($defaults, $GLOBALS['mahara_telegram_test_config']);
    return array_merge($defaults, mahara_config()['telegram'] ?? []);
}

function mahara_telegram_secrets(): array {
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_telegram_test_secrets'])) return $GLOBALS['mahara_telegram_test_secrets'];
    $path = mahara_config()['data_dir'] . '/telegram-secrets.php';
    if (!is_file($path) || is_link($path)) return ['token' => ''];
    ob_start();
    try { $secrets = @require $path; }
    catch (Throwable) { throw new MaharaTelegramDeliveryError(); }
    finally { ob_end_clean(); }
    if (!is_array($secrets) || !is_string($secrets['token'] ?? null)) throw new MaharaTelegramDeliveryError();
    return ['token' => $secrets['token']];
}

function mahara_telegram_chat_valid(mixed $chatId): bool {
    return is_string($chatId) && preg_match('/^-?[1-9][0-9]{0,19}$/D', $chatId) === 1;
}

function mahara_telegram_ready(): bool {
    $config = mahara_telegram_config();
    if ($config['enabled'] !== true || !mahara_telegram_chat_valid($config['chat_id'])) return false;
    if (!str_starts_with(mahara_config()['origin'], 'https://')) return false;
    // A complete fixture transport never opens private credential files.
    if (defined('MAHARA_LIBRARY_ONLY') && is_callable($GLOBALS['mahara_telegram_test_transport'] ?? null)) return true;
    try { $token = mahara_telegram_secrets()['token'] ?? ''; }
    catch (Throwable) { return false; }
    return is_string($token) && preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,128}$/D', $token) === 1;
}

function mahara_telegram_schema(): void {
    static $ready = false;
    if ($ready) return;
    mahara_payments_schema();
    mahara_db()->exec('CREATE TABLE IF NOT EXISTS order_telegram_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT, dedup_key TEXT NOT NULL UNIQUE,
        order_id TEXT NOT NULL REFERENCES checkout_orders(id), event TEXT NOT NULL,
        chat_id TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'pending\',
        attempts INTEGER NOT NULL DEFAULT 0, available_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL, sent_at INTEGER, lease TEXT
    ); CREATE INDEX IF NOT EXISTS order_telegram_pending ON order_telegram_queue(status,available_at);');
    $ready = true;
}

function mahara_telegram_schedule(string $id, string $event, string $occurrence = ''): bool {
    if (!in_array($event, ['order_created', 'payment_paid', 'refund_completed', 'order_shipped', 'order_completed', 'order_tracking_updated'], true) || !mahara_telegram_ready()) return false;
    mahara_telegram_schema();
    if (!mahara_query('SELECT id FROM checkout_orders WHERE id=?', [$id])->fetchColumn()) return false;
    $key = hash('sha256', json_encode([$id, $event, $occurrence], JSON_THROW_ON_ERROR));
    return mahara_query('INSERT OR IGNORE INTO order_telegram_queue(dedup_key,order_id,event,chat_id,available_at,created_at) VALUES(?,?,?,?,?,?)', [$key, $id, $event, mahara_telegram_config()['chat_id'], time(), time()])->rowCount() === 1;
}

function mahara_telegram_text(mixed $value, int $length): string {
    if (!is_string($value)) return '';
    $text = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', ' ', $value);
    if ($text === null || !preg_match('/^.{0,' . $length . '}/us', $text, $match)) return '';
    return $match[0];
}

function mahara_telegram_bounded_text(mixed $value, int $bytes): string {
    $text = trim(mahara_telegram_text($value, $bytes));
    if (strlen($text) <= $bytes) return $text;
    $text = substr($text, 0, $bytes - 3);
    while (preg_match('//u', $text) !== 1) $text = substr($text, 0, -1);
    return $text . '…';
}

function mahara_telegram_message(array $job, array $row): string {
    $labels = ['order_created' => 'Заказ создан', 'payment_paid' => 'Оплата подтверждена', 'refund_completed' => 'Возврат денег подтверждён', 'order_shipped' => 'Заказ передан в доставку', 'order_completed' => 'Заказ завершён', 'order_tracking_updated' => 'Номер отправления обновлён'];
    $statuses = ['payment_pending' => 'ожидает оплаты', 'paid' => 'оплачен', 'refund_pending' => 'возврат в обработке', 'refunded' => 'деньги возвращены', 'partial_refunded' => 'частичный возврат', 'canceled' => 'отменён', 'expired' => 'срок оплаты истёк', 'failed' => 'ошибка оплаты'];
    $fulfillment = ['processing' => 'в обработке', 'shipped' => 'отправлен', 'completed' => 'завершён'];
    $number = mahara_telegram_bounded_text($row['number'], 100);
    $text = 'MAHARA — ' . ($labels[$job['event']] ?? 'Информация о заказе') . "\nНомер заказа: " . $number;
    $text .= "\nСтатус оплаты: " . ($statuses[$row['status']] ?? 'требует проверки');
    $text .= "\nСтатус отправки: " . ($fulfillment[$row['fulfillment_status']] ?? 'требует проверки') . "\n\nТовары:\n";
    $items = json_decode($row['items_json'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($items)) throw new MaharaTelegramDeliveryError();
    $records = [];
    foreach (['customer', 'delivery'] as $key) {
        $json = $row[$key . '_json'] ?? '{}';
        $record = is_string($json) ? json_decode($json, true, 16) : null;
        $records[$key] = is_array($record) && !array_is_list($record) ? $record : [];
    }
    $customer = $records['customer']; $delivery = $records['delivery'];
    $details = "\nПолучатель: " . (mahara_telegram_bounded_text($customer['name'] ?? null, 240) ?: 'не указан');
    $details .= "\nТелефон получателя: " . (mahara_telegram_bounded_text($customer['phone'] ?? null, 40) ?: 'не указан');
    $details .= "\nГород доставки: " . (mahara_telegram_bounded_text($delivery['city'] ?? null, 240) ?: 'не указан');
    $point = mahara_telegram_bounded_text($delivery['point'] ?? null, 100);
    if ($point !== '') $details .= (is_string($delivery['quoteId'] ?? null) && $delivery['quoteId'] !== '' ? "\nПВЗ СДЭК (код): " : "\nПункт выдачи: ") . $point;
    $address = mahara_telegram_bounded_text($delivery['address'] ?? null, 600);
    $details .= "\nАдрес ПВЗ / доставки: " . ($address !== '' ? $address : 'не указан');
    $label = mahara_telegram_bounded_text($delivery['label'] ?? null, 200);
    if ($label !== '' && $label !== $address) $details .= "\nДоставка: " . $label;
    $footer = "\n\nСумма заказа: " . number_format((int)$row['total_amount'] / 100, 2, ',', ' ') . " ₽.\n";
    if (in_array($job['event'], ['order_shipped', 'order_completed', 'order_tracking_updated'], true) && $row['tracking_number'] !== '') $footer .= 'Номер отправления: ' . mahara_telegram_text($row['tracking_number'], 120) . "\n";
    // This is an authenticated admin search, never a customer bearer link.
    $footer .= "\nПанель магазина: " . mahara_config()['origin'] . '/orders.html?q=' . rawurlencode($number);
    $omitted = 'Ещё позиций: ' . count($items) . ". Полный состав — в панели магазина.\n";
    $shown = 0;
    foreach ($items as $item) {
        $line = mahara_telegram_text($item['title'] ?? '', 120) . ' · ' . mahara_telegram_text($item['size'] ?? '', 20) . ' × ' . max(0, (int)($item['qty'] ?? 0)) . "\n";
        // A byte budget is stricter than Telegram's 4096-character limit.
        if (strlen($text . $line . $omitted . $details . $footer) > 4000) break;
        $text .= $line; $shown++;
    }
    if ($shown < count($items)) $text .= 'Ещё позиций: ' . (count($items) - $shown) . ". Полный состав — в панели магазина.\n";
    return $text . $details . $footer;
}

function mahara_telegram_send(string $chatId, string $text): array {
    if (!mahara_telegram_chat_valid($chatId) || $text === '' || strlen($text) > 4096) throw new MaharaTelegramDeliveryError();
    $payload = ['chat_id' => $chatId, 'text' => $text, 'link_preview_options' => ['is_disabled' => true]];
    try {
        if (defined('MAHARA_LIBRARY_ONLY') && is_callable($GLOBALS['mahara_telegram_test_transport'] ?? null)) {
            $response = ($GLOBALS['mahara_telegram_test_transport'])('sendMessage', $payload);
        } else {
            $token = mahara_telegram_secrets()['token'] ?? '';
            if (!is_string($token) || !preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,128}$/D', $token) || !function_exists('curl_init')) throw new MaharaTelegramDeliveryError();
            $body = ''; $tooLarge = false;
            $curl = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
            if ($curl === false) throw new MaharaTelegramDeliveryError();
            try {
                curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                        if (strlen($body) + strlen($chunk) > 65536) { $tooLarge = true; return 0; }
                        $body .= $chunk; return strlen($chunk);
                    }]);
                $ok = @curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            } finally { curl_close($curl); }
            if ($ok === false || $tooLarge || ($status !== 200 && $status !== 429)) throw new MaharaTelegramDeliveryError();
            $response = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if ($status === 429 && ($response['ok'] ?? null) !== false) throw new MaharaTelegramDeliveryError();
        }
        if (!is_array($response) || ($response['ok'] ?? null) !== true || !is_int($response['result']['message_id'] ?? null) || $response['result']['message_id'] <= 0) {
            $wait = is_array($response) && ($response['error_code'] ?? null) === 429 ? ($response['parameters']['retry_after'] ?? 0) : 0;
            throw new MaharaTelegramDeliveryError(is_int($wait) ? max(0, min(604800, $wait)) : 0);
        }
        return ['message_id' => $response['result']['message_id']];
    } catch (MaharaTelegramDeliveryError $error) { throw $error; }
    catch (Throwable) { throw new MaharaTelegramDeliveryError(); }
}

function mahara_telegram_work(int $limit = 3): array {
    if (!mahara_telegram_ready()) return ['enabled' => false, 'sent' => 0, 'failed' => 0];
    mahara_telegram_schema();
    $chatId = mahara_telegram_config()['chat_id']; $sent = 0; $failed = 0;
    mahara_query("UPDATE order_telegram_queue SET status='failed',lease=NULL WHERE chat_id=? AND status='sending' AND available_at<=? AND attempts>=8", [$chatId, time()]);
    for ($i = 0; $i < max(1, min(20, $limit)); $i++) {
        $db = mahara_db(); $db->exec('BEGIN IMMEDIATE');
        try {
            // A configuration change never silently forwards older jobs to a new chat.
            $job = mahara_query("SELECT * FROM order_telegram_queue WHERE chat_id=? AND status IN ('pending','sending') AND available_at<=? AND attempts<8 ORDER BY id LIMIT 1", [$chatId, time()])->fetch();
            if (!$job) { $db->exec('COMMIT'); break; }
            $lease = mahara_random_token();
            mahara_query("UPDATE order_telegram_queue SET status='sending',lease=?,attempts=attempts+1,available_at=? WHERE id=?", [$lease, time() + 600, $job['id']]);
            $db->exec('COMMIT');
        } catch (Throwable $error) { try { $db->exec('ROLLBACK'); } catch (Throwable) {} throw $error; }
        try {
            $row = mahara_query('SELECT number,items_json,customer_json,delivery_json,total_amount,status,fulfillment_status,tracking_number FROM checkout_orders WHERE id=?', [$job['order_id']])->fetch();
            if (!$row) throw new MaharaTelegramDeliveryError();
            mahara_telegram_send($job['chat_id'], mahara_telegram_message($job, $row));
            $sent += mahara_query("UPDATE order_telegram_queue SET status='sent',sent_at=?,lease=NULL WHERE id=? AND lease=? AND status='sending'", [time(), $job['id'], $lease])->rowCount();
        } catch (Throwable $error) {
            $delay = max(min(86400, 300 * (2 ** min(8, (int)$job['attempts']))), $error instanceof MaharaTelegramDeliveryError ? $error->retryAfter : 0);
            $failed += mahara_query("UPDATE order_telegram_queue SET status=CASE WHEN attempts>=8 THEN 'failed' ELSE 'pending' END,available_at=?,lease=NULL WHERE id=? AND lease=? AND status='sending'", [time() + $delay, $job['id'], $lease])->rowCount();
        }
    }
    return ['enabled' => true, 'sent' => $sent, 'failed' => $failed];
}
