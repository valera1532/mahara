<?php
declare(strict_types=1);

// Run: php tests/inventory.php. The supervisor owns a temporary directory and
// waits for the worker to exit before removing SQLite files (also on Windows).
function inventory_test_process(string $mode): array {
    $process = proc_open([PHP_BINARY, __FILE__, $mode], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start inventory test process.');
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$mode = $argv[1] ?? '';
if ($mode !== '--worker' && $mode !== '--snapshot') {
    $privateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mahara-inventory-' . bin2hex(random_bytes(12));
    if (!mkdir($privateDir, 0700)) throw new RuntimeException('Cannot create inventory test directory.');
    putenv('APP_ENV=development'); putenv('DATA_DIR=' . $privateDir); putenv('APP_ORIGIN=http://127.0.0.1:8765');
    $status = 1;
    try {
        $result = inventory_test_process('--worker');
        echo $result['stdout']; fwrite(STDERR, $result['stderr']); $status = $result['status'];
    } finally {
        foreach (['mahara.sqlite', 'mahara.sqlite-wal', 'mahara.sqlite-shm'] as $filename) {
            $path = $privateDir . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path) && !is_link($path)) unlink($path);
        }
        rmdir($privateDir);
    }
    exit($status);
}

define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/inventory.php';
if ($mode === '--snapshot') {
    echo json_encode(mahara_inventory_snapshot(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$checks = 0;
function inventory_verify(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function inventory_error(int $status, callable $action, string $message): void {
    try { $action(); }
    catch (MaharaHttpError $error) {
        inventory_verify($error->status === $status, $message . ': expected ' . $status . ', received ' . $error->status);
        return;
    }
    throw new RuntimeException($message . ': expected rejection.');
}
function inventory_product(array $snapshot, string $id): array {
    foreach ($snapshot['products'] as $product) if ($product['id'] === $id) return $product;
    throw new RuntimeException('Missing inventory product: ' . $id);
}
function inventory_variant(array $snapshot, string $id, string $size): array {
    foreach (inventory_product($snapshot, $id)['variants'] as $variant) if ($variant['size'] === $size) return $variant;
    throw new RuntimeException('Missing inventory variant: ' . $id . '/' . $size);
}
function inventory_request(string $id, string $size, string $type, int $quantity, int $revision, ?string $note = null): array {
    static $sequence = 0;
    return ['requestId' => sprintf('00000000-0000-4000-8000-%012d', ++$sequence), 'productId' => $id, 'size' => $size,
        'type' => $type, 'quantity' => $quantity, 'expectedRevision' => $revision, 'note' => $note ?? ($type === 'receipt' ? '' : 'Контроль остатков')];
}
function inventory_publish(array $document): void {
    unset($document['revision']);
    $document = mahara_validate_content($document, false);
    mahara_query('UPDATE content SET revision=revision+1, document=?, updated_at=? WHERE id=1', [json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), time()]);
}

inventory_verify(mahara_inventory_api('/api/content', 'GET') === false, 'Other routes pass through.');
foreach ([['/api/admin/inventory', 'GET'], ['/api/admin/inventory/movements', 'POST']] as [$route, $method]) {
    inventory_error(401, static fn() => mahara_inventory_api($route, $method), 'Inventory route requires a session');
}
inventory_verify(mahara_inventory_api('/api/admin/inventory/threshold', 'PUT') === false, 'The retired threshold endpoint is not registered.');
inventory_verify(!function_exists('mahara_inventory_threshold'), 'The retired threshold mutation no longer exists.');
inventory_error(405, static fn() => mahara_inventory_api('/api/admin/inventory', 'DELETE'), 'Known routes reject unsupported methods');
mahara_query('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)', ['inventory-owner', 'not-used-for-session-tests', time()]);
$token = mahara_random_token(); $csrf = mahara_random_token();
mahara_query('INSERT INTO sessions (token_hash, admin_id, csrf_token, expires_at) VALUES (?, ?, ?, ?)', [hash('sha256', $token), 1, $csrf, time() + 3600]);
$_COOKIE[mahara_cookie_name()] = $token;
$_SERVER['HTTP_ORIGIN'] = 'http://127.0.0.1:8765';
inventory_error(403, static fn() => mahara_inventory_api('/api/admin/inventory/movements', 'POST'), 'Movements require CSRF');
$_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf; $_SERVER['HTTP_ORIGIN'] = 'https://other.example';
inventory_error(403, static fn() => mahara_inventory_api('/api/admin/inventory/movements', 'POST'), 'Movements reject another origin');
$_SERVER['HTTP_ORIGIN'] = 'http://127.0.0.1:8765';
inventory_error(415, static fn() => mahara_inventory_api('/api/admin/inventory/movements', 'POST'), 'Mutation body must be JSON');

$document = mahara_content();
$first = $document['products'][0]; $first['sizes'] = ['S', 'M'];
$second = $document['products'][1]; $second['sizes'] = ['L'];
$document['products'] = [$first, $second]; inventory_publish($document);
$id = $first['id']; $secondId = $second['id'];
$snapshot = mahara_inventory_snapshot();
inventory_verify($snapshot['revision'] === 0 && $snapshot['movements'] === [], 'Empty inventory starts at revision zero with no fabricated movements.');
inventory_verify($snapshot['summary'] === ['products' => 2, 'units' => 0, 'out' => 0, 'untracked' => 2], 'Unknown balances are untracked rather than out of stock.');
inventory_verify(inventory_variant($snapshot, $id, 'S') === ['size' => 'S', 'quantity' => 0, 'initialized' => false, 'revision' => 0, 'archived' => false], 'New catalog sizes have unknown balance and revision zero, without a replenishment threshold.');

$receipt = inventory_request($id, 'S', 'receipt', 8, 0);
$result = mahara_inventory_movement($receipt, 'inventory-owner'); $snapshot = $result['snapshot'];
inventory_verify($result['created'] && $snapshot['revision'] === 1, 'Receipt advances global revision.');
inventory_verify(inventory_variant($snapshot, $id, 'S')['quantity'] === 8 && inventory_variant($snapshot, $id, 'S')['revision'] === 1, 'Receipt initializes and advances variant revision.');
inventory_verify(inventory_product($snapshot, $id)['status'] === 'in_stock', 'Known positive balance can coexist with an untracked size.');
$movement = $snapshot['movements'][0];
inventory_verify($movement['before'] === null && $movement['after'] === 8 && $movement['actor'] === 'inventory-owner' && $movement['productTitle'] === $first['title'], 'First receipt records unknown previous balance, actor and title.');
inventory_verify((bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $movement['createdAt']), 'History timestamps are UTC ISO 8601.');
$again = mahara_inventory_movement($receipt, 'another-owner');
inventory_verify(!$again['created'] && $again['snapshot'] === $snapshot, 'Retrying an operation is idempotent even for another authenticated user.');
$tampered = $receipt; $tampered['quantity'] = 7;
inventory_error(409, static fn() => mahara_inventory_movement($tampered, 'inventory-owner'), 'A request ID cannot be reused with another payload');
inventory_error(409, static fn() => mahara_inventory_movement(inventory_request($id, 'S', 'writeoff', 9, 1), 'inventory-owner'), 'Writeoff cannot make quantity negative');
inventory_verify(mahara_inventory_snapshot() === $snapshot, 'Rejected operations roll back quantity, revision and history.');

$snapshot = mahara_inventory_movement(inventory_request($id, 'S', 'writeoff', 3, 1, 'Продано'), 'inventory-owner')['snapshot'];
inventory_verify(inventory_variant($snapshot, $id, 'S')['quantity'] === 5 && $snapshot['movements'][0]['before'] === 8 && $snapshot['movements'][0]['after'] === 5, 'Writeoff records before and after balances.');
inventory_error(409, static fn() => mahara_inventory_movement(inventory_request($id, 'S', 'receipt', 1, 1), 'inventory-owner'), 'Stale movements are rejected');
// Existing deployments may contain a high warning value and threshold-only
// rows with revisions but unknown quantities. Preserve those records verbatim;
// removing the feature must not reset revisions or manufacture stock history.
mahara_query('UPDATE inventory_balances SET threshold=1000000, revision=revision+1 WHERE product_id=? AND size=?', [$id, 'S']);
mahara_query('INSERT INTO inventory_balances (product_id, size, quantity, threshold, initialized, revision, product_snapshot, updated_at) VALUES (?, ?, 0, 2, 0, 1, ?, ?)', [$id, 'M', json_encode($first, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), time()]);
mahara_query('UPDATE inventory_meta SET revision=revision+2 WHERE id=1');
$snapshot = mahara_inventory_snapshot();
inventory_verify(inventory_variant($snapshot, $id, 'S')['revision'] === 3 && inventory_product($snapshot, $id)['status'] === 'in_stock', 'A legacy high threshold never changes stock status.');
inventory_verify(count($snapshot['movements']) === 2 && $snapshot['revision'] === 4, 'Legacy revisions and movement history are preserved.');
inventory_verify(inventory_variant($snapshot, $id, 'M')['initialized'] === false && inventory_variant($snapshot, $id, 'M')['revision'] === 1, 'A legacy threshold-only row remains untracked with its existing revision.');
inventory_verify(!str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), '"threshold"') && !array_key_exists('low', $snapshot['summary']), 'The API snapshot exposes neither thresholds nor low-stock summary.');
inventory_error(409, static fn() => mahara_inventory_movement(inventory_request($id, 'S', 'receipt', 1, 2), 'inventory-owner'), 'Legacy variant revisions still prevent stale stock writes');
$snapshot = mahara_inventory_movement(inventory_request($id, 'S', 'set', 0, 3), 'inventory-owner')['snapshot'];
inventory_verify(inventory_product($snapshot, $id)['status'] === 'in_stock', 'Zero in one size and unknown in another does not imply all sizes are out.');
$snapshot = mahara_inventory_movement(inventory_request($id, 'M', 'set', 0, 1), 'inventory-owner')['snapshot'];
inventory_verify($snapshot['movements'][0]['before'] === null, 'Initializing a legacy threshold-only variant still records unknown prior quantity.');
inventory_verify(inventory_product($snapshot, $id)['status'] === 'out' && $snapshot['summary']['out'] === 1, 'Explicit zero in all sizes is out of stock.');
$snapshot = mahara_inventory_movement(inventory_request($id, 'S', 'set', 5, 4), 'inventory-owner')['snapshot'];
inventory_verify($snapshot['movements'][0]['before'] === 0 && $snapshot['movements'][0]['after'] === 5, 'Later inventory count distinguishes explicit zero from unknown balance.');
$snapshot = mahara_inventory_movement(inventory_request($secondId, 'L', 'receipt', 2, 0), 'inventory-owner')['snapshot'];
inventory_verify($snapshot['summary']['units'] === 7 && $snapshot['summary']['out'] === 0 && $snapshot['summary']['untracked'] === 0, 'Summary totals current active balances without replenishment warnings.');
inventory_verify(inventory_product($snapshot, $id)['status'] === 'in_stock' && inventory_product($snapshot, $secondId)['status'] === 'in_stock', 'Any positive initialized balance remains in stock, even one or two units.');
inventory_verify((int)mahara_query('SELECT threshold FROM inventory_balances WHERE product_id=? AND size=?', [$id, 'S'])->fetchColumn() === 1000000 && (int)mahara_query('SELECT threshold FROM inventory_balances WHERE product_id=? AND size=?', [$id, 'M'])->fetchColumn() === 2, 'Stock writes preserve unused legacy column values without relying on them.');

$invalidMutations = [
    static function (array &$input): void { $input['quantity'] = '1'; },
    static function (array &$input): void { $input['quantity'] = 1.5; },
    static function (array &$input): void { $input['quantity'] = 0; },
    static function (array &$input): void { $input['quantity'] = -1; },
    static function (array &$input): void { $input['quantity'] = 1000001; },
    static function (array &$input): void { $input['expectedRevision'] = -1; },
    static function (array &$input): void { $input['expectedRevision'] = 1.5; },
    static function (array &$input): void { $input['type'] = 'delete'; },
    static function (array &$input): void { $input['size'] = 'XXXL'; },
    static function (array &$input): void { $input['productId'] = '../../secrets'; },
    static function (array &$input): void { $input['requestId'] = 'not-a-uuid'; },
    static function (array &$input): void { $input['note'] = str_repeat('я', 301); },
    static function (array &$input): void { $input['note'] = null; },
    static function (array &$input): void { $input['extra'] = true; },
    static function (array &$input): void { unset($input['note']); },
];
foreach ($invalidMutations as $mutate) {
    $input = inventory_request($id, 'S', 'receipt', 1, 5); $mutate($input);
    inventory_error(400, static fn() => mahara_inventory_movement($input, 'inventory-owner'), 'Invalid movement payload');
}
foreach (['writeoff', 'set'] as $type) {
    $input = inventory_request($id, 'S', $type, 1, 5, '  ');
    inventory_error(400, static fn() => mahara_inventory_movement($input, 'inventory-owner'), 'Writeoff and inventory count require a reason');
}
inventory_error(409, static fn() => mahara_inventory_movement(inventory_request('missing-product', 'S', 'receipt', 1, 0), 'inventory-owner'), 'Unknown catalog product is rejected');
inventory_error(409, static fn() => mahara_inventory_movement(inventory_request($id, 'XS', 'receipt', 1, 0), 'inventory-owner'), 'Unavailable catalog size is rejected');
inventory_verify(mahara_inventory_snapshot() === $snapshot, 'All invalid requests leave the database unchanged.');

$persisted = inventory_test_process('--snapshot');
inventory_verify($persisted['status'] === 0, 'A new PHP process can reopen inventory: ' . $persisted['stderr']);
inventory_verify(json_decode($persisted['stdout'], true, 64, JSON_THROW_ON_ERROR) === $snapshot, 'Balances, revisions and history survive process restart.');

$document['products'][0]['title'] = 'Обновленное название';
$document['products'][0]['sizes'] = ['M']; inventory_publish($document);
$snapshot = mahara_inventory_snapshot();
$archivedSize = inventory_variant($snapshot, $id, 'S');
inventory_verify($archivedSize['archived'] && $archivedSize['quantity'] === 5, 'Removing a size preserves its archived balance.');
inventory_verify(inventory_product($snapshot, $id)['title'] === 'Обновленное название' && inventory_product($snapshot, $id)['total'] === 0, 'Active product follows catalog and excludes archived sizes from active total.');
inventory_verify($snapshot['summary']['units'] === 2 && $snapshot['summary']['products'] === 2 && $snapshot['summary']['out'] === 1, 'Summary excludes archived sizes.');
inventory_error(409, static fn() => mahara_inventory_movement(inventory_request($id, 'S', 'receipt', 1, 5), 'inventory-owner'), 'A removed size cannot accept a new movement');
inventory_verify(mahara_inventory_movement($receipt, 'inventory-owner')['created'] === false, 'An accepted request remains retryable after its size is removed.');
inventory_verify(count(array_filter($snapshot['movements'], static fn(array $row): bool => $row['productTitle'] === $first['title'])) === 5, 'Historical titles are immutable after a catalog rename.');

$document['products'] = [$document['products'][1]]; inventory_publish($document);
$snapshot = mahara_inventory_snapshot();
$archivedProduct = inventory_product($snapshot, $id);
inventory_verify($archivedProduct['archived'] && $archivedProduct['status'] === 'archived' && $archivedProduct['total'] === 5 && count($archivedProduct['variants']) === 2, 'Removing a product gives it archived status and preserves every stored size and balance.');
inventory_verify($snapshot['summary'] === ['products' => 1, 'units' => 2, 'out' => 0, 'untracked' => 0], 'Summary excludes archived products.');
inventory_error(409, static fn() => mahara_inventory_movement(inventory_request($id, 'M', 'receipt', 1, 2), 'inventory-owner'), 'A removed product cannot accept a new movement');
inventory_verify(mahara_inventory_movement($receipt, 'inventory-owner')['created'] === false, 'An accepted request remains retryable after its product is removed.');

$document['products'][] = $first; inventory_publish($document);
$snapshot = mahara_inventory_snapshot();
inventory_verify(!inventory_product($snapshot, $id)['archived'] && !inventory_variant($snapshot, $id, 'S')['archived'] && inventory_variant($snapshot, $id, 'S')['quantity'] === 5, 'Restoring a product and size restores their existing balance.');

// The stored trail remains complete; only the response is limited to the latest 100.
$revision = inventory_variant($snapshot, $secondId, 'L')['revision'];
for ($index = 0; $index < 101; $index++) {
    $snapshot = mahara_inventory_movement(inventory_request($secondId, 'L', 'receipt', 1, $revision++), 'history-owner')['snapshot'];
}
inventory_verify(count($snapshot['movements']) === 100 && (int)mahara_query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn() === 107, 'History response is bounded without deleting stored movements.');
inventory_verify($snapshot['movements'][0]['id'] > $snapshot['movements'][99]['id'], 'History is newest first.');
inventory_verify(mahara_inventory_movement($receipt, 'inventory-owner')['created'] === false, 'Idempotency still holds after a request leaves the response history window.');

// Seed the upper-bound fixture directly rather than performing 1,000 receipts.
mahara_query('UPDATE inventory_balances SET quantity=? WHERE product_id=? AND size=?', [MAHARA_INVENTORY_MAX_BALANCE, $secondId, 'L']);
$bounded = mahara_inventory_snapshot();
inventory_error(400, static fn() => mahara_inventory_movement(inventory_request($secondId, 'L', 'receipt', 1, $revision), 'inventory-owner'), 'A balance cannot exceed the safe aggregate limit');
inventory_verify(mahara_inventory_snapshot() === $bounded, 'Overflow rejection rolls back revision and history.');

echo "PASS: $checks inventory checks\n";
