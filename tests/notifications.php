<?php
declare(strict_types=1);

// Every process uses a unique fixture DB and a complete app configuration
// override. Only an in-memory fake transport may send; no private files/network.
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--worker', '--cli'], true)) {
    $privateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mahara-notifications-' . bin2hex(random_bytes(12));
    if (!mkdir($privateDir, 0700)) throw new RuntimeException('Cannot create notification fixture directory.');
    putenv('MAHARA_NOTIFICATIONS_TEST_DIR=' . $privateDir);
    $status = 1;
    try {
        foreach (['--worker', '--cli'] as $childMode) {
            $process = proc_open([PHP_BINARY, __FILE__, $childMode], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new RuntimeException('Cannot start notification fixture process.');
            fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
            if ($errors !== '') fwrite(STDERR, $errors);
            if ($status !== 0) { echo $output; break; }
            if ($childMode === '--cli') {
                $summary = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
                if ($summary !== ['enabled' => true, 'sent' => 0, 'failed' => 0]) throw new RuntimeException('CLI must emit only aggregate counts for the empty queue.');
                echo "PASS: isolated mail CLI emits aggregate counts only\n";
            } else echo $output;
        }
    } finally {
        $resolved = realpath($privateDir); $temporaryRoot = realpath(sys_get_temp_dir());
        if ($resolved === false || $temporaryRoot === false || dirname($resolved) !== $temporaryRoot || !str_starts_with(basename($resolved), 'mahara-notifications-')) throw new RuntimeException('Unsafe notification fixture cleanup.');
        foreach (['mahara.sqlite', 'mahara.sqlite-wal', 'mahara.sqlite-shm'] as $name) {
            $file = $resolved . DIRECTORY_SEPARATOR . $name;
            if (is_file($file) && !is_link($file)) unlink($file);
        }
        rmdir($resolved);
    }
    exit($status);
}

$fixtureDir = getenv('MAHARA_NOTIFICATIONS_TEST_DIR');
if (!$fixtureDir || !is_dir($fixtureDir) || !str_starts_with(basename($fixtureDir), 'mahara-notifications-')) throw new RuntimeException('Missing isolated notification fixture directory.');
$GLOBALS['mahara_app_test_config'] = [
    'root' => dirname(__DIR__), 'data_dir' => $fixtureDir, 'origin' => 'https://shop.example.test',
    'production' => false, 'trust_proxy' => false, 'session_ttl' => 28800,
    'login_window' => 900, 'login_pair_limit' => 8, 'login_ip_limit' => 40,
    'mail' => [], 'payments' => ['enabled' => false], 'shipping' => ['enabled' => false],
];
$GLOBALS['mahara_mail_test_config'] = ['enabled' => true, 'host' => 'smtp.example.test', 'port' => 465, 'encryption' => 'tls', 'from' => 'orders@example.test', 'from_name' => 'MAHARA Fixture', 'shop_email' => 'shop@example.test'];
$GLOBALS['mahara_mail_test_secrets'] = ['username' => 'fixture-mail-user', 'password' => 'fixture-mail-password'];
$GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => 'fixture-bank-key', 'password' => 'fixture-bank-password'];
$GLOBALS['mahara_payments_test_transport'] = static function (): never { throw new RuntimeException('A notification test must never contact a bank.'); };
$GLOBALS['mahara_shipping_test_secrets'] = ['account' => 'fixture-account', 'password' => 'fixture-password'];
$GLOBALS['mahara_shipping_test_transport'] = static function (): never { throw new RuntimeException('A notification test must never contact CDEK.'); };
if ($mode === '--cli') {
    $GLOBALS['mahara_mail_test_transport'] = static function (): never { throw new RuntimeException('Empty fixture CLI must not send.'); };
    $argv = [dirname(__DIR__) . '/tools/notifications.php', 'send'];
    require $argv[0];
    exit;
}

define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/payments.php';
require_once dirname(__DIR__) . '/server/notifications.php';
$checks = 0; $messages = []; $transportMode = 'success';
function notification_verify(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function notification_error(int $status, callable $action, string $message): void {
    try { $action(); }
    catch (MaharaHttpError $error) { notification_verify($error->status === $status, $message . ': wrong HTTP status.'); return; }
    throw new RuntimeException($message . ': expected HTTP error.');
}
function notification_queue(string $event): array {
    return mahara_query('SELECT * FROM order_mail_queue WHERE event=? ORDER BY id', [$event])->fetchAll();
}
function notification_link_token(array $message): string {
    if (!preg_match('~https://shop\.example\.test/checkout\.html#access=([a-zA-Z0-9_-]{43})~', $message['body'], $matches)) throw new RuntimeException('Customer message needs a fragment capability.');
    return $matches[1];
}
function notification_state(): array {
    $state = [];
    foreach (['checkout_orders', 'checkout_reservations', 'checkout_refund_attempts', 'inventory_meta', 'inventory_balances', 'inventory_movements'] as $table) $state[$table] = mahara_query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
    return $state;
}
$GLOBALS['mahara_mail_test_transport'] = static function (string $recipient, string $subject, string $body, string $messageId) use (&$messages, &$transportMode): void {
    $messages[] = compact('recipient', 'subject', 'body', 'messageId');
    if ($transportMode === 'fail') throw new RuntimeException('Synthetic SMTP failure fixture-mail-password');
    if ($transportMode === 'replace-lease') mahara_query("UPDATE order_mail_queue SET lease='another-worker',available_at=? WHERE status='sending'", [time() + 600]);
};

notification_verify(mahara_config()['data_dir'] === $fixtureDir && mahara_config()['origin'] === 'https://shop.example.test', 'Complete test configuration overrides private files.');
mahara_notifications_schema();
$id = 'fixture-order-1'; $accessToken = mahara_random_token(); $now = time();
$order = [
    'id' => $id, 'number' => 'MH-FIXTURE-1', 'request_id' => 'fixture-order-request', 'request_payload' => '{}', 'access_token' => $accessToken,
    'items_json' => json_encode([['id' => 'hoodie-black', 'size' => 'M', 'qty' => 2, 'title' => 'Тестовое худи', 'image' => 'assets/images/black-hoodie.webp', 'unitAmount' => 1690000, 'amount' => 3380000]], JSON_THROW_ON_ERROR),
    'customer_json' => json_encode(['name' => 'Тестовый покупатель', 'phone' => '+79990000000', 'email' => 'Buyer@example.test'], JSON_THROW_ON_ERROR),
    'delivery_json' => json_encode(['city' => 'Москва', 'point' => 'FIXTURE1', 'address' => 'Тестовая улица, 1', 'quoteId' => 'fixture-quote'], JSON_THROW_ON_ERROR),
    'total_amount' => 3380000, 'delivery_amount' => 0, 'status' => 'paid', 'bank_status' => 'CONFIRMED', 'terminal_key' => 'fixture-bank-key',
    'payment_id' => 'fixture-payment-1', 'payment_url' => null, 'qr_svg' => null, 'stock_applied' => 1, 'expires_at' => $now + 900,
    'last_checked' => $now, 'created_at' => $now, 'updated_at' => $now, 'tracking_number' => 'FIXTURE-TRACK-1',
];
mahara_query('INSERT INTO checkout_orders(' . implode(',', array_keys($order)) . ') VALUES(' . implode(',', array_fill(0, count($order), '?')) . ')', array_values($order));
mahara_query('INSERT INTO checkout_reservations(order_id,product_id,size,quantity) VALUES(?,?,?,?)', [$id, 'hoodie-black', 'M', 2]);
mahara_query('INSERT INTO inventory_balances(product_id,size,quantity,threshold,initialized,revision,product_snapshot,updated_at) VALUES(?,?,?,?,?,?,?,?)', ['hoodie-black', 'M', 8, 0, 1, 1, '{}', $now]);
$unchanged = notification_state();

$GLOBALS['mahara_mail_test_config']['enabled'] = false;
notification_verify(!mahara_mail_ready(), 'Mail is disabled by explicit configuration.');
mahara_order_notification_schedule($id, 'order_created');
notification_verify(notification_queue('order_created') === [], 'Disabled mail does not enqueue order notifications.');
notification_error(503, static fn() => mahara_order_recovery_request(['number' => 'MH-FIXTURE-1', 'email' => 'Buyer@example.test']), 'Recovery requests are disabled without mail.');
notification_verify(mahara_notifications_work() === ['enabled' => false, 'sent' => 0, 'failed' => 0] && $messages === [], 'Disabled worker cannot send.');
$GLOBALS['mahara_mail_test_config']['enabled'] = true;
foreach (['host' => "smtp.example.test\r\nInjected", 'port' => 25, 'encryption' => 'none', 'from' => 'invalid-address', 'shop_email' => 'invalid-address'] as $field => $invalid) {
    $original = $GLOBALS['mahara_mail_test_config'][$field]; $GLOBALS['mahara_mail_test_config'][$field] = $invalid;
    notification_verify(!mahara_mail_ready(), 'Invalid ' . $field . ' disables mail before transport.');
    $GLOBALS['mahara_mail_test_config'][$field] = $original;
}
notification_verify(mahara_mail_ready(), 'Fully fake, valid fixture mail is ready.');
mahara_order_notification_schedule($id, 'unknown_event');
notification_verify((int)mahara_query('SELECT COUNT(*) FROM order_mail_queue')->fetchColumn() === 0, 'Unknown events are not queued.');
mahara_order_notification_schedule($id, 'order_created'); mahara_order_notification_schedule($id, 'order_created');
notification_verify(count(notification_queue('order_created')) === 2, 'An event is queued once for each customer/shop audience.');
notification_verify($messages === [], 'Web-side scheduling never sends synchronously.');
notification_verify(mahara_notifications_work(3) === ['enabled' => true, 'sent' => 2, 'failed' => 0], 'Worker sends both queued audiences.');
notification_verify(array_column($messages, 'recipient') === ['Buyer@example.test', 'shop@example.test'], 'Only stored customer and configured shop addresses receive mail.');
notification_verify(str_contains($messages[0]['body'], ".\nНомер заказа: MH-FIXTURE-1") && !str_contains($messages[0]['body'], '\\n'), 'Message line breaks are real newlines.');
notification_verify(str_contains($messages[0]['body'], '33 800,00') && str_contains($messages[0]['body'], 'оплачивается отдельно'), 'Goods total and separately paid CDEK delivery are described honestly.');
$createdToken = notification_link_token($messages[0]);
notification_verify(!str_contains($messages[0]['body'], $accessToken) && !str_contains($messages[0]['body'], '?access='), 'Long-lived order bearer is absent from email and recovery capability uses a fragment.');
notification_verify(!str_contains($messages[1]['body'], '#access=') && str_contains($messages[1]['body'], '/orders.html'), 'Shop mail contains only the authenticated admin page link.');
$storedToken = mahara_query('SELECT * FROM order_recovery_tokens')->fetch();
notification_verify($storedToken['token_hash'] === hash('sha256', $createdToken) && $storedToken['token_hash'] !== $createdToken, 'Recovery table stores only SHA-256 capability hashes.');
notification_verify((int)$storedToken['expires_at'] >= time() + 86390 && (int)$storedToken['expires_at'] <= time() + 86400, 'Recovery capability expires in 24 hours.');
notification_verify(count(array_filter(notification_queue('order_created'), static fn(array $job): bool => $job['status'] === 'sent' && $job['link_token'] === null && $job['lease'] === null && $job['sent_at'] !== null)) === 2, 'Sent jobs clear raw capability and lease.');
notification_verify(mahara_notifications_work(3)['sent'] === 0 && count($messages) === 2, 'A subsequent worker does not resend completed jobs.');
mahara_order_notification_schedule($id, 'order_created');
notification_verify(count(notification_queue('order_created')) === 2, 'Deduplication survives delivery.');

$generic = [];
foreach ([['number' => 'UNKNOWN', 'email' => 'Buyer@example.test'], ['number' => 'MH-FIXTURE-1', 'email' => 'attacker@example.test'], ['number' => 'MH-FIXTURE-1', 'email' => 'Buyer+alias@example.test'], ['number' => "MH-FIXTURE-1' OR 1=1--", 'email' => 'Buyer@example.test']] as $input) {
    ob_start(); $result = mahara_order_recovery_request($input); $generic[] = [$result, ob_get_clean()];
}
notification_verify(notification_queue('access_recovery') === [], 'Missing order, wrong email, email alias and SQL-like number never enqueue mail.');
ob_start(); $result = mahara_order_recovery_request(['number' => 'MH-FIXTURE-1', 'email' => 'buyer@EXAMPLE.test']); $generic[] = [$result, ob_get_clean()];
notification_verify(count(array_unique(array_map('serialize', $generic))) === 1 && $generic[0] === [null, ''], 'Matching and nonmatching recovery requests have identical void/no-output results.');
$recoveryJob = notification_queue('access_recovery')[0];
notification_verify($recoveryJob['recipient'] === 'Buyer@example.test' && $recoveryJob['audience'] === 'customer', 'Exact case-insensitive matching queues to the stored email only.');
mahara_order_recovery_request(['number' => 'MH-FIXTURE-1', 'email' => 'Buyer@example.test']);
notification_verify(count(notification_queue('access_recovery')) === 1, 'Repeated recovery requests within a 15-minute bucket deduplicate.');
notification_error(400, static fn() => mahara_order_recovery_request(['number' => 'MH-FIXTURE-1', 'email' => "Buyer@example.test\r\nBcc:x@example.test"]), 'Mail header injection is rejected.');
notification_error(400, static fn() => mahara_order_recovery_request(['number' => 'MH-FIXTURE-1', 'email' => 'Buyer@example.test', 'recipient' => 'attacker@example.test']), 'Recovery cannot override recipient.');
notification_verify(mahara_notifications_work(1)['sent'] === 1, 'Recovery queue is delivered by worker only.');
$recoveryToken = notification_link_token($messages[2]);
$view = mahara_order_recovery_exchange(['token' => $createdToken]);
notification_verify($view['accessToken'] === $accessToken && $view['order']['id'] === $id && $view['order']['status'] === 'paid', 'A valid one-use link exchanges for the private order access token.');
notification_verify(!isset($view['order']['customer']) && !isset($view['order']['bankStatus']) && !isset($view['order']['paymentId']), 'Recovery uses the customer view, without admin/provider details.');
notification_verify((int)mahara_query('SELECT used_at FROM order_recovery_tokens WHERE token_hash=?', [hash('sha256', $createdToken)])->fetchColumn() > 0, 'Successful exchange atomically marks the recovery link used.');
notification_error(404, static fn() => mahara_order_recovery_exchange(['token' => $createdToken]), 'Recovery link cannot be replayed.');
mahara_query('UPDATE order_recovery_tokens SET expires_at=? WHERE token_hash=?', [time() - 1, hash('sha256', $recoveryToken)]);
notification_error(404, static fn() => mahara_order_recovery_exchange(['token' => $recoveryToken]), 'Expired link cannot exchange.');
notification_verify(mahara_query('SELECT used_at FROM order_recovery_tokens WHERE token_hash=?', [hash('sha256', $recoveryToken)])->fetchColumn() === null, 'Expired exchange leaves the token unused.');
notification_error(404, static fn() => mahara_order_recovery_exchange(['token' => mahara_random_token()]), 'Unknown valid-shaped capability is rejected.');
notification_error(404, static fn() => mahara_order_recovery_exchange(['token' => '../../private']), 'Malformed capability is rejected.');
notification_error(400, static fn() => mahara_order_recovery_exchange(['token' => $createdToken, 'id' => $id]), 'Exchange cannot be retargeted to an arbitrary order.');

$GLOBALS['mahara_mail_test_config']['shop_email'] = '';
mahara_order_notification_schedule($id, 'order_shipped');
$transportMode = 'fail'; $beforeFailed = count($messages); ob_start(); $failure = mahara_notifications_work(1); $failureOutput = ob_get_clean();
notification_verify($failure === ['enabled' => true, 'sent' => 0, 'failed' => 1] && $failureOutput === '', 'Transport exceptions return counts without error details or secrets.');
$failedJob = notification_queue('order_shipped')[0]; $failedToken = notification_link_token($messages[$beforeFailed]);
notification_verify($failedJob['status'] === 'pending' && (int)$failedJob['attempts'] === 1 && $failedJob['lease'] === null && (int)$failedJob['available_at'] >= time() + 290, 'First failure releases lease and schedules bounded retry.');
notification_verify($failedJob['link_token'] === $failedToken, 'Retry retains the same private capability while it is valid.');
notification_verify(mahara_notifications_work(1)['failed'] === 0 && count($messages) === $beforeFailed + 1, 'Worker respects retry backoff.');
$GLOBALS['mahara_mail_test_config']['enabled'] = false;
mahara_query('UPDATE order_mail_queue SET available_at=? WHERE id=?', [time() - 1, $failedJob['id']]);
notification_verify(mahara_notifications_work(1)['enabled'] === false && count($messages) === $beforeFailed + 1, 'Disabled worker cannot send an already queued retry.');
$GLOBALS['mahara_mail_test_config']['enabled'] = true; $transportMode = 'success';
notification_verify(mahara_notifications_work(1)['sent'] === 1, 'A later successful retry completes the job.');
notification_verify(notification_link_token($messages[$beforeFailed + 1]) === $failedToken && $messages[$beforeFailed + 1]['messageId'] === $messages[$beforeFailed]['messageId'], 'Retries reuse capability and stable Message-ID.');
notification_verify(str_contains($messages[$beforeFailed + 1]['body'], 'Номер отправления: FIXTURE-TRACK-1'), 'Shipment mail includes the saved tracking number.');
notification_verify(str_contains($messages[$beforeFailed + 1]['body'], "https://www.cdek.ru/ru/tracking/\n") && notification_link_token($messages[$beforeFailed + 1]) === $failedToken, 'Shipment mail links to official CDEK tracking without invented query parameters and keeps private order access.');

mahara_order_notification_schedule($id, 'order_completed');
$leased = notification_queue('order_completed')[0];
$leaseToken = mahara_random_token();
mahara_query('INSERT INTO order_recovery_tokens(token_hash,order_id,expires_at) VALUES(?,?,?)', [hash('sha256', $leaseToken), $id, time() + 86400]);
mahara_query("UPDATE order_mail_queue SET status='sending',attempts=2,lease='active-worker',available_at=?,link_token=?,link_expires=? WHERE id=?", [time() + 600, $leaseToken, time() + 86400, $leased['id']]);
$beforeLease = count($messages);
notification_verify(mahara_notifications_work(1)['sent'] === 0 && count($messages) === $beforeLease, 'An active sending lease cannot be stolen.');
mahara_query('UPDATE order_mail_queue SET available_at=? WHERE id=?', [time() - 1, $leased['id']]);
notification_verify(mahara_notifications_work(1)['sent'] === 1 && notification_link_token($messages[$beforeLease]) === $leaseToken, 'An expired lease is recovered using its existing capability.');
notification_verify(str_contains($messages[$beforeLease]['body'], 'Номер отправления: FIXTURE-TRACK-1') && str_contains($messages[$beforeLease]['body'], "https://www.cdek.ru/ru/tracking/\n"), 'Completed-order mail also includes the tracking number and official tracking page.');

mahara_order_notification_schedule($id, 'refund_completed');
$exhausted = notification_queue('refund_completed')[0];
mahara_query('UPDATE order_mail_queue SET attempts=7,available_at=? WHERE id=?', [time() - 1, $exhausted['id']]);
$transportMode = 'fail'; notification_verify(mahara_notifications_work(1)['failed'] === 1, 'Eighth failed attempt is recorded.');
$exhausted = notification_queue('refund_completed')[0];
notification_verify($exhausted['status'] === 'failed' && (int)$exhausted['attempts'] === 8, 'Retry exhaustion becomes terminal failure.');
mahara_query("UPDATE order_mail_queue SET status='sending',lease='crashed-eighth-attempt',available_at=? WHERE id=?", [time() - 1, $exhausted['id']]);
$beforeExhausted = count($messages); $transportMode = 'success'; mahara_notifications_work(1);
$exhausted = notification_queue('refund_completed')[0];
notification_verify($exhausted['status'] === 'failed' && $exhausted['lease'] === null && count($messages) === $beforeExhausted, 'An expired eighth lease fails without a ninth delivery attempt.');

// No capability survives indefinitely; cleanup must not touch valid/just-expired links.
$old = mahara_random_token(); mahara_query('INSERT INTO order_recovery_tokens(token_hash,order_id,expires_at) VALUES(?,?,?)', [hash('sha256', $old), $id, time() - 86401]);
mahara_notifications_work(1);
notification_verify((int)mahara_query('SELECT COUNT(*) FROM order_recovery_tokens WHERE token_hash=?', [hash('sha256', $old)])->fetchColumn() === 0, 'Worker removes capabilities expired for over one day.');
notification_verify(notification_state() === $unchanged, 'Notifications/recovery do not mutate orders, payment/refund state, reservations or stock.');
notification_verify(!str_contains(json_encode($failure, JSON_THROW_ON_ERROR), $accessToken) && !str_contains(json_encode($failure, JSON_THROW_ON_ERROR), 'fixture-mail-password'), 'Worker return value contains no bearer or secret.');

// An old shipped order may have had no number, and its original shipment email
// already used the legacy dedup key. Adding/correcting the track must still mail.
mahara_query("UPDATE checkout_orders SET fulfillment_status='shipped',tracking_number='' WHERE id=?", [$id]);
foreach (['TRACK-A', 'TRACK-B', 'TRACK-A'] as $index => $tracking) {
    $beforeUpdate = count($messages);
    mahara_payments_fulfillment($id, ['status' => 'shipped', 'trackingNumber' => $tracking]);
    mahara_payments_fulfillment($id, ['status' => 'shipped', 'trackingNumber' => ' ' . $tracking . ' ']);
    notification_verify(count(notification_queue('order_tracking_updated')) === $index + 1, 'Each changed track queues once; repeated trimmed values do not queue another message.');
    notification_verify(count(notification_queue('order_shipped')) === 1, 'Tracking corrections preserve legacy shipment deduplication.');
    notification_verify(mahara_notifications_work(3)['sent'] === 1 && count($messages) === $beforeUpdate + 1, 'A correction produces one customer message, including returning to an earlier number.');
    $updated = $messages[$beforeUpdate];
    notification_verify(str_contains($updated['subject'], 'Номер отправления обновлён') && str_contains($updated['body'], 'Номер отправления: ' . $tracking) && str_contains($updated['body'], "https://www.cdek.ru/ru/tracking/\n") && strlen(notification_link_token($updated)) === 43, 'Correction message includes the current track, official CDEK page and private order link.');
}
mahara_query("UPDATE checkout_orders SET fulfillment_status='completed' WHERE id=?", [$id]);
mahara_payments_fulfillment($id, ['status' => 'completed', 'trackingNumber' => 'TRACK-C']);
notification_verify(count(notification_queue('order_tracking_updated')) === 4 && mahara_notifications_work(3)['sent'] === 1, 'Correcting a completed order also sends the current tracking number.');

foreach ([['completed', 'TRACK-D'], ['processing', 'TRACK-E']] as [$previousStatus, $tracking]) {
    $currentTracking = mahara_payments_row($id)['tracking_number'];
    mahara_payments_fulfillment($id, ['status' => $previousStatus, 'trackingNumber' => $currentTracking]);
    $beforeUpdate = count($messages); $beforeCorrections = count(notification_queue('order_tracking_updated'));
    mahara_payments_fulfillment($id, ['status' => 'shipped', 'trackingNumber' => $tracking]);
    mahara_payments_fulfillment($id, ['status' => 'shipped', 'trackingNumber' => $tracking]);
    notification_verify(count(notification_queue('order_tracking_updated')) === $beforeCorrections + 1 && count(notification_queue('order_shipped')) === 1, 'Returning to shipped with a changed track bypasses its already-delivered legacy status event exactly once.');
    notification_verify(mahara_notifications_work(3)['sent'] === 1 && count($messages) === $beforeUpdate + 1 && str_contains($messages[$beforeUpdate]['body'], 'Номер отправления: ' . $tracking), 'A status transition with a changed track delivers the current number after completed or processing.');
}
$firstShipment = array_replace($order, ['id' => 'fixture-order-2', 'number' => 'MH-FIXTURE-2', 'request_id' => 'fixture-order-request-2', 'payment_id' => 'fixture-payment-2', 'tracking_number' => '']);
mahara_query('INSERT INTO checkout_orders(' . implode(',', array_keys($firstShipment)) . ') VALUES(' . implode(',', array_fill(0, count($firstShipment), '?')) . ')', array_values($firstShipment));
$beforeCorrections = count(notification_queue('order_tracking_updated')); $beforeFirst = count($messages);
mahara_payments_fulfillment($firstShipment['id'], ['status' => 'shipped', 'trackingNumber' => 'FIRST-TRACK']);
mahara_payments_fulfillment($firstShipment['id'], ['status' => 'shipped', 'trackingNumber' => 'FIRST-TRACK']);
notification_verify(count(notification_queue('order_shipped')) === 2 && count(notification_queue('order_tracking_updated')) === $beforeCorrections && mahara_notifications_work(3)['sent'] === 1, 'The first shipment queues only its original status email, not a duplicate tracking correction.');
notification_verify(count($messages) === $beforeFirst + 1 && str_contains($messages[$beforeFirst]['subject'], 'Заказ передан в доставку') && str_contains($messages[$beforeFirst]['body'], 'Номер отправления: FIRST-TRACK'), 'The normal first-shipment message keeps its meaning and correct track.');
mahara_payments_fulfillment($firstShipment['id'], ['status' => 'processing', 'trackingNumber' => 'FIRST-TRACK']);
mahara_payments_fulfillment($firstShipment['id'], ['status' => 'shipped', 'trackingNumber' => 'FIRST-TRACK']);
notification_verify(mahara_notifications_work(3)['sent'] === 0 && count(notification_queue('order_tracking_updated')) === $beforeCorrections, 'Returning to shipped with the same tracking number does not send a duplicate.');

// Exercise the actual SMTP function with namespace-local stream primitives.
// No socket is opened. A server that accepts DATA then rejects QUIT must still
// count as success, otherwise the queue would deliver another copy on retry.
$reflection = new ReflectionFunction('mahara_smtp_send');
$sourceLines = file((string)$reflection->getFileName());
$smtpSource = implode('', array_slice($sourceLines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
eval('namespace MaharaNotificationSmtpFixture;' . $smtpSource . <<<'SMTP_FIXTURE'
function stream_socket_client($address, &$errorCode, &$errorText, $timeout, $flags, $context) {
    $GLOBALS['notification_smtp_fixture']['address'] = $address;
    $GLOBALS['notification_smtp_fixture']['context'] = \stream_context_get_options($context);
    return new \stdClass();
}
function stream_set_timeout($socket, $timeout) { return true; }
function fgets($socket, $length) { return array_shift($GLOBALS['notification_smtp_fixture']['responses']) ?? false; }
function fwrite($socket, $data) {
    $GLOBALS['notification_smtp_fixture']['writes'][] = $data;
    return $data === "QUIT\r\n" ? false : strlen($data);
}
function fclose($socket) { $GLOBALS['notification_smtp_fixture']['closed'] = true; return true; }
SMTP_FIXTURE);
$GLOBALS['notification_smtp_fixture'] = [
    'responses' => ["220 ready\r\n", "250 ehlo\r\n", "334 username\r\n", "334 password\r\n", "235 authenticated\r\n", "250 sender\r\n", "250 recipient\r\n", "354 data\r\n", "250 accepted\r\n"],
    'writes' => [], 'closed' => false,
];
$savedTransport = $GLOBALS['mahara_mail_test_transport']; unset($GLOBALS['mahara_mail_test_transport']);
try { \MaharaNotificationSmtpFixture\mahara_smtp_send('Buyer@example.test', 'Тестовое письмо', 'Только тестовая отправка.', 'fixture-message'); }
finally { $GLOBALS['mahara_mail_test_transport'] = $savedTransport; }
$smtp = $GLOBALS['notification_smtp_fixture'];
notification_verify($smtp['address'] === 'tls://smtp.example.test:465' && $smtp['context']['ssl']['verify_peer'] === true && $smtp['context']['ssl']['verify_peer_name'] === true && $smtp['context']['ssl']['allow_self_signed'] === false, 'SMTP uses TLS with certificate/hostname validation.');
notification_verify($smtp['responses'] === [] && end($smtp['writes']) === "QUIT\r\n" && $smtp['closed'] === true, 'Accepted DATA followed by failed QUIT returns success and closes the fake socket.');
notification_verify(count(array_filter($smtp['writes'], static fn(string $write): bool => str_contains($write, 'Content-Transfer-Encoding: base64'))) === 1, 'Failed QUIT never repeats the SMTP message body.');
notification_verify(str_contains($smtp['writes'][7], 'Message-ID: <fixture-message@shop.example.test>'), 'SMTP message uses the stable queue identifier and configured origin.');
echo 'PASS: ' . $checks . " isolated notification checks (disabled gate, deduplication, exact email, private one-use links, retries, leases, no order/stock changes)\n";
