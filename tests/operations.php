<?php
declare(strict_types=1);

// Isolated journal fixtures; no shop configuration, providers or real stock.
if (($argv[1] ?? '') !== '--worker') {
    $privateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mahara-operations-' . bin2hex(random_bytes(12));
    if (!mkdir($privateDir, 0700)) throw new RuntimeException('Cannot create operations fixture directory.');
    $status = 1;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $privateDir], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start operations fixtures.');
        fclose($pipes[0]); echo stream_get_contents($pipes[1]); fwrite(STDERR, stream_get_contents($pipes[2])); fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
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
$GLOBALS['mahara_app_test_config'] = ['root' => dirname(__DIR__), 'production' => false, 'origin' => 'https://operations.example.test', 'data_dir' => $argv[2], 'payments' => ['enabled' => false], 'shipping' => ['enabled' => false], 'mail' => ['enabled' => false]];
$GLOBALS['mahara_payments_test_config'] = ['enabled' => true];
$GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => 'TEST-ONLY-TERMINAL', 'password' => 'test-only-bank-password'];
$bankCalls = 0;
$GLOBALS['mahara_payments_test_transport'] = static function () use (&$bankCalls): never { $bankCalls++; throw new RuntimeException('Operations must never contact a bank.'); };
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/payments.php';
require_once dirname(__DIR__) . '/server/operations.php';

$checks = 0;
function operations_verify(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function operations_error(int $status, callable $action, string $message): void {
    try { $action(); }
    catch (MaharaHttpError $error) { operations_verify($error->status === $status, $message); return; }
    throw new RuntimeException($message . ': expected rejection.');
}
function operations_state(): array {
    $state = [];
    foreach (mahara_query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-z0-9_]+$/D', $table)) throw new RuntimeException('Unexpected fixture table name.');
        $state[$table] = mahara_query('SELECT * FROM "' . $table . '" ORDER BY rowid')->fetchAll();
    }
    return ['tables' => $state, 'totalChanges' => (int)mahara_query('SELECT total_changes()')->fetchColumn()];
}
function operations_movement(string $requestId, string $type, int $quantity, ?int $before, int $after, int $created, string $size = 'S', string $note = '', string $actor = 'КЛАДОВЩИК'): string {
    mahara_query('INSERT INTO inventory_movements(request_id,request_payload,product_id,product_title,size,type,quantity,before_quantity,after_quantity,note,actor,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', [$requestId, '{}', 'test-hoodie', 'ЧЁРНОЕ ХУДИ', $size, $type, $quantity, $before, $after, $note, $actor, $created]);
    return (string)mahara_db()->lastInsertId();
}
function operations_order(string $id, string $number, string $status, bool $stockApplied, array $items, int $created): void {
    mahara_query('INSERT INTO checkout_orders(id,number,request_id,request_payload,access_token,items_json,customer_json,delivery_json,total_amount,delivery_amount,status,bank_status,terminal_key,payment_id,payment_url,qr_svg,stock_applied,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $id, $number, 'request-' . $id, 'PRIVATE_REQUEST_PAYLOAD', 'PRIVATE_ACCESS_TOKEN', json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        json_encode(['name' => 'СЕКРЕТНЫЙ ПОКУПАТЕЛЬ', 'email' => 'private-customer@example.test', 'phone' => '+79991234567']), json_encode(['address' => 'PRIVATE_CUSTOMER_ADDRESS']),
        500000, 0, $status, $stockApplied ? 'CONFIRMED' : 'NEW', 'PRIVATE_TERMINAL_KEY', 'PRIVATE_PAYMENT_' . $id, 'https://qr.nspk.ru/PRIVATE_PAYMENT_LINK', '<svg>PRIVATE_QR</svg>', (int)$stockApplied, time() - 3600, $created, $created,
    ]);
}

operations_verify(mahara_operations_api('/api/content', 'GET') === false, 'Unrelated API routes pass through.');
operations_error(401, static fn() => mahara_operations_api('/api/admin/operations', 'GET'), 'Journal API requires an admin session.');
mahara_payments_schema();
mahara_query('INSERT INTO admins(username,password_hash,created_at) VALUES(?,?,?)', ['fixture-owner', 'not-a-login-password', time()]);
$cookie = mahara_random_token();
mahara_query('INSERT INTO sessions(token_hash,admin_id,csrf_token,expires_at) VALUES(?,?,?,?)', [hash('sha256', $cookie), 1, mahara_random_token(), time() + 3600]);
$_COOKIE[mahara_cookie_name()] = $cookie;
operations_error(405, static fn() => mahara_operations_api('/api/admin/operations', 'POST'), 'The journal exposes no mutation method.');
operations_verify(mahara_operations_list([])['pagination'] === ['page' => 1, 'pageSize' => 50, 'pages' => 1, 'total' => 0], 'An empty journal has a stable first page.');

$paidId = str_repeat('a', 32); $pendingId = str_repeat('b', 32); $failedId = str_repeat('c', 32); $missingId = str_repeat('d', 32); $baseTime = 1700000000;
$paidItems = [['id' => 'test-hoodie', 'title' => 'ЁЛОЧНОЕ ХУДИ', 'size' => 'S', 'qty' => 2, 'unitAmount' => 100000, 'image' => 'PRIVATE_ITEM_IMAGE'], ['id' => 'test-hoodie', 'title' => 'ЁЛОЧНОЕ ХУДИ', 'size' => 'M', 'qty' => 3]];
operations_order($paidId, 'MAH-TEST-PAID', 'paid', true, $paidItems, $baseTime + 500);
operations_order($pendingId, 'MAH-TEST-PENDING', 'payment_pending', false, [['id' => 'test-hoodie', 'title' => 'Ожидающий товар', 'size' => 'S', 'qty' => 3]], $baseTime + 500);
operations_order($failedId, 'MAH-TEST-FAILED', 'failed', false, [['id' => 'test-hoodie', 'title' => 'Отклонённый товар', 'size' => 'S', 'qty' => 1]], $baseTime + 500);
mahara_query('INSERT INTO checkout_reservations(order_id,product_id,size,quantity) VALUES(?,?,?,?)', [$pendingId, 'test-hoodie', 'S', 3]);
for ($index = 1; $index <= 105; $index++) operations_movement('fixture-receipt-' . $index, 'receipt', 1, $index === 1 ? null : $index - 1, $index, $baseTime + $index, 'S', $index === 60 ? 'Редкая поставка 50%_Брак' : 'Поставка партии');
operations_movement('fixture-receipt-m', 'receipt', 7, null, 7, $baseTime + 110, 'M', 'Начальный остаток', 'ПОСТАВЩИК');
operations_movement('fixture-writeoff', 'writeoff', 5, 105, 100, $baseTime + 150, 'S', 'Брак упаковки');
operations_movement('fixture-set', 'set', 80, 100, 80, $baseTime + 200, 'S', 'Инвентаризация');
$paidS = operations_movement('payment:' . $paidId . ':test-hoodie:S', 'writeoff', 2, 80, 78, $baseTime + 600, 'S', '', 'T-Bank');
$paidM = operations_movement('payment:' . $paidId . ':test-hoodie:M', 'writeoff', 3, 7, 4, $baseTime + 600, 'M', '', 'T-Bank');
$orphan = operations_movement('payment:' . $missingId . ':test-hoodie:S', 'writeoff', 1, 78, 77, $baseTime + 800, 'S', 'Историческая операция без заказа', 'T-Bank');
$prefixOrphan = operations_movement('payment:' . $paidId . ':test-hoodie:M:legacy-extra', 'writeoff', 1, 4, 3, $baseTime + 801, 'M', 'Неканоническая историческая связь', 'T-Bank');
foreach (['S' => 77, 'M' => 3] as $size => $quantity) mahara_query('INSERT INTO inventory_balances(product_id,size,quantity,threshold,initialized,revision,product_snapshot,updated_at) VALUES(?,?,?,?,?,?,?,?)', ['test-hoodie', $size, $quantity, 0, 1, 9, '{"id":"test-hoodie","title":"ЧЁРНОЕ ХУДИ"}', $baseTime + 801]);
mahara_query('UPDATE inventory_meta SET revision=42 WHERE id=1');
$before = operations_state();

$first = mahara_operations_list(['page' => '1', 'pageSize' => '100']); $second = mahara_operations_list(['page' => '2', 'pageSize' => '100']);
operations_verify($first['pagination'] === ['page' => 1, 'pageSize' => 100, 'pages' => 2, 'total' => 113] && count($first['entries']) === 100 && count($second['entries']) === 13, 'Pagination includes all events beyond one hundred rows.');
$entries = array_merge($first['entries'], $second['entries']); $ids = array_column($entries, 'id');
operations_verify(count(array_unique($ids)) === 113 && array_intersect(array_column($first['entries'], 'id'), array_column($second['entries'], 'id')) === [], 'Adjacent pages contain no duplicated events.');
operations_verify($ids === array_column(array_merge(mahara_operations_list(['pageSize' => 100])['entries'], mahara_operations_list(['page' => 2, 'pageSize' => 100])['entries']), 'id'), 'Equal timestamps retain deterministic pagination order.');
$times = array_column($entries, 'createdAt'); $sortedTimes = $times; rsort($sortedTimes);
operations_verify($times === $sortedTimes && array_slice($ids, 2, 3) === ['order:' . $failedId, 'order:' . $pendingId, 'order:' . $paidId], 'Events are newest first with stable order ties.');
operations_verify(mahara_operations_list(['page' => 999999999, 'pageSize' => 100])['pagination']['page'] === 2, 'A page beyond the end clamps to the last page.');
foreach (['receipt' => 106, 'writeoff' => 3, 'set' => 1, 'order' => 3] as $kind => $total) {
    $filtered = mahara_operations_list(['kind' => $kind, 'pageSize' => 100]);
    operations_verify($filtered['pagination']['total'] === $total && count(array_filter($filtered['entries'], static fn(array $entry): bool => $entry['kind'] !== $kind)) === 0, 'Operation kind filters count only their actual events.');
}
$paid = mahara_operations_list(['q' => 'mah-test-paid'])['entries'][0];
operations_verify($paid['id'] === 'order:' . $paidId && $paid['actor'] === 'T-Bank' && $paid['order']['stockApplied'] === true && $paid['order']['status'] === 'paid', 'Paid orders retain identity and confirmed stock state.');
operations_verify($paid['stockChanges'] === [['productId' => 'test-hoodie', 'size' => 'S', 'quantity' => 2, 'before' => 80, 'after' => 78], ['productId' => 'test-hoodie', 'size' => 'M', 'quantity' => 3, 'before' => 7, 'after' => 4]], 'An order includes only its two exact payment movements, never a prefix-shaped orphan.');
operations_verify(!in_array('movement:' . $paidS, $ids, true) && !in_array('movement:' . $paidM, $ids, true) && in_array('movement:' . $orphan, $ids, true) && in_array('movement:' . $prefixOrphan, $ids, true), 'Paid writeoffs are deduplicated while both kinds of orphan remain visible.');
$pending = mahara_operations_list(['q' => 'MAH-TEST-PENDING'])['entries'][0];
operations_verify($pending['order']['status'] === 'payment_pending' && $pending['actor'] === 'Покупатель' && $pending['stockChanges'] === [] && !$pending['order']['stockApplied'], 'An expired pending order stays pending without invented stock changes.');
operations_verify(array_keys($paid['items'][0]) === ['productId', 'title', 'size', 'quantity'] && $paid['items'][0]['quantity'] === 2, 'Order items expose only product identity and quantities.');
$encoded = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
foreach (['PRIVATE_', 'private-customer@example.test', '+79991234567', 'СЕКРЕТНЫЙ ПОКУПАТЕЛЬ', 'customer_json', 'request_payload', 'access_token', 'payment_id', 'terminal_key'] as $privateValue) operations_verify(!str_contains($encoded, $privateValue), 'Journal output excludes customer, bearer and provider details.');

foreach (['редкая ПОСТАВКА' => 1, 'кЛаДоВщИк' => 107, 'ёлочное' => 1, 'test-hoodie' => 113, '50%_' => 1, '%' => 1, '_' => 1, 'СЕКРЕТНЫЙ ПОКУПАТЕЛЬ' => 0, 'private-customer@example.test' => 0, "' OR 1=1 --" => 0] as $query => $total) operations_verify(mahara_operations_list(['q' => $query])['pagination']['total'] === $total, 'Search folds Russian case and treats percent, underscore and SQL text literally.');
$combined = mahara_operations_list(['q' => '  РЕДКАЯ ПОСТАВКА  ', 'kind' => 'receipt']);
operations_verify($combined['pagination']['total'] === 1 && $combined['query'] === 'РЕДКАЯ ПОСТАВКА', 'Search and kind filters combine while preserving trimmed display text.');
foreach ([['unknown' => 1], ['page' => 0], ['page' => '01'], ['page' => 1.5], ['pageSize' => 101], ['pageSize' => 0], ['kind' => 'refund'], ['q' => []], ['q' => str_repeat('я', 201)], ['q' => "bad\0query"]] as $invalid) operations_error(400, static fn() => mahara_operations_list($invalid), 'Malformed or excessive journal parameters are rejected.');
operations_verify($before === operations_state(), 'Listing, filtering and invalid requests leave all rows, balances, reservations and revisions unchanged.');
operations_verify($bankCalls === 0, 'No journal request contacts a payment provider.');
echo 'PASS: ' . $checks . " operations journal checks\n";
