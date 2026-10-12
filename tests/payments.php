<?php
declare(strict_types=1);

// Run: node tools/php.cjs tests/payments.php. No bank requests are made: the
// fixture transport is available only when MAHARA_LIBRARY_ONLY is defined.
$mode = $argv[1] ?? '';
if ($mode !== '--worker' && $mode !== '--race') {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mahara-payments-' . bin2hex(random_bytes(12));
    if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create payment test directory.');
    putenv('APP_ENV=development'); putenv('DATA_DIR=' . $dir); putenv('APP_ORIGIN=http://127.0.0.1:8765');
    $status = 1;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start payment tests.');
        fclose($pipes[0]); echo stream_get_contents($pipes[1]); fwrite(STDERR, stream_get_contents($pipes[2])); fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
    } finally {
        foreach (['mahara.sqlite', 'mahara.sqlite-wal', 'mahara.sqlite-shm'] as $name) if (is_file($dir . DIRECTORY_SEPARATOR . $name) && !is_link($dir . DIRECTORY_SEPARATOR . $name)) unlink($dir . DIRECTORY_SEPARATOR . $name);
        rmdir($dir);
    }
    exit($status);
}

define('MAHARA_LIBRARY_ONLY', true);
$GLOBALS['mahara_app_test_config'] = ['origin' => 'https://mahara.example.test']; // Do not load a shop's private config.
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/payments.php';
// Payment tests exercise the shipping hook without invoking CDEK's network.
function mahara_shipping_public_config(): array { return ['enabled' => true]; }
function mahara_shipping_validate(array $delivery, array $items): array {
    if (!empty($GLOBALS['mahara_shipping_fixture_expired']) || ($delivery['quoteId'] ?? '') !== 'fixture-shipping-quote') throw new MaharaHttpError(409, 'Расчёт доставки устарел.');
    return ['city' => 'Москва', 'point' => 'MSK-1', 'address' => 'Пункт выдачи', 'quoteId' => 'fixture-shipping-quote', 'shippingAmount' => 50000, 'tariffCode' => 136, 'estimated' => true];
}
$checks = 0;
function payment_verify(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function payment_error(int $status, callable $action, string $message): void {
    try { $action(); }
    catch (MaharaHttpError $error) { payment_verify($error->status === $status, $message . ': expected ' . $status . ', received ' . $error->status . ' ' . $error->getMessage()); return; }
    throw new RuntimeException($message . ': expected rejection.');
}
function payment_create_error(int $status, array $input, bool $expectedNotCreated, string $message): void {
    $notCreated = null;
    try { mahara_payments_create($input, $notCreated); }
    catch (MaharaHttpError $error) {
        payment_verify($error->status === $status && $notCreated === $expectedNotCreated, $message);
        return;
    }
    throw new RuntimeException($message . ': expected rejection.');
}
function payment_uuid(): string { static $n = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$n); }
function payment_request(string $id, string $size = 'S', int $quantity = 1): array {
    $items = [['id' => $id, 'size' => $size, 'qty' => $quantity]];
    return ['requestId' => payment_uuid(), 'items' => $items, 'expectedTotalAmount' => mahara_payments_quote($items)['totalAmount'],
        'customer' => ['name' => 'Покупатель', 'email' => 'buyer@example.test', 'phone' => '+7 (999) 123-45-67'],
        'delivery' => ['city' => 'Москва', 'point' => 'MSK-1', 'address' => 'Пункт выдачи', 'quoteId' => 'fixture-shipping-quote'], 'acceptedTerms' => true];
}
function payment_signed(array $order, string $status, ?int $amount = null): array {
    $reply = ['TerminalKey' => 'TEST-TERMINAL', 'PaymentId' => $order['payment_id'], 'OrderId' => $order['number'], 'Success' => true, 'ErrorCode' => '0', 'Status' => $status, 'Amount' => $amount ?? (int)$order['total_amount']];
    $reply['Token'] = mahara_tbank_token($reply, 'fixture-password'); return $reply;
}
function payment_balance(string $id, string $size = 'S'): int { return (int)mahara_query('SELECT quantity FROM inventory_balances WHERE product_id=? AND size=?', [$id, $size])->fetchColumn(); }
function payment_recheck(string $id): array { mahara_query('UPDATE checkout_orders SET last_checked=0 WHERE id=?', [$id]); return mahara_payments_reconcile($id); }

if ($mode === '--race') {
    $GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => 'TEST-TERMINAL', 'password' => 'fixture-password'];
    $GLOBALS['mahara_payments_test_config'] = ['enabled' => true, 'receipt_enabled' => true, 'taxation' => 'usn_income', 'item_tax' => 'none', 'delivery_mode' => 'cdek_receiver', 'terms_url' => 'https://merchant.example.test/terms', 'privacy_url' => 'https://merchant.example.test/privacy'];
    $input = json_decode(base64_decode($argv[2], true), true, 64, JSON_THROW_ON_ERROR);
    $GLOBALS['mahara_payments_test_transport'] = static function (string $method, array $payload): array {
        if ($method === 'Init') return ['Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'TEST-TERMINAL', 'OrderId' => $payload['OrderId'], 'PaymentId' => (string)(2000000000 + crc32($payload['OrderId'])), 'Amount' => $payload['Amount'], 'Status' => 'NEW'];
        if ($method === 'GetQr') return ['Success' => true, 'ErrorCode' => '0', 'PaymentId' => $payload['PaymentId'], 'Data' => $payload['DataType'] === 'PAYLOAD' ? 'https://qr.nspk.ru/' . $payload['PaymentId'] : '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10z"/></svg>'];
        throw new RuntimeException('Unexpected race fixture method.');
    };
    try { $result = mahara_payments_create($input); echo json_encode(['status' => 201, 'id' => $result['order']['id']], JSON_THROW_ON_ERROR); }
    catch (MaharaHttpError $error) { echo json_encode(['status' => $error->status], JSON_THROW_ON_ERROR); }
    exit;
}

// Published protocol fixture, including literal "true" and excluded objects.
$tokenFixture = ['TerminalKey' => '1234567890DEMO', 'OrderId' => '000000', 'Success' => true, 'Status' => 'AUTHORIZED', 'PaymentId' => '0000000', 'ErrorCode' => '0', 'Amount' => '1111', 'CardId' => '000000', 'Pan' => '200000******0000', 'ExpDate' => '1111', 'RebillId' => '000000'];
payment_verify(mahara_tbank_token($tokenFixture, '11111111111') === '1c0964277d0213349243065a0d5b838b8e90d2d25f740d0f2767836e710e80c8', 'Token matches official T-Bank SHA-256 fixture.');
payment_verify(mahara_tbank_token($tokenFixture + ['Receipt' => ['bad' => 'ignored'], 'DATA' => ['nested' => true], 'Token' => 'ignored', 'Optional' => null], '11111111111') === mahara_tbank_token($tokenFixture, '11111111111'), 'Nested data, null and Token are excluded from signature.');
payment_verify(mahara_tbank_token(['Success' => false], 'p') === hash('sha256', 'pfalse'), 'Boolean false is encoded literally.');
foreach (['http://qr.nspk.ru/abc', 'https://qr.nspk.ru.evil.test/abc', 'https://evil.test/tbank.ru', 'https://user@qr.nspk.ru/abc', 'https://qr.nspk.ru:443/abc', 'javascript:alert(1)', 'https://qr.nspk.ru/abc\\evil'] as $url) payment_verify(mahara_tbank_url($url) === null, 'Unsafe payment URL is rejected.');
payment_verify(mahara_tbank_url('https://qr.nspk.ru/abc?sum=1000') !== null && mahara_tbank_url('https://securepay.tinkoff.ru/abc') !== null, 'Known HTTPS provider domains are accepted.');
payment_verify(mahara_tbank_svg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') === null, 'Active SVG is rejected.');
payment_verify(mahara_tbank_svg('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>') === null, 'External XML entities are rejected.');
payment_verify(mahara_payments_legal_url('https://user@example.test/terms') === null, 'Legal URL cannot contain credentials.');

$GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => 'TEST-TERMINAL', 'password' => 'fixture-password'];
$GLOBALS['mahara_payments_test_config'] = ['enabled' => false, 'receipt_enabled' => true, 'taxation' => 'usn_income', 'item_tax' => 'none', 'delivery_mode' => 'cdek_receiver', 'terms_url' => 'https://merchant.example.test/terms', 'privacy_url' => 'https://merchant.example.test/privacy'];
$calls = []; $bank = []; $lostInit = false; $failMethod = ''; $unsafeQr = false;
$GLOBALS['mahara_payments_test_transport'] = static function (string $method, array $payload, string $url) use (&$calls, &$bank, &$lostInit, &$failMethod, &$unsafeQr): array {
    $calls[] = $method;
    payment_verify($url === 'https://securepay.tinkoff.ru/v2/' . $method, 'Transport target is fixed.');
    payment_verify($payload['Token'] === mahara_tbank_token($payload, 'fixture-password'), 'All API calls carry a correct token.');
    if ($failMethod === $method) throw new MaharaHttpError(502, 'Fixture transport timeout.');
    if ($method === 'Init') {
        $paymentId = (string)(100000 + count($bank));
        payment_verify($payload['PayType'] === 'O' && strlen($payload['OrderId']) <= 50 && $payload['Amount'] >= 1000, 'One-stage payment has safe identity and minimum amount.');
        payment_verify(array_sum(array_column($payload['Receipt']['Items'], 'Amount')) === $payload['Amount'], 'Receipt sum equals authoritative order amount.');
        payment_verify($payload['Receipt']['Items'][0]['PaymentMethod'] === 'full_prepayment' && $payload['Receipt']['Items'][0]['MeasurementUnit'] === 'шт' && $payload['Receipt']['FfdVersion'] === '1.2', 'Prepayment and FFD 1.2 receipt fields are sent.');
        $bank[$paymentId] = ['Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'TEST-TERMINAL', 'OrderId' => $payload['OrderId'], 'PaymentId' => $paymentId, 'Amount' => $payload['Amount'], 'Status' => 'NEW', 'PaymentURL' => 'https://securepay.tinkoff.ru/generic-card-form'];
        if ($lostInit) { $lostInit = false; throw new MaharaHttpError(502, 'Fixture response lost after bank created payment.'); }
        return $bank[$paymentId];
    }
    if ($method === 'CheckOrder') return ['Success' => true, 'ErrorCode' => '0', 'OrderId' => $payload['OrderId'], 'Payments' => array_values(array_filter($bank, static fn(array $payment): bool => $payment['OrderId'] === $payload['OrderId']))];
    $paymentId = $payload['PaymentId'];
    if (!isset($bank[$paymentId])) throw new RuntimeException('Unknown fixture payment.');
    if ($method === 'GetQr') {
        payment_verify($payload['PaymentMethod'] === 'SBP', 'QR explicitly requests SBP.');
        return ['Success' => true, 'ErrorCode' => '0', 'PaymentId' => $paymentId, 'Data' => $payload['DataType'] === 'IMAGE' ? '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="white"/><path d="M0 0h10v10H0z" fill="black"/></svg>' : ($unsafeQr ? 'https://attacker.example/pay' : 'https://qr.nspk.ru/' . $paymentId . '?type=02')];
    }
    if ($method === 'Cancel') {
        $bank[$paymentId]['Status'] = $bank[$paymentId]['Status'] === 'CONFIRMED' ? 'REFUNDED' : 'CANCELED';
        if ($bank[$paymentId]['Status'] === 'REFUNDED') $bank[$paymentId]['Amount'] = 0;
    }
    return $bank[$paymentId];
};
payment_verify(!mahara_payments_public_config()['enabled'], 'Checkout defaults to disabled.');
payment_error(503, static fn() => mahara_tbank_call('Init', []), 'Disabled payments never reach transport');
payment_verify($calls === [], 'No live or fixture transport called while disabled.');
payment_verify(mahara_payments_api('/api/content', 'GET') === false, 'Unrelated routes pass through.');
payment_error(405, static fn() => mahara_payments_api('/api/checkout/config', 'POST'), 'Known route rejects wrong method');
payment_error(401, static fn() => mahara_payments_api('/api/admin/orders', 'GET'), 'Admin list requires login');
payment_error(401, static fn() => mahara_payments_api('/api/admin/orders/' . str_repeat('a', 32) . '/refund', 'POST'), 'Refund requires login');

$document = mahara_content(); $product = $document['products'][0]; $product['sizes'] = ['S', 'M']; $product['price'] = 1900;
$document['products'] = [$product]; unset($document['revision']);
mahara_query('UPDATE content SET document=?,revision=revision+1 WHERE id=1', [json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
$id = $product['id'];
// Upgrade the schema of a shop with existing orders; never fabricate acceptance
// evidence for an order made before that evidence was recorded.
mahara_db()->exec('CREATE TABLE checkout_orders (
    id TEXT PRIMARY KEY, number TEXT NOT NULL UNIQUE, request_id TEXT NOT NULL UNIQUE,
    request_payload TEXT NOT NULL, access_token TEXT NOT NULL, items_json TEXT NOT NULL,
    customer_json TEXT NOT NULL, delivery_json TEXT NOT NULL, total_amount INTEGER NOT NULL,
    delivery_amount INTEGER NOT NULL, status TEXT NOT NULL, bank_status TEXT NOT NULL DEFAULT \'\',
    terminal_key TEXT NOT NULL, payment_id TEXT UNIQUE, payment_url TEXT, qr_svg TEXT,
    stock_applied INTEGER NOT NULL DEFAULT 0, expires_at INTEGER NOT NULL,
    last_checked INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
    fulfillment_status TEXT NOT NULL DEFAULT \'processing\', tracking_number TEXT NOT NULL DEFAULT \'\',
    refund_request_id TEXT UNIQUE, refund_requested_at INTEGER, receipt_status TEXT NOT NULL DEFAULT \'not_verified\'
)');
$legacyId = str_repeat('9', 32);
mahara_query('INSERT INTO checkout_orders(id,number,request_id,request_payload,access_token,items_json,customer_json,delivery_json,total_amount,delivery_amount,status,terminal_key,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$legacyId, 'LEGACY-ORDER', payment_uuid(), '[]', 'fixture-access', '[]', '{}', '{}', 190000, 0, 'failed', 'TEST-TERMINAL', 1, 1, 1]);
mahara_payments_schema();
$legacy = mahara_payments_view(mahara_payments_row($legacyId), true)['order'];
payment_verify($legacy['number'] === 'LEGACY-ORDER' && $legacy['acceptance'] === ['acceptedAt' => null, 'termsUrl' => '', 'privacyUrl' => '', 'legalVersion' => ''], 'Schema upgrade preserves old order and honestly leaves historic acceptance unknown.');
mahara_query('DELETE FROM checkout_orders WHERE id=?', [$legacyId]);
payment_error(409, static fn() => mahara_payments_quote([['id' => $id, 'size' => 'S', 'qty' => 1]]), 'Uninitialized stock cannot be sold');
foreach (['S', 'M'] as $size) mahara_inventory_movement(['requestId' => payment_uuid(), 'productId' => $id, 'size' => $size, 'type' => 'receipt', 'quantity' => 12, 'expectedRevision' => 0, 'note' => ''], 'fixture');
$request = payment_request($id, 'S', 2);
$quote = mahara_payments_quote($request['items']);
payment_verify($quote['totalAmount'] === 380000 && $quote['deliveryAmount'] === 0 && $quote['items'][0]['unitAmount'] === 190000, 'Quote uses server price in kopecks and receiver-paid delivery is not charged.');
payment_error(503, static fn() => mahara_payments_create($request), 'Disabled checkout creates no order');
payment_verify((int)mahara_query('SELECT COUNT(*) FROM checkout_orders')->fetchColumn() === 0, 'Disabled checkout persists no order.');
$GLOBALS['mahara_payments_test_config']['enabled'] = true;
payment_verify(mahara_payments_public_config()['enabled'], 'Complete settings allow checkout.');
$GLOBALS['mahara_payments_test_config']['item_tax'] = '';
payment_verify(!mahara_payments_public_config()['enabled'], 'Missing tax blocks activation.');
$GLOBALS['mahara_payments_test_config']['item_tax'] = 'vat20';
payment_verify(!mahara_payments_public_config()['enabled'], 'Obsolete VAT tax blocks activation.');
$GLOBALS['mahara_payments_test_config']['item_tax'] = 'none';
$GLOBALS['mahara_payments_test_config']['terms_url'] = '';
payment_verify(!mahara_payments_public_config()['enabled'], 'Missing real legal document configuration blocks activation.');
$GLOBALS['mahara_payments_test_config']['terms_url'] = 'https://merchant.example.test/terms';
foreach (['/terms.html', '/privacy.html?version=1', '/%74erms.html', '/./terms.html', '/a/../privacy.html', 'https://mahara.example.test/terms.html', 'https://mahara.example.test/a/%2e%2e/privacy.html', 'https://127.0.0.1/terms.html'] as $builtin) {
    $GLOBALS['mahara_payments_test_config']['terms_url'] = $builtin;
    // The HTTPS fixture is a different origin: only same-origin built-ins gate.
    payment_verify(mahara_payments_public_config()['enabled'] === str_starts_with($builtin, 'https://127.0.0.1'), 'Unpublished built-in legal documents cannot activate checkout: ' . $builtin);
}
payment_verify(mahara_payments_builtin_legal_url('https://mahara.example.test/terms.html') && mahara_payments_builtin_legal_url('https://mahara.example.test/%70rivacy.html?v=1'), 'Absolute same-origin built-in legal URLs are recognized.');
payment_verify(!mahara_payments_builtin_legal_url('https://other.example.test/terms.html'), 'External legal documents do not use the built-in draft gate.');
$GLOBALS['mahara_payments_test_config']['terms_url'] = 'https://merchant.example.test/terms';
$tampered = $request; $tampered['items'][0]['unitAmount'] = 1;
payment_error(400, static fn() => mahara_payments_create($tampered), 'Client price field is rejected');
$stale = $request; $stale['expectedTotalAmount'] = 370000;
payment_create_error(409, $stale, true, 'A stale total proves no order was created under the inventory lock.');
$unavailable = $request; $unavailable['items'][0]['qty'] = 20;
payment_create_error(409, $unavailable, true, 'Insufficient stock marks the pre-INSERT rejection.');
$expiredQuote = $request; $expiredQuote['delivery']['quoteId'] = 'expired-fixture-quote';
payment_create_error(409, $expiredQuote, true, 'An expired shipping quote marks the pre-INSERT rejection.');
payment_verify((int)mahara_query('SELECT COUNT(*) FROM checkout_orders')->fetchColumn() === 0 && mahara_payments_reserved($id, 'S') === 0, 'Rejected checkout attempts leave no orders or reservations.');
// A publication between quote and create cannot silently change the charge.
$changedDocument = $document; $changedDocument['products'][0]['price'] = 2000;
mahara_query('UPDATE content SET document=?,revision=revision+1 WHERE id=1', [json_encode($changedDocument, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
payment_error(409, static fn() => mahara_payments_create($request), 'Catalogue price changed after quote');
mahara_query('UPDATE content SET document=?,revision=revision+1 WHERE id=1', [json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
payment_verify($calls === [], 'Invalid price makes no bank request.');

// Two independent PHP processes contend for the same SQLite stock. They must
// never both accept seven units when only twelve exist.
$racers = [];
foreach ([payment_request($id, 'M', 7), payment_request($id, 'M', 7)] as $raceInput) {
    $process = proc_open([PHP_BINARY, __FILE__, '--race', base64_encode(json_encode($raceInput, JSON_THROW_ON_ERROR))], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start stock race fixture.');
    fclose($pipes[0]); $racers[] = [$process, $pipes];
}
$raceResults = [];
foreach ($racers as [$process, $pipes]) {
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    payment_verify(proc_close($process) === 0, 'Concurrent checkout worker exits cleanly: ' . $stderr);
    $raceResults[] = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
}
$raceStatuses = array_column($raceResults, 'status'); sort($raceStatuses);
payment_verify($raceStatuses === [201, 409] && mahara_payments_reserved($id, 'M') === 7 && payment_balance($id, 'M') === 12, 'SQLite transaction prevents simultaneous checkout overselling.');
foreach ($raceResults as $raceResult) if ($raceResult['status'] === 201) mahara_payments_notification(payment_signed(mahara_payments_row($raceResult['id']), 'CANCELED'));
payment_verify(mahara_payments_reserved($id, 'M') === 0, 'Concurrent fixture reservation is safely released by verified cancellation.');
$created = mahara_payments_create($request); $orderId = $created['order']['id']; $row = mahara_payments_row($orderId);
payment_verify($created['order']['status'] === 'payment_pending' && str_starts_with($created['payment']['url'], 'https://qr.nspk.ru/'), 'Created order exposes only SBP link and pending status.');
payment_verify(!isset($created['order']['customer']) && !isset($created['order']['delivery']) && !isset($created['order']['paymentId']), 'Public order does not expose contact information or bank metadata.');
payment_verify(strlen($created['accessToken']) === 43 && !str_contains($created['payment']['url'], $created['accessToken']), 'Order bearer token stays out of bank URL.');
payment_verify(mahara_payments_reserved($id, 'S') === 2 && payment_balance($id) === 12, 'Creation reserves stock without an inventory writeoff.');
$stockRevision = (int)mahara_query('SELECT revision FROM inventory_balances WHERE product_id=? AND size=\'S\'', [$id])->fetchColumn();
$manual = ['requestId' => payment_uuid(), 'productId' => $id, 'size' => 'S', 'type' => 'set', 'quantity' => 1, 'expectedRevision' => $stockRevision, 'note' => 'Проверка резерва'];
payment_error(409, static fn() => mahara_inventory_movement($manual, 'fixture'), 'Manual inventory count cannot reduce balance below reserved stock');
$manualWriteoff = $manual; $manualWriteoff['requestId'] = payment_uuid(); $manualWriteoff['type'] = 'writeoff'; $manualWriteoff['quantity'] = 11;
payment_error(409, static fn() => mahara_inventory_movement($manualWriteoff, 'fixture'), 'Manual writeoff cannot consume stock reserved by an order');
$manual['quantity'] = 2;
mahara_inventory_movement($manual, 'fixture');
payment_verify(payment_balance($id) === 2 && mahara_payments_reserved($id, 'S') === 2, 'Manual count equal to reserved quantity is permitted.');
$manual['requestId'] = payment_uuid(); $manual['quantity'] = 12; $manual['expectedRevision']++;
mahara_inventory_movement($manual, 'fixture');
$count = count($calls); $GLOBALS['mahara_shipping_fixture_expired'] = true;
$notCreated = true; $again = mahara_payments_create($request, $notCreated);
unset($GLOBALS['mahara_shipping_fixture_expired']);
payment_verify($again['order']['id'] === $orderId && $again['accessToken'] === $created['accessToken'] && count(array_filter($calls, static fn(string $method): bool => $method === 'Init')) === 1, 'Retry reuses order and payment instead of Init.');
payment_verify($notCreated === false && mahara_payments_reserved($id, 'S') === 2, 'An accepted request recovers after shipping expiry without another reservation or a rejection marker.');
$tampered = $request; $tampered['customer']['name'] = 'Другой покупатель';
payment_create_error(409, $tampered, false, 'An existing-order identity conflict must not authorize a new request.');
$otherPending = mahara_payments_create(payment_request($id, 'S')); $otherRow = mahara_payments_row($otherPending['order']['id']);
payment_verify(mahara_payments_reserved($id, 'S') === 3, 'Reservations for independent orders accumulate.');
payment_error(409, static fn() => mahara_payments_quote([['id' => $id, 'size' => 'S', 'qty' => 11]]), 'Other orders cannot oversell reserved stock');
$_SERVER['HTTP_X_ORDER_TOKEN'] = 'forged';
payment_error(404, static fn() => mahara_payments_api('/api/checkout/orders/' . $orderId, 'GET'), 'Order GET requires bearer token');
$forged = payment_signed($row, 'CONFIRMED'); $forged['Token'] = str_repeat('0', 64);
payment_error(403, static fn() => mahara_payments_notification($forged), 'Forged webhook rejected');
payment_error(409, static fn() => mahara_payments_notification(payment_signed($row, 'CONFIRMED', 1)), 'Signed webhook with wrong amount rejected');
$wrong = payment_signed($row, 'CONFIRMED'); $wrong['PaymentId'] = '999999'; $wrong['Token'] = mahara_tbank_token($wrong, 'fixture-password');
payment_error(409, static fn() => mahara_payments_notification($wrong), 'Signed webhook for another bank payment rejected');
mahara_payments_notification(payment_signed($row, 'AUTHORIZED'));
payment_verify(mahara_payments_row($orderId)['status'] === 'payment_pending' && payment_balance($id) === 12, 'AUTHORIZED is not paid and does not deduct stock.');
mahara_payments_notification(payment_signed($row, 'CONFIRMED'));
payment_verify(mahara_payments_row($orderId)['status'] === 'paid' && payment_balance($id) === 10 && mahara_payments_reserved($id, 'S') === 1, 'Confirmed payment writes off stock and preserves the other order reservation atomically.');
mahara_payments_notification(payment_signed($otherRow, 'CANCELED'));
payment_verify(mahara_payments_reserved($id, 'S') === 0 && payment_balance($id) === 10, 'Canceling another pending order releases only its reservation.');
$movements = (int)mahara_query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn();
mahara_payments_notification(payment_signed($row, 'CONFIRMED'));
mahara_payments_notification(payment_signed($row, 'NEW'));
payment_verify(payment_balance($id) === 10 && (int)mahara_query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn() === $movements && mahara_payments_row($orderId)['bank_status'] === 'CONFIRMED', 'Duplicate confirmation and late NEW do not reverse paid status or duplicate stock movement.');
mahara_payments_notification(payment_signed($row, 'PARTIAL_REFUNDED', 100));
mahara_payments_notification(payment_signed($row, 'CONFIRMED'));
payment_verify(mahara_payments_row($orderId)['status'] === 'partial_refunded' && payment_balance($id) === 10, 'Partial refund stays distinct and late confirmation cannot erase it.');
mahara_payments_notification(payment_signed($row, 'REFUNDED', 0));
mahara_payments_notification(payment_signed($row, 'CONFIRMED'));
payment_verify(mahara_payments_row($orderId)['status'] === 'refunded' && payment_balance($id) === 10, 'Refunded is final and goods are never automatically restocked.');
payment_error(409, static fn() => mahara_payments_fulfillment($orderId, ['status' => 'shipped', 'trackingNumber' => '123']), 'Refunded orders cannot be shipped');

// Lost Init responses must recover via CheckOrder and must not call Init twice.
$lostInit = true; $lostRequest = payment_request($id, 'S'); $lost = mahara_payments_create($lostRequest); $lostId = $lost['order']['id'];
payment_verify($lost['payment']['url'] === null && mahara_payments_reserved($id, 'S') === 1, 'Ambiguous Init timeout keeps reservation and returns a recoverable order.');
mahara_query('UPDATE checkout_orders SET created_at=?,last_checked=0 WHERE id=?', [time() - 60, $lostId]);
$initCount = count(array_filter($calls, static fn(string $method): bool => $method === 'Init'));
$recovered = mahara_payments_create($lostRequest);
payment_verify($recovered['payment']['url'] !== null && count(array_filter($calls, static fn(string $method): bool => $method === 'Init')) === $initCount, 'Ambiguous Init is recovered without a second Init.');
$lostRow = mahara_payments_row($lostId); $bank[$lostRow['payment_id']]['Status'] = 'CONFIRMED';
payment_verify(payment_recheck($lostId)['status'] === 'paid' && payment_balance($id) === 9, 'Missed webhook reconciles against bank GetState.');
$beforeCalls = count($calls); mahara_payments_reconcile($lostId); mahara_payments_reconcile($lostId);
payment_verify(count($calls) === $beforeCalls, 'Frequent polling is bounded by the last-checked claim.');

// Full refund only once. A timeout retains refund_pending and never resends Cancel.
$refundId = payment_uuid(); $failMethod = 'Cancel';
$pendingRefund = mahara_payments_refund($lostId, ['requestId' => $refundId]);
payment_verify($pendingRefund['order']['status'] === 'refund_pending', 'Uncertain refund remains pending.');
payment_verify(mahara_query('SELECT outcome FROM checkout_refund_attempts WHERE request_id=?', [$refundId])->fetchColumn() === 'unknown', 'Refund journal records the unknown transport outcome.');
$cancelCount = count(array_filter($calls, static fn(string $method): bool => $method === 'Cancel'));
$failMethod = ''; mahara_query('UPDATE checkout_orders SET last_checked=0 WHERE id=?', [$lostId]);
mahara_payments_refund($lostId, ['requestId' => $refundId]);
payment_verify(mahara_payments_row($lostId)['status'] === 'refund_pending' && count(array_filter($calls, static fn(string $method): bool => $method === 'Cancel')) === $cancelCount, 'Retry does not duplicate Cancel and CONFIRMED does not reset refund_pending.');
payment_error(409, static fn() => mahara_payments_refund($lostId, ['requestId' => payment_uuid()]), 'Different refund request is rejected while pending');
$bank[$lostRow['payment_id']]['Status'] = 'REFUNDED'; $bank[$lostRow['payment_id']]['Amount'] = 0;
payment_verify(payment_recheck($lostId)['status'] === 'refunded' && payment_balance($id) === 9, 'Authoritative zero-amount refund settles without restock.');

// Wall-clock expiry alone must not free a possibly paid reservation or call
// Cancel, which could race a successful payment and refund it unintentionally.
$expired = mahara_payments_create(payment_request($id, 'M', 2)); $expiredId = $expired['order']['id'];
mahara_query('UPDATE checkout_orders SET expires_at=?,last_checked=0 WHERE id=?', [time() - 10, $expiredId]);
$beforeCancel = count(array_filter($calls, static fn(string $method): bool => $method === 'Cancel'));
$expiration = mahara_payments_reconcile($expiredId);
payment_verify($expiration['status'] === 'payment_pending' && mahara_payments_reserved($id, 'M') === 2 && count(array_filter($calls, static fn(string $method): bool => $method === 'Cancel')) === $beforeCancel, 'Local expiry never releases stock or automatically refunds.');
payment_verify(mahara_payments_view($expiration)['payment']['url'] === null, 'Expired link is not offered for payment.');
$bank[$expiration['payment_id']]['Status'] = 'DEADLINE_EXPIRED';
payment_verify(payment_recheck($expiredId)['status'] === 'expired' && mahara_payments_reserved($id, 'M') === 0, 'Bank-confirmed deadline expiry releases stock.');

// A failed GetQr must never expose the generic card payment form.
$unsafeQr = true; $unsafe = mahara_payments_create(payment_request($id, 'M')); $unsafeQr = false;
payment_verify($unsafe['payment']['url'] === null, 'Invalid SBP URL does not fall back to generic card form.');
payment_verify(mahara_payments_view(mahara_payments_row($unsafe['order']['id']), true)['order']['customer']['email'] === 'buyer@example.test', 'Authenticated admin view contains order contacts.');

// Fulfillment is independent of payment and does not pretend to fiscalize a
// closing receipt. The operator must use their configured fiscal process.
$ship = mahara_payments_create(payment_request($id, 'M')); $shipRow = mahara_payments_row($ship['order']['id']);
mahara_payments_notification(payment_signed($shipRow, 'CONFIRMED'));
$fulfilled = mahara_payments_fulfillment($shipRow['id'], ['status' => 'shipped', 'trackingNumber' => 'TEST-TRACK']);
payment_verify($fulfilled['order']['fulfillmentStatus'] === 'shipped' && $fulfilled['order']['trackingNumber'] === 'TEST-TRACK' && $fulfilled['order']['receiptStatus'] === 'provider_requested', 'Fulfillment stores tracking without claiming receipt issuance.');

// Failure recovery is exercised with real persistence and callbacks. No rejected
// request is assumed harmless merely because its HTTP exchange returned an error.
$stockRevision = (int)mahara_query('SELECT revision FROM inventory_balances WHERE product_id=? AND size=\'S\'', [$id])->fetchColumn();
mahara_inventory_movement(['requestId' => payment_uuid(), 'productId' => $id, 'size' => 'S', 'type' => 'receipt', 'quantity' => 50, 'expectedRevision' => $stockRevision, 'note' => 'Failure regression fixtures'], 'fixture');
$baseTransport = $GLOBALS['mahara_payments_test_transport']; $scenario = ''; $rejectCode = '2'; $raceOrder = null;
$GLOBALS['mahara_payments_test_transport'] = static function (string $method, array $payload, string $url) use ($baseTransport, &$scenario, &$rejectCode, &$raceOrder, &$calls, &$bank): array {
    $intercept = ($method === 'Init' && in_array($scenario, ['init-reject', 'init-timeout', 'init-existing', 'init-callback-race'], true))
        || ($method === 'Cancel' && in_array($scenario, ['refund-reject', 'refund-callback-race', 'refund-overlap'], true))
        || ($method === 'GetState' && $scenario === 'state-reject');
    if ($method === 'CheckOrder' && $scenario === 'init-callback-race') {
        $reply = $baseTransport($method, $payload, $url); // stale empty snapshot
        $current = mahara_query('SELECT * FROM checkout_orders WHERE number=?', [$payload['OrderId']])->fetch();
        $current['payment_id'] = (string)(900000 + count($bank));
        $bank[$current['payment_id']] = payment_signed($current, 'CONFIRMED');
        mahara_payments_notification($bank[$current['payment_id']]);
        return $reply;
    }
    if (!$intercept) return $baseTransport($method, $payload, $url);
    $calls[] = $method;
    payment_verify($url === 'https://securepay.tinkoff.ru/v2/' . $method && $payload['Token'] === mahara_tbank_token($payload, 'fixture-password'), 'Failure transport keeps fixed origin and signed request.');
    if ($scenario === 'init-timeout') throw new MaharaHttpError(502, 'Fixture request outcome unknown.');
    if ($scenario === 'init-existing') {
        $paymentId = (string)(900000 + count($bank));
        $bank[$paymentId] = ['Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'TEST-TERMINAL', 'OrderId' => $payload['OrderId'], 'PaymentId' => $paymentId, 'Amount' => $payload['Amount'], 'Status' => 'NEW'];
    }
    if ($scenario === 'refund-callback-race') {
        $bank[$payload['PaymentId']]['Status'] = 'REFUNDED'; $bank[$payload['PaymentId']]['Amount'] = 0;
        mahara_payments_notification(payment_signed(mahara_payments_row($raceOrder), 'REFUNDED', 0));
    }
    if ($scenario === 'refund-overlap') {
        $requestId = mahara_payments_row($raceOrder)['refund_request_id'];
        payment_verify(mahara_payments_refund($raceOrder, ['requestId' => $requestId])['order']['status'] === 'refund_pending', 'Overlapping same-UUID refund sees pending operation without another Cancel.');
        payment_error(409, static fn() => mahara_payments_refund($raceOrder, ['requestId' => payment_uuid()]), 'Overlapping different-UUID refund is rejected');
    }
    return ['Success' => false, 'ErrorCode' => $rejectCode, 'TerminalKey' => 'TEST-TERMINAL', 'Details' => 'PRIVATE PROVIDER TEXT MUST NEVER BE EXPOSED'];
};
$initCalls = static function () use (&$calls): int { return count(array_filter($calls, static fn(string $method): bool => $method === 'Init')); };
$cancelCalls = static function () use (&$calls): int { return count(array_filter($calls, static fn(string $method): bool => $method === 'Cancel')); };
$reservationCount = static fn(string $order): int => (int)mahara_query('SELECT COALESCE(SUM(quantity),0) FROM checkout_reservations WHERE order_id=?', [$order])->fetchColumn();

$scenario = 'init-reject'; $rejectCode = '2'; $definiteRequest = payment_request($id); $beforeInit = $initCalls();
$definite = mahara_payments_create($definiteRequest); $definiteId = $definite['order']['id'];
$definiteRow = mahara_payments_row($definiteId);
payment_verify($definiteRow['status'] === 'failed' && $definiteRow['payment_id'] === null && $reservationCount($definiteId) === 0, 'Validated Init rejection plus authoritative empty CheckOrder releases only its reservation.');
payment_verify($definiteRow['init_outcome'] === 'rejected' && $definiteRow['init_error_code'] === '2' && !str_contains(json_encode(mahara_payments_view($definiteRow, true)), 'PRIVATE PROVIDER'), 'Only sanitized rejection code is persisted/exposed.');
$same = mahara_payments_create($definiteRequest);
payment_verify($same['accessToken'] === $definite['accessToken'] && $same['order']['id'] === $definiteId && $initCalls() === $beforeInit + 1, 'Failed Init retry never sends a second Init for the same order.');

foreach (['20', '3', '9999'] as $code) {
    $scenario = 'init-reject'; $rejectCode = $code; $ambiguous = mahara_payments_create(payment_request($id)); $ambiguousId = $ambiguous['order']['id'];
    payment_verify(mahara_payments_row($ambiguousId)['status'] === 'payment_pending' && $reservationCount($ambiguousId) === 1, 'Duplicate/internal/unknown rejection plus empty CheckOrder cannot free possible paid stock: ' . $code);
}
$scenario = 'init-timeout'; $timeout = mahara_payments_create(payment_request($id)); $timeoutId = $timeout['order']['id'];
mahara_query('UPDATE checkout_orders SET created_at=?,last_checked=0 WHERE id=?', [time() - 60, $timeoutId]);
$scenario = ''; payment_recheck($timeoutId);
payment_verify(mahara_payments_row($timeoutId)['status'] === 'payment_pending' && $reservationCount($timeoutId) === 1, 'Empty CheckOrder after transport ambiguity retains the reservation.');

$scenario = 'init-existing'; $rejectCode = '20'; $existing = mahara_payments_create(payment_request($id)); $existingId = $existing['order']['id'];
payment_verify(mahara_payments_row($existingId)['payment_id'] !== null && $existing['payment']['url'] !== null, 'Duplicate Init is recovered from the existing bank payment without issuing another Init.');
$scenario = 'init-callback-race'; $rejectCode = '2'; $beforeBalance = payment_balance($id);
$race = mahara_payments_create(payment_request($id)); $raceId = $race['order']['id']; $raceRow = mahara_payments_row($raceId);
payment_verify($raceRow['status'] === 'paid' && (int)$raceRow['stock_applied'] === 1 && payment_balance($id) === $beforeBalance - 1 && $reservationCount($raceId) === 0, 'A confirmed callback racing stale empty CheckOrder wins without releasing possible paid stock.');
mahara_payments_notification(payment_signed($raceRow, 'CONFIRMED'));
payment_verify(payment_balance($id) === $beforeBalance - 1, 'Repeated callback after rejection recovery still deducts stock once.');

$scenario = ''; $refundFixture = mahara_payments_create(payment_request($id)); $refundOrder = $refundFixture['order']['id']; $refundRow = mahara_payments_row($refundOrder);
$bank[$refundRow['payment_id']]['Status'] = 'CONFIRMED'; mahara_payments_notification(payment_signed($refundRow, 'CONFIRMED'));
$beforeCancel = $cancelCalls(); $beforeAttempts = (int)mahara_query('SELECT COUNT(*) FROM checkout_refund_attempts')->fetchColumn();
$GLOBALS['mahara_payments_test_config']['enabled'] = false;
payment_error(503, static fn() => mahara_payments_refund($refundOrder, ['requestId' => payment_uuid()]), 'Disabled bank connection rejects a new refund before attempting it');
$GLOBALS['mahara_payments_test_config']['enabled'] = true;
$GLOBALS['mahara_payments_test_secrets']['terminal_key'] = 'ANOTHER-TERMINAL';
payment_error(409, static fn() => mahara_payments_refund($refundOrder, ['requestId' => payment_uuid()]), 'A different current merchant terminal cannot refund a historic order');
$GLOBALS['mahara_payments_test_secrets']['terminal_key'] = 'TEST-TERMINAL';
$GLOBALS['mahara_payments_test_secrets']['password'] = '';
payment_error(503, static fn() => mahara_payments_refund($refundOrder, ['requestId' => payment_uuid()]), 'Missing bank credentials reject a new refund before attempting it');
$GLOBALS['mahara_payments_test_secrets']['password'] = 'fixture-password';
payment_verify(mahara_payments_row($refundOrder)['status'] === 'paid' && (int)mahara_query('SELECT COUNT(*) FROM checkout_refund_attempts')->fetchColumn() === $beforeAttempts && $cancelCalls() === $beforeCancel, 'Local preflight failures do not create a false pending refund or send Cancel.');
$scenario = 'refund-reject'; $rejectCode = '2'; $rejectedRefundId = payment_uuid(); $beforeCancel = $cancelCalls();
$rejectResult = mahara_payments_refund($refundOrder, ['requestId' => $rejectedRefundId]);
payment_verify($rejectResult['order']['status'] === 'paid' && $rejectResult['order']['refundOutcome'] === 'rejected' && str_contains($rejectResult['order']['operatorMessage'], 'Банк отклонил'), 'Definite rejected Cancel is recoverable only after GetState confirms paid.');
mahara_query('UPDATE checkout_orders SET last_checked=0 WHERE id=?', [$refundOrder]);
mahara_payments_refund($refundOrder, ['requestId' => $rejectedRefundId]);
payment_verify($cancelCalls() === $beforeCancel + 1, 'Replaying rejected refund UUID never sends Cancel again.');
$scenario = ''; $newRefundId = payment_uuid(); mahara_payments_refund($refundOrder, ['requestId' => $newRefundId]);
payment_verify(mahara_payments_row($refundOrder)['status'] === 'refunded' && $cancelCalls() === $beforeCancel + 2, 'A new explicit UUID can retry only a bank-verified definite failed refund.');
mahara_payments_refund($refundOrder, ['requestId' => $rejectedRefundId]); mahara_payments_refund($refundOrder, ['requestId' => $newRefundId]);
payment_verify($cancelCalls() === $beforeCancel + 2, 'Old and successful refund UUID replays cannot duplicate a financial action.');

$scenario = ''; $uncertainRefund = mahara_payments_create(payment_request($id)); $uncertainId = $uncertainRefund['order']['id']; $uncertainRow = mahara_payments_row($uncertainId);
$bank[$uncertainRow['payment_id']]['Status'] = 'CONFIRMED'; mahara_payments_notification(payment_signed($uncertainRow, 'CONFIRMED'));
$scenario = 'refund-reject'; $rejectCode = '3'; $uncertainRequestId = payment_uuid(); mahara_payments_refund($uncertainId, ['requestId' => $uncertainRequestId]);
payment_verify(mahara_payments_row($uncertainId)['status'] === 'refund_pending' && mahara_payments_row($uncertainId)['refund_outcome'] === 'unknown', 'Internal Cancel rejection remains ambiguous even when GetState still reports CONFIRMED.');
payment_error(409, static fn() => mahara_payments_refund($uncertainId, ['requestId' => payment_uuid()]), 'Ambiguous rejected refund cannot be retried with a new UUID');

$scenario = ''; $stateFailure = mahara_payments_create(payment_request($id)); $stateId = $stateFailure['order']['id']; $stateRow = mahara_payments_row($stateId);
$bank[$stateRow['payment_id']]['Status'] = 'CONFIRMED'; mahara_payments_notification(payment_signed($stateRow, 'CONFIRMED'));
$scenario = 'state-reject'; $rejectCode = '2'; $stateRequest = payment_uuid(); $beforeCancel = $cancelCalls(); mahara_payments_refund($stateId, ['requestId' => $stateRequest]);
payment_verify(mahara_payments_row($stateId)['status'] === 'refund_pending', 'A rejected GetState after accepted Cancel never reopens the refund.');
mahara_payments_refund($stateId, ['requestId' => $stateRequest]);
payment_verify($cancelCalls() === $beforeCancel + 1, 'Unverified accepted refund is never sent twice.');
$scenario = ''; payment_recheck($stateId);
payment_verify(mahara_payments_row($stateId)['status'] === 'refunded', 'Later authoritative status safely resolves an accepted refund.');

$scenario = ''; $callbackRefund = mahara_payments_create(payment_request($id)); $raceOrder = $callbackRefund['order']['id']; $callbackRow = mahara_payments_row($raceOrder);
$bank[$callbackRow['payment_id']]['Status'] = 'CONFIRMED'; mahara_payments_notification(payment_signed($callbackRow, 'CONFIRMED'));
$scenario = 'refund-callback-race'; $rejectCode = '2'; $callbackRefundId = payment_uuid(); mahara_payments_refund($raceOrder, ['requestId' => $callbackRefundId]);
payment_verify(mahara_payments_row($raceOrder)['status'] === 'refunded' && mahara_query('SELECT outcome FROM checkout_refund_attempts WHERE request_id=?', [$callbackRefundId])->fetchColumn() === 'settled', 'Settled callback racing a rejected HTTP response cannot regress order or refund journal.');
$scenario = ''; $overlap = mahara_payments_create(payment_request($id)); $raceOrder = $overlap['order']['id']; $overlapRow = mahara_payments_row($raceOrder);
$bank[$overlapRow['payment_id']]['Status'] = 'CONFIRMED'; mahara_payments_notification(payment_signed($overlapRow, 'CONFIRMED'));
$scenario = 'refund-overlap'; $rejectCode = '3'; $beforeCancel = $cancelCalls(); mahara_payments_refund($raceOrder, ['requestId' => payment_uuid()]);
payment_verify($cancelCalls() === $beforeCancel + 1, 'Interleaved refund requests send one financial operation.');
$scenario = '';

$acceptance = mahara_payments_view(mahara_payments_row($existingId), true)['order']['acceptance'];
payment_verify($acceptance['acceptedAt'] !== null && $acceptance['termsUrl'] === 'https://merchant.example.test/terms' && $acceptance['privacyUrl'] === 'https://merchant.example.test/privacy' && $acceptance['legalVersion'] === 'external', 'Order persists the time, legal URLs and version accepted at checkout.');
$GLOBALS['mahara_payments_test_config']['terms_url'] = 'https://merchant.example.test/new-terms';
payment_verify(mahara_payments_view(mahara_payments_row($existingId), true)['order']['acceptance'] === $acceptance, 'Later legal publication cannot rewrite stored acceptance evidence.');
$GLOBALS['mahara_payments_test_config']['terms_url'] = 'https://merchant.example.test/terms';

// Search must reach historical orders beyond the former newest-200 window.
// Clone archived rows in this isolated database without inventory/bank actions.
$source = mahara_payments_row($definiteId);
for ($index = 0; $index < 205; $index++) {
    $clone = $source; $clone['id'] = sprintf('%032x', 700000 + $index); $clone['number'] = sprintf('ARCHIVE-%04d', $index); $clone['request_id'] = payment_uuid();
    $clone['created_at'] = 100000 + $index; $clone['updated_at'] = $clone['created_at'];
    $clone['customer_json'] = json_encode(['name' => $index === 0 ? 'ЁЛКА Старый покупатель 50%_скидка' : 'Архивный покупатель', 'email' => $index === 0 ? 'old@example.test' : 'archive@example.test', 'phone' => '+79995550000'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    mahara_query('INSERT INTO checkout_orders (' . implode(',', array_keys($clone)) . ') VALUES (' . implode(',', array_fill(0, count($clone), '?')) . ')', array_values($clone));
}
$list = mahara_payments_admin_list([]);
payment_verify(count($list['orders']) === 50 && $list['pagination']['total'] > 200 && $list['pagination']['pages'] > 4, 'Server pagination covers all persisted orders with bounded page size.');
$lastPage = mahara_payments_admin_list(['page' => '999999999', 'pageSize' => '100']);
payment_verify($lastPage['pagination']['page'] === $lastPage['pagination']['pages'] && in_array('ARCHIVE-0000', array_column($lastPage['orders'], 'number'), true), 'Historical order remains reachable on the last page and excessive page number is clamped.');
foreach (['old@example.test', 'ёлка', '50%_скидка', 'ARCHIVE-0000'] as $search) {
    $found = mahara_payments_admin_list(['q' => $search]);
    payment_verify($found['pagination']['total'] === 1 && $found['orders'][0]['number'] === 'ARCHIVE-0000', 'Global search reaches old records and treats text literally: ' . $search);
}
payment_verify(mahara_payments_admin_list(['q' => "' OR 1=1 --"])['pagination']['total'] === 0, 'Search input cannot alter SQL filters.');
$pendingList = mahara_payments_admin_list(['status' => 'pending', 'pageSize' => '100']);
payment_verify(array_unique(array_column($pendingList['orders'], 'status')) === ['payment_pending'], 'Status filtering is performed by the server across the whole dataset.');
foreach ([['page' => '0'], ['pageSize' => '101'], ['page' => []], ['status' => 'bogus'], ['q' => str_repeat('a', 201)], ['unknown' => 'x']] as $badList) payment_error(400, static fn() => mahara_payments_admin_list($badList), 'Malformed list parameters are rejected');

echo "PASS: $checks payment checks (fake bank transport only)\n";
