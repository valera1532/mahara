<?php
declare(strict_types=1);

require_once __DIR__ . '/payments.php';

final class MaharaTelegramDeliveryError extends RuntimeException {
    public function __construct(public readonly int $retryAfter = 0) {
        parent::__construct('Telegram delivery unavailable.');
    }
}

function mahara_telegram_config(): array {
    $defaults = ['enabled' => false, 'chat_id' => '', 'allowed_usernames' => [], 'bot_username' => 'MaharaRussia_bot'];
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

function mahara_telegram_allowed_usernames(): array {
    $values = mahara_telegram_config()['allowed_usernames']; $names = [];
    if (!is_array($values) || !array_is_list($values)) return [];
    foreach (array_slice($values, 0, 20) as $value) {
        if (is_string($value) && preg_match('/^[a-zA-Z0-9_]{5,32}$/D', $value)) $names[] = strtolower($value);
    }
    return array_values(array_unique($names));
}

function mahara_telegram_ready(): bool {
    $config = mahara_telegram_config();
    $canSubscribe = mahara_telegram_allowed_usernames() !== [] && is_string($config['bot_username']) && preg_match('/^[a-zA-Z0-9_]{5,32}$/D', $config['bot_username']);
    if ($config['enabled'] !== true || (!mahara_telegram_chat_valid($config['chat_id']) && !$canSubscribe)) return false;
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
    ); CREATE INDEX IF NOT EXISTS order_telegram_pending ON order_telegram_queue(status,available_at);
    CREATE TABLE IF NOT EXISTS order_telegram_subscribers (
        chat_id TEXT PRIMARY KEY, username TEXT NOT NULL DEFAULT \'\', active INTEGER NOT NULL CHECK(active IN (0,1)), updated_at INTEGER NOT NULL
    ); CREATE TABLE IF NOT EXISTS order_telegram_state (name TEXT PRIMARY KEY,value TEXT NOT NULL);');
    $ready = true;
}

function mahara_telegram_subscribers(): array {
    mahara_telegram_schema(); $config = mahara_telegram_config();
    if (mahara_telegram_chat_valid($config['chat_id'])) {
        $db = mahara_db(); $db->exec('BEGIN IMMEDIATE');
        try {
            $seed = mahara_query("INSERT OR IGNORE INTO order_telegram_state(name,value) VALUES('legacy_seeded',?)", [$config['chat_id']]);
            if ($seed->rowCount() === 1) mahara_query("INSERT OR IGNORE INTO order_telegram_subscribers(chat_id,username,active,updated_at) VALUES(?,'',1,?)", [$config['chat_id'], time()]);
            $db->exec('COMMIT');
        } catch (Throwable $error) { try { $db->exec('ROLLBACK'); } catch (Throwable) {} throw $error; }
    }
    $allowed = mahara_telegram_allowed_usernames(); $ids = [];
    foreach (mahara_query('SELECT chat_id,username FROM order_telegram_subscribers WHERE active=1 ORDER BY chat_id')->fetchAll() as $subscriber) {
        if (mahara_telegram_chat_valid($subscriber['chat_id']) && (in_array($subscriber['username'], $allowed, true) || ($subscriber['username'] === '' && $subscriber['chat_id'] === $config['chat_id']))) $ids[] = $subscriber['chat_id'];
    }
    return $ids;
}

function mahara_telegram_poll(): void {
    $allowed = mahara_telegram_allowed_usernames(); $bot = mahara_telegram_config()['bot_username'];
    if ($allowed === [] || !is_string($bot) || !preg_match('/^[a-zA-Z0-9_]{5,32}$/D', $bot)) return;
    mahara_telegram_schema();
    $storedCursor = mahara_query("SELECT value FROM order_telegram_state WHERE name='update_cursor'")->fetchColumn();
    $cursor = $storedCursor === false ? -1 : (int)$storedCursor;
    $updates = mahara_telegram_request('getUpdates', ['offset' => $cursor + 1, 'limit' => 100, 'timeout' => 0, 'allowed_updates' => ['message']]);
    if (!array_is_list($updates) || count($updates) > 100) throw new MaharaTelegramDeliveryError();
    foreach ($updates as $update) if (!is_array($update) || !is_int($update['update_id'] ?? null) || $update['update_id'] < 0) throw new MaharaTelegramDeliveryError();
    usort($updates, static fn(array $a, array $b): int => $a['update_id'] <=> $b['update_id']);
    $db = mahara_db(); $db->exec('BEGIN IMMEDIATE');
    try {
        $storedCursor = mahara_query("SELECT value FROM order_telegram_state WHERE name='update_cursor'")->fetchColumn();
        $cursor = $storedCursor === false ? -1 : (int)$storedCursor;
        foreach ($updates as $update) {
            if ($update['update_id'] <= $cursor) continue;
            $cursor = $update['update_id']; $message = $update['message'] ?? null;
            if (!is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || ($message['from']['is_bot'] ?? null) !== false
                || !is_int($message['chat']['id'] ?? null) || $message['chat']['id'] <= 0 || ($message['from']['id'] ?? null) !== $message['chat']['id']
                || !is_string($message['from']['username'] ?? null) || !in_array(strtolower($message['from']['username']), $allowed, true) || !is_string($message['text'] ?? null)) continue;
            if (!preg_match('/^\/(start|stop)(?:@([a-zA-Z0-9_]{5,32}))?(?:\s[^\r\n]*)?$/D', trim($message['text']), $match)) continue;
            if (($match[2] ?? '') !== '' && strcasecmp($match[2], $bot) !== 0) continue;
            $chat = (string)$message['chat']['id']; $username = strtolower($message['from']['username']); $active = $match[1] === 'start' ? 1 : 0;
            // One current private account per allowed handle; never infer identities from a public chat.
            if ($active) mahara_query('UPDATE order_telegram_subscribers SET active=0,updated_at=? WHERE username=? AND chat_id<>?', [time(), $username, $chat]);
            mahara_query('INSERT INTO order_telegram_subscribers(chat_id,username,active,updated_at) VALUES(?,?,?,?) ON CONFLICT(chat_id) DO UPDATE SET username=excluded.username,active=excluded.active,updated_at=excluded.updated_at', [$chat, $username, $active, time()]);
        }
        mahara_query("INSERT INTO order_telegram_state(name,value) VALUES('update_cursor',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value", [(string)$cursor]);
        $db->exec('COMMIT');
    } catch (Throwable $error) { try { $db->exec('ROLLBACK'); } catch (Throwable) {} throw $error; }
}

function mahara_telegram_schedule(string $id, string $event, string $occurrence = ''): bool {
    if (!in_array($event, ['order_created', 'payment_paid', 'refund_completed', 'order_shipped', 'order_completed', 'order_tracking_updated'], true) || !mahara_telegram_ready()) return false;
    mahara_telegram_schema();
    if (!mahara_query('SELECT id FROM checkout_orders WHERE id=?', [$id])->fetchColumn()) return false;
    $legacyKey = hash('sha256', json_encode([$id, $event, $occurrence], JSON_THROW_ON_ERROR)); $inserted = 0;
    foreach (mahara_telegram_subscribers() as $chat) {
        $key = hash('sha256', json_encode([$legacyKey, $chat], JSON_THROW_ON_ERROR));
        $inserted += mahara_query('INSERT OR IGNORE INTO order_telegram_queue(dedup_key,order_id,event,chat_id,available_at,created_at) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM order_telegram_queue WHERE dedup_key=? AND chat_id=?)', [$key, $id, $event, $chat, time(), time(), $legacyKey, $chat])->rowCount();
    }
    return $inserted > 0;
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

function mahara_telegram_request(string $method, array $payload): array {
    if (!in_array($method, ['sendMessage', 'getUpdates'], true)) throw new MaharaTelegramDeliveryError();
    try {
        if (defined('MAHARA_LIBRARY_ONLY') && is_callable($GLOBALS['mahara_telegram_test_transport'] ?? null)) {
            $response = ($GLOBALS['mahara_telegram_test_transport'])($method, $payload);
        } else {
            $token = mahara_telegram_secrets()['token'] ?? '';
            if (!is_string($token) || !preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,128}$/D', $token) || !function_exists('curl_init')) throw new MaharaTelegramDeliveryError();
            $body = ''; $tooLarge = false; $maximumBytes = $method === 'getUpdates' ? 4194304 : 65536;
            $curl = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
            if ($curl === false) throw new MaharaTelegramDeliveryError();
            try {
                curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maximumBytes): int {
                        if (strlen($body) + strlen($chunk) > $maximumBytes) { $tooLarge = true; return 0; }
                        $body .= $chunk; return strlen($chunk);
                    }]);
                $ok = @curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            } finally { curl_close($curl); }
            if ($ok === false || $tooLarge || ($status !== 200 && $status !== 429)) throw new MaharaTelegramDeliveryError();
            $response = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if ($status === 429 && ($response['ok'] ?? null) !== false) throw new MaharaTelegramDeliveryError();
        }
        if (!is_array($response) || ($response['ok'] ?? null) !== true || !is_array($response['result'] ?? null)) {
            $wait = is_array($response) && ($response['error_code'] ?? null) === 429 ? ($response['parameters']['retry_after'] ?? 0) : 0;
            throw new MaharaTelegramDeliveryError(is_int($wait) ? max(0, min(604800, $wait)) : 0);
        }
        return $response['result'];
    } catch (MaharaTelegramDeliveryError $error) { throw $error; }
    catch (Throwable) { throw new MaharaTelegramDeliveryError(); }
}

function mahara_telegram_send(string $chatId, string $text): array {
    if (!mahara_telegram_chat_valid($chatId) || $text === '' || strlen($text) > 4096) throw new MaharaTelegramDeliveryError();
    $result = mahara_telegram_request('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'link_preview_options' => ['is_disabled' => true]]);
    if (!is_int($result['message_id'] ?? null) || $result['message_id'] <= 0) throw new MaharaTelegramDeliveryError();
    return ['message_id' => $result['message_id']];
}

function mahara_telegram_work(int $limit = 3): array {
    if (!mahara_telegram_ready()) return ['enabled' => false, 'sent' => 0, 'failed' => 0];
    mahara_telegram_schema();
    mahara_telegram_subscribers();
    try { mahara_telegram_poll(); }
    catch (Throwable) { error_log('MAHARA Telegram subscription polling failed.'); }
    $sent = 0; $failed = 0;
    mahara_query("UPDATE order_telegram_queue SET status='failed',lease=NULL WHERE status='sending' AND available_at<=? AND attempts>=8", [time()]);
    for ($i = 0; $i < max(1, min(20, $limit)); $i++) {
        $subscribers = mahara_telegram_subscribers();
        if ($subscribers === []) break;
        $db = mahara_db(); $db->exec('BEGIN IMMEDIATE');
        try {
            $marks = implode(',', array_fill(0, count($subscribers), '?'));
            $job = mahara_query("SELECT q.* FROM order_telegram_queue q JOIN order_telegram_subscribers s ON s.chat_id=q.chat_id AND s.active=1 WHERE q.chat_id IN ($marks) AND q.status IN ('pending','sending') AND q.available_at<=? AND q.attempts<8 ORDER BY q.id LIMIT 1", [...$subscribers, time()])->fetch();
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
