<?php
declare(strict_types=1);

// Exercise the real shipping -> checkout -> notification flow against an isolated
// database. Only the two providers' HTTP transports use synthetic responses.
if (($argv[1] ?? '') !== '--worker') {
    $privateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mahara-commerce-' . bin2hex(random_bytes(12));
    if (!mkdir($privateDir, 0700)) throw new RuntimeException('Cannot create test directory.');
    putenv('APP_ENV=development');
    putenv('DATA_DIR=' . $privateDir);
    putenv('APP_ORIGIN=http://127.0.0.1:8765');
    $status = 1;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start commerce test.');
        fclose($pipes[0]);
        echo stream_get_contents($pipes[1]);
        fwrite(STDERR, stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
    } finally {
        foreach (['mahara.sqlite', 'mahara.sqlite-wal', 'mahara.sqlite-shm'] as $name) {
            $path = $privateDir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && !is_link($path)) unlink($path);
        }
        rmdir($privateDir);
    }
    exit($status);
}

define('MAHARA_LIBRARY_ONLY', true);
$GLOBALS['mahara_app_test_config'] = []; // Do not load a shop's private config.
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/merchant.php';
require_once dirname(__DIR__) . '/server/shipping.php';
require_once dirname(__DIR__) . '/server/payments.php';
require_once dirname(__DIR__) . '/server/notifications.php';

$GLOBALS['mahara_shipping_test_config'] = [
    'enabled' => true, 'origin_city_code' => 44, 'tariff_codes' => [136],
    'packages_by_category' => [
        'hoodie' => ['weight' => 800, 'width' => 30, 'height' => 8, 'length' => 40],
        'tee' => ['weight' => 300, 'width' => 20, 'height' => 4, 'length' => 30],
    ],
];
$GLOBALS['mahara_shipping_test_secrets'] = ['account' => 'test-only-account', 'password' => 'test-only-password'];
$GLOBALS['mahara_payments_test_config'] = [
    'enabled' => true, 'receipt_enabled' => true, 'taxation' => 'usn_income', 'item_tax' => 'none',
    'delivery_mode' => 'cdek_receiver', 'terms_url' => 'https://merchant.example.test/terms', 'privacy_url' => 'https://merchant.example.test/privacy',
];
$GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => 'TEST-TERMINAL', 'password' => 'fixture-password'];
$GLOBALS['mahara_mail_test_config'] = ['enabled' => true, 'host' => 'smtp.example.test', 'from' => 'shop@example.test', 'shop_email' => 'owner@example.test'];
$GLOBALS['mahara_mail_test_secrets'] = ['username' => 'fixture-user', 'password' => 'fixture-mail-password'];

$checks = 0; $cdekCalls = []; $bankCalls = []; $bank = [];
function commerce_verify(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function commerce_error(int $status, callable $action, string $message): void {
    try { $action(); }
    catch (MaharaHttpError $error) {
        commerce_verify($error->status === $status, $message . ': received ' . $error->status . ', expected ' . $status);
        return;
    }
    throw new RuntimeException($message . ': expected rejection.');
}
function commerce_uuid(): string {
    static $number = 0;
    return sprintf('00000000-0000-4000-8000-%012d', ++$number);
}
function commerce_request(array $items, string $quoteId): array {
    return [
        'requestId' => commerce_uuid(), 'items' => $items,
        'expectedTotalAmount' => mahara_payments_quote($items)['totalAmount'],
        'customer' => ['name' => 'Покупатель', 'email' => 'buyer@example.test', 'phone' => '+7 (999) 123-45-67'],
        'delivery' => ['city' => 'Поддельный город', 'point' => 'FAKE1', 'address' => 'Поддельный адрес', 'quoteId' => $quoteId],
        'acceptedTerms' => true,
    ];
}
function commerce_balance(string $productId): int {
    return (int)mahara_query('SELECT quantity FROM inventory_balances WHERE product_id=? AND size=?', [$productId, 'S'])->fetchColumn();
}
function commerce_state(): array {
    global $cdekCalls, $bankCalls;
    return [
        'orders' => (int)mahara_query('SELECT COUNT(*) FROM checkout_orders')->fetchColumn(),
        'reservations' => (int)mahara_query('SELECT COALESCE(SUM(quantity),0) FROM checkout_reservations')->fetchColumn(),
        'cdekCalls' => count($cdekCalls), 'bankCalls' => count($bankCalls),
    ];
}

$GLOBALS['mahara_shipping_test_transport'] = static function (string $method, string $path, array $data, ?string $token) use (&$cdekCalls): array {
    $cdekCalls[] = [$method, $path, $data];
    if ($path === 'oauth/token') {
        commerce_verify($method === 'POST' && $token === null && $data['client_id'] === 'test-only-account', 'CDEK authenticates with synthetic server credentials.');
        return ['access_token' => 'test-only-bearer', 'expires_in' => 3600];
    }
    commerce_verify($token === 'test-only-bearer', 'CDEK requests use the synthetic bearer token.');
    if ($path === 'deliverypoints') {
        commerce_verify($method === 'GET' && $data['code'] === 'MSK1', 'Shipping resolves the requested PVZ through CDEK.');
        return [[
            'code' => 'MSK1', 'type' => 'PVZ', 'is_handout' => true, 'name' => 'Тестовый ПВЗ',
            'location' => ['city_code' => 44, 'city' => 'Москва', 'country_code' => 'RU', 'address' => 'Тестовая улица, 1'],
        ]];
    }
    if ($path === 'calculator/tarifflist') {
        commerce_verify($method === 'POST' && $data['from_location'] === ['code' => 44] && $data['to_location'] === ['code' => 44], 'Shipping calculates between verified sender/destination city codes.');
        commerce_verify($data['packages'] === array_fill(0, 2, $GLOBALS['mahara_shipping_test_config']['packages_by_category']['hoodie']), 'CDEK receives configured dimensions for both units.');
        return ['tariff_codes' => [['tariff_code' => 136, 'delivery_mode' => 4, 'delivery_sum' => 345.67, 'period_min' => 2, 'period_max' => 4]]];
    }
    throw new RuntimeException('Unexpected CDEK transport call: ' . $path);
};
$GLOBALS['mahara_payments_test_transport'] = static function (string $method, array $payload, string $url) use (&$bankCalls, &$bank): array {
    $bankCalls[] = [$method, $payload];
    commerce_verify($url === 'https://securepay.tinkoff.ru/v2/' . $method, 'Bank requests have the fixed provider URL.');
    commerce_verify($payload['Token'] === mahara_tbank_token($payload, 'fixture-password'), 'Bank requests carry a valid synthetic terminal signature.');
    if ($method === 'Init') {
        $paymentId = (string)(100000 + count($bank));
        $bank[$paymentId] = [
            'Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'TEST-TERMINAL',
            'OrderId' => $payload['OrderId'], 'PaymentId' => $paymentId,
            'Amount' => $payload['Amount'], 'Status' => 'NEW',
        ];
        return $bank[$paymentId];
    }
    $paymentId = (string)($payload['PaymentId'] ?? '');
    if (!isset($bank[$paymentId])) throw new RuntimeException('Unknown synthetic payment.');
    if ($method === 'GetQr') {
        commerce_verify($payload['PaymentMethod'] === 'SBP', 'The checkout requests SBP explicitly.');
        return ['Success' => true, 'ErrorCode' => '0', 'PaymentId' => $paymentId, 'Data' => $payload['DataType'] === 'IMAGE'
            ? '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10z"/></svg>'
            : 'https://qr.nspk.ru/' . $paymentId . '?type=02'];
    }
    if ($method === 'GetState') return $bank[$paymentId];
    if ($method === 'Cancel') { $bank[$paymentId]['Status'] = 'REFUNDED'; $bank[$paymentId]['Amount'] = 0; return $bank[$paymentId]; }
    throw new RuntimeException('Unexpected bank transport call: ' . $method);
};

commerce_verify(realpath((new ReflectionFunction('mahara_shipping_validate'))->getFileName()) === realpath(dirname(__DIR__) . '/server/shipping.php'), 'Checkout uses the real shipping validation implementation.');
commerce_verify(mahara_shipping_public_config()['enabled'] && mahara_payments_public_config()['enabled'], 'Both modules enable only with complete synthetic configuration.');
$document = mahara_content();
$product = $document['products'][0];
$product['sizes'] = ['S']; $product['price'] = 1900; $product['category'] = 'hoodie';
$document['products'] = [$product]; unset($document['revision']);
mahara_query('UPDATE content SET document=?,revision=revision+1 WHERE id=1', [json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
$productId = $product['id'];
mahara_inventory_movement(['requestId' => commerce_uuid(), 'productId' => $productId, 'size' => 'S', 'type' => 'receipt', 'quantity' => 12, 'expectedRevision' => 0, 'note' => ''], 'fixture');
$items = [['id' => $productId, 'size' => 'S', 'qty' => 2]];
$shipping = mahara_shipping_quote(['items' => $items, 'pointCode' => 'MSK1']);
commerce_verify($shipping['amount'] === 34567 && $shipping['point']['cityCode'] === 44, 'A real shipping quote persists the separate kopeck estimate and verified PVZ.');
$request = commerce_request($items, $shipping['quoteId']);
$cdekCount = count($cdekCalls);
$created = mahara_payments_create($request);
$row = mahara_payments_row($created['order']['id']);
commerce_verify(mahara_query("SELECT COUNT(*) FROM order_mail_queue WHERE event='order_created'")->fetchColumn() === 2, 'Creation schedules customer/shop notification jobs without sending SMTP.');
commerce_verify((int)$row['accepted_at'] >= (int)$row['created_at'] && $row['terms_url'] === 'https://merchant.example.test/terms' && $row['privacy_url'] === 'https://merchant.example.test/privacy' && $row['legal_version'] === 'external', 'Integration persists acceptance evidence at the original order creation.');
$delivery = json_decode($row['delivery_json'], true, 64, JSON_THROW_ON_ERROR);
commerce_verify($created['order']['status'] === 'payment_pending' && $created['payment']['url'] === 'https://qr.nspk.ru/' . $row['payment_id'] . '?type=02', 'Checkout creates a pending order and usable SBP link.');
commerce_verify($created['order']['totalAmount'] === 380000 && $created['order']['deliveryAmount'] === 0 && $delivery['shippingAmount'] === 34567, 'The bank total contains goods only while the receiver-paid estimate is stored separately.');
commerce_verify($delivery === ['city' => 'Москва', 'point' => 'MSK1', 'address' => 'Тестовая улица, 1', 'quoteId' => $shipping['quoteId'], 'shippingAmount' => 34567, 'tariffCode' => 136, 'estimated' => true], 'Persisted delivery replaces all forged destination fields with the verified quote.');
commerce_verify(count($cdekCalls) === $cdekCount, 'Checkout validates its persisted quote without any further CDEK request.');
$inits = array_values(array_filter($bankCalls, static fn(array $call): bool => $call[0] === 'Init'));
commerce_verify(count($inits) === 1 && $inits[0][1]['Amount'] === 380000, 'Exactly one bank Init charges the goods total.');
$receiptItems = $inits[0][1]['Receipt']['Items'];
commerce_verify(count($receiptItems) === 1 && $receiptItems[0]['Quantity'] === 2 && $receiptItems[0]['Price'] === 190000 && array_sum(array_column($receiptItems, 'Amount')) === 380000, 'The payment receipt contains the goods only and matches the bank amount.');
commerce_verify(commerce_balance($productId) === 12 && mahara_payments_reserved($productId, 'S') === 2, 'Pending payment reserves units without writing off stock.');

// Retries identify the original request before validating an expired quote.
mahara_query('UPDATE shipping_quotes SET expires_at=? WHERE id=?', [time() - 1, $shipping['quoteId']]);
$retried = mahara_payments_create($request);
commerce_verify(mahara_query("SELECT COUNT(*) FROM order_mail_queue WHERE event='order_created'")->fetchColumn() === 2, 'Idempotent create retry cannot duplicate notification jobs.');
commerce_verify($retried['order']['id'] === $created['order']['id'] && $retried['accessToken'] === $created['accessToken'], 'An identical retry returns the existing order and access token after shipping quote expiry.');
commerce_verify(count(array_filter($bankCalls, static fn(array $call): bool => $call[0] === 'Init')) === 1 && mahara_payments_reserved($productId, 'S') === 2, 'Retry neither initializes a second bank payment nor reserves more stock.');
commerce_verify(count($cdekCalls) === $cdekCount, 'Retry does not recalculate expired delivery.');

$notification = $bank[$row['payment_id']];
$notification['Status'] = 'CONFIRMED';
$bank[$row['payment_id']] = $notification;
$notification['Token'] = mahara_tbank_token($notification, 'fixture-password');
mahara_payments_notification($notification);
commerce_verify(mahara_payments_row($row['id'])['status'] === 'paid' && commerce_balance($productId) === 10 && mahara_payments_reserved($productId, 'S') === 0, 'A signed CONFIRMED callback marks paid, writes off two units and releases reservations.');
mahara_payments_notification($notification);
commerce_verify(commerce_balance($productId) === 10 && (int)mahara_query("SELECT COUNT(*) FROM inventory_movements WHERE request_id LIKE 'payment:%'")->fetchColumn() === 1, 'Duplicate signed callbacks apply stock and audit movement exactly once.');
commerce_verify(mahara_query("SELECT COUNT(*) FROM order_mail_queue WHERE event='payment_paid'")->fetchColumn() === 2, 'Repeated confirmed callbacks schedule each paid notification once.');
mahara_payments_fulfillment($row['id'], ['status' => 'shipped', 'trackingNumber' => 'FIXTURE-123']);
mahara_payments_fulfillment($row['id'], ['status' => 'shipped', 'trackingNumber' => 'FIXTURE-123']);
mahara_payments_fulfillment($row['id'], ['status' => 'completed', 'trackingNumber' => 'FIXTURE-123']);
commerce_verify(mahara_query("SELECT COUNT(*) FROM order_mail_queue WHERE event IN ('order_shipped','order_completed')")->fetchColumn() === 4, 'Fulfillment changes queue shipped/completed notifications without duplicates.');
mahara_payments_refund($row['id'], ['requestId' => commerce_uuid()]);
commerce_verify(mahara_payments_row($row['id'])['status'] === 'refunded' && commerce_balance($productId) === 10 && mahara_query("SELECT COUNT(*) FROM order_mail_queue WHERE event='refund_completed'")->fetchColumn() === 2, 'Confirmed full refund queues notifications without automatically restocking goods.');
commerce_verify(mahara_query("SELECT COUNT(*) FROM order_mail_queue WHERE status<>'pending' OR sent_at IS NOT NULL")->fetchColumn() === 0, 'Web payment/order hooks never send mail directly.');

$staleRequest = $request; $staleRequest['requestId'] = commerce_uuid();
$before = commerce_state();
commerce_error(409, static fn() => mahara_payments_create($staleRequest), 'A new order cannot use an expired shipping quote');
commerce_verify(commerce_state() === $before, 'Expired quote rejection creates no order, reservation or provider request.');

$fresh = mahara_shipping_quote(['items' => $items, 'pointCode' => 'MSK1']);
$changedItems = $items; $changedItems[0]['qty'] = 1;
$changedRequest = commerce_request($changedItems, $fresh['quoteId']);
$before = commerce_state();
commerce_error(409, static fn() => mahara_payments_create($changedRequest), 'A shipping quote cannot be reused for a changed cart');
commerce_verify(commerce_state() === $before, 'Cart binding failure rolls back without reservations or provider requests.');

$disabledRequest = commerce_request($items, $fresh['quoteId']);
$GLOBALS['mahara_payments_test_config']['enabled'] = false;
$before = commerce_state();
commerce_error(503, static fn() => mahara_payments_create($disabledRequest), 'Disabled payment configuration blocks a new checkout');
commerce_verify(commerce_state() === $before && commerce_balance($productId) === 10, 'Disabled payments persist nothing and leave stock unchanged.');
$GLOBALS['mahara_payments_test_config']['enabled'] = true;
$GLOBALS['mahara_shipping_test_config']['enabled'] = false;
commerce_verify(!mahara_payments_public_config()['enabled'], 'Receiver-paid checkout is disabled when CDEK setup is disabled.');
$disabledRequest['requestId'] = commerce_uuid();
commerce_error(503, static fn() => mahara_payments_create($disabledRequest), 'Disabled shipping configuration also blocks a new checkout');
commerce_verify(commerce_state() === $before && commerce_balance($productId) === 10, 'Disabled shipping creates no order, reservation or provider request.');

echo 'Commerce integration checks passed: ' . $checks . PHP_EOL;
