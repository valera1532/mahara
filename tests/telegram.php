<?php
declare(strict_types=1);

// The child closes SQLite before the parent removes only its own fixture files.
if (($argv[1] ?? '') !== '--worker') {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mahara-telegram-' . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create Telegram fixture directory.');
    putenv('MAHARA_TELEGRAM_TEST_DIR=' . $directory);
    $status = 1;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start Telegram fixture process.');
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
        echo $output; if ($errors !== '') fwrite(STDERR, $errors);
    } finally {
        $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
        if ($resolved === false || $temporaryRoot === false || dirname($resolved) !== $temporaryRoot || !str_starts_with(basename($resolved), 'mahara-telegram-')) throw new RuntimeException('Unsafe Telegram fixture cleanup.');
        foreach (['mahara.sqlite', 'mahara.sqlite-wal', 'mahara.sqlite-shm', 'telegram-secrets.php', 'unexpected-secret-read'] as $name) {
            $file = $resolved . DIRECTORY_SEPARATOR . $name;
            if (is_file($file) && !is_link($file)) unlink($file);
        }
        rmdir($resolved);
    }
    exit($status);
}

$directory = getenv('MAHARA_TELEGRAM_TEST_DIR');
if (!$directory || !is_dir($directory) || !str_starts_with(basename($directory), 'mahara-telegram-')) throw new RuntimeException('Missing isolated Telegram fixture directory.');
$GLOBALS['mahara_app_test_config'] = [
    'root' => dirname(__DIR__), 'data_dir' => $directory, 'origin' => 'https://shop.example.test',
    'production' => false, 'trust_proxy' => false, 'session_ttl' => 28800,
    'login_window' => 900, 'login_pair_limit' => 8, 'login_ip_limit' => 40,
    'mail' => [], 'payments' => ['enabled' => false], 'shipping' => ['enabled' => false],
    'telegram' => ['enabled' => true, 'chat_id' => '-19999999999'],
];
$GLOBALS['mahara_mail_test_config'] = ['enabled' => false];
$GLOBALS['mahara_mail_test_secrets'] = ['username' => '', 'password' => ''];
$GLOBALS['mahara_mail_test_transport'] = static function (): never { throw new RuntimeException('This fixture must not send email.'); };
$GLOBALS['mahara_telegram_test_config'] = ['enabled' => false];
$GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => 'fixture-bank-key', 'password' => 'fixture-bank-password'];
$GLOBALS['mahara_payments_test_transport'] = static function (): never { throw new RuntimeException('This fixture must not contact a bank.'); };
$GLOBALS['mahara_shipping_test_secrets'] = ['account' => 'fixture-account', 'password' => 'fixture-password'];
$GLOBALS['mahara_shipping_test_transport'] = static function (): never { throw new RuntimeException('This fixture must not contact CDEK.'); };
define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/notifications.php';

$checks = 0; $messages = []; $transportMode = 'success'; $nestedSummary = null;
function telegram_verify(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function telegram_job(string $occurrence): array {
    $key = hash('sha256', json_encode(['fixture-order-1', 'order_tracking_updated', $occurrence], JSON_THROW_ON_ERROR));
    return mahara_query('SELECT * FROM order_telegram_queue WHERE dedup_key=?', [$key])->fetch();
}
function telegram_state(): array {
    $result = [];
    foreach (['checkout_orders', 'checkout_reservations', 'checkout_refund_attempts', 'inventory_meta', 'inventory_balances', 'inventory_movements'] as $table) $result[$table] = mahara_query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
    return $result;
}
$transport = static function (string $method, array $payload) use (&$messages, &$transportMode, &$nestedSummary): array {
    telegram_verify($method === 'sendMessage', 'Only sendMessage is used by the delivery worker.');
    telegram_verify(!mahara_db()->inTransaction(), 'Delivery is outside SQLite write transactions.');
    $messages[] = $payload;
    if ($transportMode === 'fail') throw new RuntimeException('Fixture secret must never escape: 123456:FAKE_TOKEN_DO_NOT_PRINT');
    if ($transportMode === 'rate-limit') return ['ok' => false, 'error_code' => 429, 'description' => 'Private provider response', 'parameters' => ['retry_after' => 1200]];
    if ($transportMode === 'bad-response') return ['ok' => true, 'result' => ['message_id' => 'invalid']];
    if ($transportMode === 'nested-worker') $nestedSummary = mahara_telegram_work(1);
    if ($transportMode === 'replace-lease') mahara_query("UPDATE order_telegram_queue SET lease='new-worker',available_at=? WHERE status='sending'", [time() + 600]);
    return ['ok' => true, 'result' => ['message_id' => count($messages), 'chat' => ['id' => -10012345], 'text' => 'Not returned to caller']];
};
$GLOBALS['mahara_telegram_test_transport'] = $transport;
file_put_contents($directory . '/telegram-secrets.php', '<?php file_put_contents(__DIR__ . "/unexpected-secret-read", "read"); throw new RuntimeException("Private fixture credentials must not be loaded.");');

telegram_verify(mahara_config()['data_dir'] === $directory, 'Tests use a complete isolated application configuration.');
telegram_verify(mahara_telegram_config() === ['enabled' => false, 'chat_id' => ''], 'Telegram fixture configuration completely replaces application Telegram settings.');
telegram_verify(!mahara_telegram_ready() && mahara_telegram_work() === ['enabled' => false, 'sent' => 0, 'failed' => 0], 'Disabled Telegram has no worker activity.');
telegram_verify(!mahara_telegram_schedule('missing', 'payment_paid'), 'Disabled Telegram never enqueues.');
mahara_telegram_schema(); mahara_notifications_schema();
$id = 'fixture-order-1'; $now = time();
$order = [
    'id' => $id, 'number' => 'MH-FIXTURE-1', 'request_id' => 'fixture-order-request', 'request_payload' => '{}', 'access_token' => 'PRIVATE-CUSTOMER-BEARER',
    'items_json' => json_encode([['id' => 'hoodie-black', 'title' => 'Тестовое худи', 'size' => 'M', 'qty' => 2, 'image' => 'assets/images/black-hoodie.webp', 'unitAmount' => 1690000, 'amount' => 3380000]], JSON_THROW_ON_ERROR),
    'customer_json' => json_encode(['name' => 'PRIVATE-CUSTOMER-NAME', 'phone' => '+79990000000', 'email' => 'private-customer@example.test'], JSON_THROW_ON_ERROR),
    'delivery_json' => json_encode(['city' => 'PRIVATE-CUSTOMER-CITY', 'point' => 'PRIVATE-PICKUP', 'address' => 'PRIVATE-CUSTOMER-ADDRESS', 'quoteId' => 'fixture-quote'], JSON_THROW_ON_ERROR),
    'total_amount' => 3380000, 'delivery_amount' => 0, 'status' => 'paid', 'bank_status' => 'CONFIRMED', 'terminal_key' => 'fixture-bank-key',
    'payment_id' => 'fixture-payment-1', 'payment_url' => 'https://bank.example.test/PRIVATE-PAYMENT-URL', 'qr_svg' => 'PRIVATE-QR', 'stock_applied' => 1,
    'expires_at' => $now + 900, 'last_checked' => $now, 'created_at' => $now, 'updated_at' => $now, 'tracking_number' => 'FIXTURE-TRACK-1',
];
mahara_query('INSERT INTO checkout_orders(' . implode(',', array_keys($order)) . ') VALUES(' . implode(',', array_fill(0, count($order), '?')) . ')', array_values($order));
mahara_query('INSERT INTO checkout_reservations(order_id,product_id,size,quantity) VALUES(?,?,?,?)', [$id, 'hoodie-black', 'M', 2]);
mahara_query('INSERT INTO inventory_balances(product_id,size,quantity,threshold,initialized,revision,product_snapshot,updated_at) VALUES(?,?,?,?,?,?,?,?)', ['hoodie-black', 'M', 8, 0, 1, 1, '{}', $now]);
$unchanged = telegram_state();

$GLOBALS['mahara_telegram_test_config'] = ['enabled' => true, 'chat_id' => '-10012345'];
telegram_verify(mahara_telegram_ready(), 'Fake Telegram is ready without consulting private secrets.');
foreach (['', '@guessed-channel', 12345, '0', "-10012345\n"] as $invalid) {
    $GLOBALS['mahara_telegram_test_config']['chat_id'] = $invalid;
    telegram_verify(!mahara_telegram_ready(), 'Only an explicitly configured numeric string chat ID is accepted.');
}
$GLOBALS['mahara_telegram_test_config']['chat_id'] = '-10012345';
unset($GLOBALS['mahara_telegram_test_transport']);
$GLOBALS['mahara_telegram_test_secrets'] = ['token' => ''];
telegram_verify(!mahara_telegram_ready() && !mahara_telegram_schedule($id, 'payment_paid'), 'Missing credentials disable real transport and queue scheduling.');
$GLOBALS['mahara_telegram_test_secrets'] = ['token' => 'invalid-token'];
telegram_verify(!mahara_telegram_ready(), 'Malformed credentials do not enable real transport.');
unset($GLOBALS['mahara_telegram_test_secrets']);
$GLOBALS['mahara_telegram_test_transport'] = $transport;
telegram_verify(!mahara_telegram_schedule($id, 'unknown') && !mahara_telegram_schedule('missing', 'payment_paid'), 'Unknown events and missing orders are ignored.');
mahara_order_notification_schedule($id, 'order_created');
mahara_order_notification_schedule($id, 'order_created');
telegram_verify((int)mahara_query('SELECT COUNT(*) FROM order_telegram_queue')->fetchColumn() === 1, 'Real notification wrapper deduplicates Telegram events when email is disabled.');
telegram_verify((int)mahara_query('SELECT COUNT(*) FROM order_mail_queue')->fetchColumn() === 0 && !mahara_mail_ready(), 'Telegram scheduling is independent of SMTP readiness.');
telegram_verify($messages === [], 'Scheduling never sends synchronously.');
telegram_verify(mahara_telegram_work() === ['enabled' => true, 'sent' => 1, 'failed' => 0], 'Worker delivers the queued event.');
$message = $messages[0];
telegram_verify($message['chat_id'] === '-10012345' && $message['link_preview_options'] === ['is_disabled' => true] && !isset($message['parse_mode']), 'Messages use the explicit chat, plain text and disabled previews.');
telegram_verify(str_contains($message['text'], 'Заказ создан') && str_contains($message['text'], 'Тестовое худи · M × 2') && str_contains($message['text'], '33 800,00') && str_contains($message['text'], 'Статус оплаты: оплачен'), 'Messages include the event, order items, size, quantity, amount and payment state.');
telegram_verify(str_contains($message['text'], '/orders.html?q=MH-FIXTURE-1') && !str_contains($message['text'], 'FIXTURE-TRACK-1'), 'Admin search link is authenticated and unrelated events omit tracking.');
telegram_verify(str_contains($message['text'], 'Получатель: PRIVATE-CUSTOMER-NAME') && str_contains($message['text'], 'Телефон получателя: +79990000000'), 'The owner alert includes the actual recipient name and phone.');
telegram_verify(str_contains($message['text'], 'Город доставки: PRIVATE-CUSTOMER-CITY') && str_contains($message['text'], 'ПВЗ СДЭК (код): PRIVATE-PICKUP') && str_contains($message['text'], 'Адрес ПВЗ / доставки: PRIVATE-CUSTOMER-ADDRESS'), 'The owner alert includes the persisted destination city, pickup code and address.');
foreach (['PRIVATE-CUSTOMER-BEARER', 'PRIVATE-PAYMENT-URL', 'PRIVATE-QR', 'private-customer@', 'fixture-bank', 'fixture-payment', 'fixture-quote', 'checkout.html', '#access='] as $forbidden) telegram_verify(!str_contains(json_encode($message, JSON_THROW_ON_ERROR), $forbidden), 'Telegram excludes customer email, access capabilities, payment details and credentials.');
$sent = mahara_query('SELECT * FROM order_telegram_queue')->fetch();
telegram_verify($sent['status'] === 'sent' && $sent['lease'] === null && $sent['sent_at'] !== null, 'Delivered queue entries retain deduplication and clear the lease.');
telegram_verify(!mahara_telegram_schedule($id, 'order_created') && mahara_telegram_work()['sent'] === 0 && count($messages) === 1, 'Completed jobs and repeated events are not redelivered.');
foreach (['payment_paid', 'refund_completed', 'order_shipped', 'order_completed', 'order_tracking_updated'] as $event) telegram_verify(mahara_telegram_schedule($id, $event), 'Each supported event can be scheduled.');
telegram_verify(mahara_telegram_work(20)['sent'] === 5, 'All remaining supported events are delivered.');
foreach (array_slice($messages, 3, 3) as $shipment) telegram_verify(str_contains($shipment['text'], 'FIXTURE-TRACK-1'), 'Shipping events include the current tracking number.');
telegram_verify(mahara_telegram_schedule($id, 'order_tracking_updated', 'audit-1') && !mahara_telegram_schedule($id, 'order_tracking_updated', 'audit-1') && mahara_telegram_schedule($id, 'order_tracking_updated', 'audit-2'), 'Different tracking occurrences survive deduplication, repeated occurrences do not.');
telegram_verify(mahara_telegram_work(20)['sent'] === 2, 'Distinct tracking occurrences are delivered.');

mahara_telegram_schedule($id, 'order_tracking_updated', 'retry'); $transportMode = 'fail';
ob_start(); $summary = mahara_telegram_work(1); $output = ob_get_clean();
$job = telegram_job('retry');
telegram_verify($summary === ['enabled' => true, 'sent' => 0, 'failed' => 1] && $output === '', 'Worker failures expose aggregate counts only.');
telegram_verify($job['status'] === 'pending' && (int)$job['attempts'] === 1 && (int)$job['available_at'] >= time() + 299 && $job['lease'] === null, 'Failed jobs release their lease and wait before retrying.');
$before = count($messages);
telegram_verify(mahara_telegram_work()['failed'] === 0 && count($messages) === $before, 'Backoff prevents immediate retry loops.');
mahara_query('UPDATE order_telegram_queue SET available_at=0 WHERE id=?', [$job['id']]); $transportMode = 'success';
telegram_verify(mahara_telegram_work(1)['sent'] === 1 && (int)telegram_job('retry')['attempts'] === 2, 'A due retry succeeds without creating another queue record.');

mahara_telegram_schedule($id, 'order_tracking_updated', 'rate'); $transportMode = 'rate-limit';
telegram_verify(mahara_telegram_work(1)['failed'] === 1 && (int)telegram_job('rate')['available_at'] >= time() + 1199, 'Telegram retry_after extends exponential backoff.');
mahara_query('UPDATE order_telegram_queue SET available_at=0 WHERE id=?', [telegram_job('rate')['id']]); $transportMode = 'success';
telegram_verify(mahara_telegram_work(1)['sent'] === 1, 'Rate-limited jobs remain recoverable.');

mahara_telegram_schedule($id, 'order_tracking_updated', 'lease'); $job = telegram_job('lease');
mahara_query("UPDATE order_telegram_queue SET status='sending',attempts=1,lease='active-worker',available_at=? WHERE id=?", [time() + 600, $job['id']]);
telegram_verify(mahara_telegram_work()['sent'] === 0, 'Another worker cannot claim an active lease.');
mahara_query('UPDATE order_telegram_queue SET available_at=0 WHERE id=?', [$job['id']]); $transportMode = 'nested-worker';
telegram_verify(mahara_telegram_work(1)['sent'] === 1 && $nestedSummary === ['enabled' => true, 'sent' => 0, 'failed' => 0], 'An expired lease is reclaimed once while a concurrent worker skips the new lease.');

mahara_telegram_schedule($id, 'order_tracking_updated', 'ownership'); $transportMode = 'replace-lease';
telegram_verify(mahara_telegram_work(1)['sent'] === 0 && telegram_job('ownership')['lease'] === 'new-worker' && telegram_job('ownership')['status'] === 'sending', 'A stale worker cannot finish a job owned by another lease.');
mahara_query('UPDATE order_telegram_queue SET available_at=0 WHERE id=?', [telegram_job('ownership')['id']]); $transportMode = 'success';
telegram_verify(mahara_telegram_work(1)['sent'] === 1, 'The current lease can be recovered after expiry.');

mahara_telegram_schedule($id, 'order_tracking_updated', 'exhausted'); $job = telegram_job('exhausted');
mahara_query('UPDATE order_telegram_queue SET attempts=7 WHERE id=?', [$job['id']]); $transportMode = 'fail';
telegram_verify(mahara_telegram_work(1)['failed'] === 1 && telegram_job('exhausted')['status'] === 'failed' && (int)telegram_job('exhausted')['attempts'] === 8, 'The eighth failed attempt is terminal.');
mahara_telegram_schedule($id, 'order_tracking_updated', 'abandoned'); $job = telegram_job('abandoned');
mahara_query("UPDATE order_telegram_queue SET status='sending',attempts=8,lease='abandoned',available_at=0 WHERE id=?", [$job['id']]);
$before = count($messages); mahara_telegram_work(1);
telegram_verify(telegram_job('abandoned')['status'] === 'failed' && telegram_job('abandoned')['lease'] === null && count($messages) === $before, 'An exhausted expired lease terminates without another network attempt.');

$transportMode = 'success'; mahara_telegram_schedule($id, 'order_tracking_updated', 'target');
$GLOBALS['mahara_telegram_test_config']['chat_id'] = '-10099999';
telegram_verify(mahara_telegram_work()['sent'] === 0 && telegram_job('target')['chat_id'] === '-10012345', 'Changing configuration never reroutes an existing job to a different chat.');
$GLOBALS['mahara_telegram_test_config'] = ['enabled' => false];
telegram_verify(mahara_telegram_work() === ['enabled' => false, 'sent' => 0, 'failed' => 0] && telegram_job('target')['status'] === 'pending', 'Disabling Telegram preserves pending jobs without sending.');
$GLOBALS['mahara_telegram_test_config'] = ['enabled' => true, 'chat_id' => '-10012345'];
telegram_verify(mahara_telegram_work()['sent'] === 1, 'Re-enabling the original target resumes its queue.');

$transportMode = 'fail';
try { mahara_telegram_send('-10012345', 'Synthetic transport failure'); throw new LogicException('Expected safe delivery exception.'); }
catch (MaharaTelegramDeliveryError $error) { telegram_verify($error->getMessage() === 'Telegram delivery unavailable.' && $error->getPrevious() === null, 'Transport exceptions and chained credentials are redacted.'); }
$transportMode = 'bad-response';
try { mahara_telegram_send('-10012345', 'Synthetic invalid response'); throw new LogicException('Expected invalid response failure.'); }
catch (MaharaTelegramDeliveryError $error) { telegram_verify($error->getMessage() === 'Telegram delivery unavailable.', 'Malformed successful responses are rejected safely.'); }
$transportMode = 'success';
telegram_verify(array_keys(mahara_telegram_send('-10012345', 'Synthetic CLI proof')) === ['message_id'], 'CLI delivery proof contains only the message ID.');
$long = $order + ['fulfillment_status' => 'processing'];
$long['items_json'] = json_encode(array_fill(0, 100, ['title' => str_repeat('Очень длинное название ', 12), 'size' => 'XXL', 'qty' => 999]), JSON_THROW_ON_ERROR);
$long['customer_json'] = json_encode(['name' => str_repeat('Я😀', 500), 'phone' => str_repeat('9', 200), 'email' => 'private-long@example.test'], JSON_THROW_ON_ERROR);
$long['delivery_json'] = json_encode(['city' => str_repeat('Я😀', 500), 'point' => str_repeat('ПВЗ', 500), 'address' => str_repeat('Адрес 😀 ', 500), 'label' => str_repeat('Пункт 😀 ', 500)], JSON_THROW_ON_ERROR);
$text = mahara_telegram_message(['event' => 'order_shipped'], $long);
telegram_verify(strlen($text) <= 4096 && preg_match('//u', $text) === 1 && str_contains($text, 'Ещё позиций:') && str_contains($text, 'Сумма заказа:') && str_contains($text, '/orders.html?q=') && str_contains($text, 'Адрес ПВЗ / доставки:') && str_contains($text, '…'), 'Large orders and long delivery data keep bounded UTF-8, destination, totals and the admin link.');
telegram_verify(!str_contains($text, 'private-long@example.test'), 'Even long owner alerts omit customer email.');
$malformed = $order + ['fulfillment_status' => 'processing'];
foreach ([['{broken', 'null'], ['[]', '"not an object"'], [null, ['address' => 'not JSON']]] as [$customer, $delivery]) {
    $malformed['customer_json'] = $customer; $malformed['delivery_json'] = $delivery;
    $text = mahara_telegram_message(['event' => 'payment_paid'], $malformed);
    telegram_verify(str_contains($text, 'Получатель: не указан') && str_contains($text, 'Адрес ПВЗ / доставки: не указан') && str_contains($text, 'Тестовое худи'), 'Malformed or absent legacy contact data does not discard the order notification.');
}
$malformed['customer_json'] = json_encode(['name' => ['unexpected' => 'PRIVATE-NESTED'], 'phone' => 12345, 'email' => 'private-malformed@example.test'], JSON_THROW_ON_ERROR);
$malformed['delivery_json'] = json_encode(['city' => true, 'point' => ['code' => 'PRIVATE-NESTED'], 'address' => new stdClass(), 'label' => ['PRIVATE-NESTED']], JSON_THROW_ON_ERROR);
$text = mahara_telegram_message(['event' => 'payment_paid'], $malformed);
telegram_verify(str_contains($text, 'Получатель: не указан') && !str_contains($text, 'PRIVATE-NESTED') && !str_contains($text, '12345') && !str_contains($text, 'private-malformed@'), 'Malformed field types are never coerced or dumped into the owner message.');
$malformed['customer_json'] = json_encode(['name' => "Иван\r\nПодмена\u{202e}", 'phone' => "+79990000000\t"], JSON_THROW_ON_ERROR);
$malformed['delivery_json'] = json_encode(['city' => "Москва\u{2028}Город", 'point' => "MSK1\0", 'address' => 'г. Москва, ул. Тестовая, д. 1', 'label' => 'Тестовый пункт выдачи', 'quoteId' => 'fixture-quote'], JSON_THROW_ON_ERROR);
$text = mahara_telegram_message(['event' => 'payment_paid'], $malformed);
telegram_verify(str_contains($text, 'Получатель: Иван  Подмена') && !str_contains($text, "\nПодмена") && !preg_match('/[\p{Cf}\p{Zl}\p{Zp}\x00\r\t]/u', $text), 'Customer-controlled fields cannot inject new lines or hidden direction controls.');
telegram_verify(str_contains($text, 'ПВЗ СДЭК (код): MSK1') && str_contains($text, 'Доставка: Тестовый пункт выдачи'), 'Legacy destination labels are included as bounded plain text when available.');
$malformed['customer_json'] = '{}';
$malformed['delivery_json'] = json_encode(['city' => 'Москва', 'address' => 'Одинаковый адрес', 'label' => 'Одинаковый адрес'], JSON_THROW_ON_ERROR);
$text = mahara_telegram_message(['event' => 'payment_paid'], $malformed);
telegram_verify(substr_count($text, 'Одинаковый адрес') === 1 && !str_contains($text, 'ПВЗ СДЭК (код):'), 'Address-only legacy delivery is shown without inventing a pickup code or duplicating its label.');
$malformed['delivery_json'] = json_encode(['point' => 'Самовывоз из магазина', 'address' => 'Тестовый адрес'], JSON_THROW_ON_ERROR);
$text = mahara_telegram_message(['event' => 'payment_paid'], $malformed);
telegram_verify(str_contains($text, 'Пункт выдачи: Самовывоз из магазина') && !str_contains($text, 'СДЭК'), 'A manual legacy pickup is not misrepresented as a verified CDEK point.');
telegram_verify(!file_exists($directory . '/unexpected-secret-read'), 'Fake transport and complete overrides never read the fixture secret file.');
telegram_verify(telegram_state() === $unchanged, 'Notifications do not change orders, inventory, reservations or refund records.');
$queue = json_encode(mahara_query('SELECT * FROM order_telegram_queue')->fetchAll(), JSON_THROW_ON_ERROR);
telegram_verify(!str_contains($queue, 'PRIVATE-') && !str_contains($queue, 'TOKEN') && !str_contains($queue, 'example.test'), 'The durable queue stores no message bodies, customer contacts or credentials.');
echo 'PASS: ' . $checks . " Telegram checks; isolated database and fake transport only.\n";
