<?php
declare(strict_types=1);

// Loaded after app.php. Stock is independent of catalog publication: removing a
// product never deletes its balance or its audit trail.
const MAHARA_INVENTORY_MAX_INTEGER = 9007199254740991;
const MAHARA_INVENTORY_MAX_BALANCE = 1000000000;

function mahara_inventory_schema(): void {
    static $ready = false;
    if ($ready) return;
    // Keep the retired threshold column for existing SQLite databases. Inserts
    // supply a neutral zero; existing values are never read or updated.
    mahara_db()->exec('CREATE TABLE IF NOT EXISTS inventory_meta (
            id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL CHECK(revision>=0)
        );
        INSERT OR IGNORE INTO inventory_meta (id, revision) VALUES (1, 0);
        CREATE TABLE IF NOT EXISTS inventory_balances (
            product_id TEXT NOT NULL, size TEXT NOT NULL,
            quantity INTEGER NOT NULL CHECK(quantity>=0 AND quantity<=1000000000),
            threshold INTEGER NOT NULL CHECK(threshold>=0 AND threshold<=1000000),
            initialized INTEGER NOT NULL CHECK(initialized IN (0,1)),
            revision INTEGER NOT NULL CHECK(revision>=1), product_snapshot TEXT NOT NULL,
            updated_at INTEGER NOT NULL, PRIMARY KEY (product_id, size)
        );
        CREATE TABLE IF NOT EXISTS inventory_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT, request_id TEXT NOT NULL UNIQUE,
            request_payload TEXT NOT NULL, product_id TEXT NOT NULL, product_title TEXT NOT NULL,
            size TEXT NOT NULL, type TEXT NOT NULL CHECK(type IN (\'receipt\',\'writeoff\',\'set\')),
            quantity INTEGER NOT NULL CHECK(quantity>=0 AND quantity<=1000000),
            before_quantity INTEGER CHECK(before_quantity>=0),
            after_quantity INTEGER NOT NULL CHECK(after_quantity>=0),
            note TEXT NOT NULL, actor TEXT NOT NULL, created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS inventory_history_product ON inventory_movements(product_id, id DESC);');
    $ready = true;
}

function mahara_inventory_transaction(callable $operation, bool $write = false): mixed {
    mahara_inventory_schema();
    $db = mahara_db();
    // The catalog and the balance are checked under the same SQLite write lock.
    // A concurrent catalog publication cannot invalidate an accepted mutation.
    $db->exec($write ? 'BEGIN IMMEDIATE' : 'BEGIN');
    try {
        $result = $operation();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) { /* Preserve the original error. */ }
        throw $error;
    }
}

function mahara_inventory_variant(string $size, ?array $row = null, bool $archived = false): array {
    return [
        'size' => $size, 'quantity' => $row ? (int)$row['quantity'] : 0,
        'initialized' => $row ? (bool)$row['initialized'] : false,
        'revision' => $row ? (int)$row['revision'] : 0, 'archived' => $archived,
    ];
}

function mahara_inventory_status(array $variants): string {
    $initialized = array_filter($variants, static fn(array $variant): bool => $variant['initialized']);
    if (!$initialized) return 'untracked';
    $total = array_sum(array_column($variants, 'quantity'));
    if (count($initialized) === count($variants) && $total === 0) return 'out';
    return 'in_stock';
}

// Call within a transaction to make catalog, balances, revision and history one snapshot.
function mahara_inventory_read(): array {
    $content = mahara_content();
    $revision = (int)mahara_query('SELECT revision FROM inventory_meta WHERE id=1')->fetchColumn();
    $rows = mahara_query('SELECT rowid, product_id, size, quantity, initialized, revision, product_snapshot, updated_at FROM inventory_balances ORDER BY updated_at DESC, rowid DESC')->fetchAll();
    $balances = []; $savedProducts = [];
    foreach ($rows as $row) {
        $balances[$row['product_id']][$row['size']] = $row;
        if (!isset($savedProducts[$row['product_id']])) $savedProducts[$row['product_id']] = json_decode($row['product_snapshot'], true, 64, JSON_THROW_ON_ERROR);
    }
    $products = []; $active = [];
    $summary = ['products' => count($content['products']), 'units' => 0, 'out' => 0, 'untracked' => 0];
    foreach ($content['products'] as $product) {
        $id = $product['id']; $active[$id] = true; $variants = [];
        foreach ($product['sizes'] as $size) $variants[] = mahara_inventory_variant($size, $balances[$id][$size] ?? null);
        $total = array_sum(array_column($variants, 'quantity'));
        $status = mahara_inventory_status($variants);
        $summary['units'] += $total;
        if (isset($summary[$status])) $summary[$status]++;
        foreach (MAHARA_SIZES as $size) {
            if (isset($balances[$id][$size]) && !in_array($size, $product['sizes'], true)) $variants[] = mahara_inventory_variant($size, $balances[$id][$size], true);
        }
        $products[] = array_merge($product, ['archived' => false, 'variants' => $variants, 'total' => $total, 'status' => $status]);
    }
    foreach ($savedProducts as $id => $product) {
        if (isset($active[$id])) continue;
        $variants = [];
        foreach (MAHARA_SIZES as $size) if (isset($balances[$id][$size])) $variants[] = mahara_inventory_variant($size, $balances[$id][$size], true);
        $products[] = array_merge($product, [
            'archived' => true, 'variants' => $variants,
            'total' => array_sum(array_column($variants, 'quantity')), 'status' => 'archived',
        ]);
    }
    $movements = [];
    foreach (mahara_query('SELECT * FROM inventory_movements ORDER BY id DESC LIMIT 100')->fetchAll() as $row) {
        $movements[] = [
            'id' => (int)$row['id'], 'productId' => $row['product_id'], 'productTitle' => $row['product_title'],
            'size' => $row['size'], 'type' => $row['type'], 'quantity' => (int)$row['quantity'],
            'before' => $row['before_quantity'] === null ? null : (int)$row['before_quantity'], 'after' => (int)$row['after_quantity'],
            'note' => $row['note'], 'actor' => $row['actor'], 'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['created_at']),
        ];
    }
    return ['revision' => $revision, 'products' => $products, 'movements' => $movements, 'summary' => $summary];
}

function mahara_inventory_snapshot(): array {
    return mahara_inventory_transaction(static fn(): array => mahara_inventory_read());
}

function mahara_inventory_identity(mixed $productId, mixed $size): array {
    if (!is_string($productId) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $productId)) mahara_fail('Некорректный код товара.');
    if (!is_string($size) || !in_array($size, MAHARA_SIZES, true)) mahara_fail('Некорректный размер товара.');
    return [$productId, $size];
}

// This lookup must remain inside BEGIN IMMEDIATE, including for a new balance.
function mahara_inventory_product(string $productId, string $size): array {
    foreach (mahara_content()['products'] as $product) {
        if ($product['id'] !== $productId) continue;
        if (!in_array($size, $product['sizes'], true)) throw new MaharaHttpError(409, 'Этот размер удалён из каталога. Обновите остатки.');
        return $product;
    }
    throw new MaharaHttpError(409, 'Товар удалён из каталога. Обновите остатки.');
}

function mahara_inventory_current(string $productId, string $size, int $expectedRevision): array {
    $row = mahara_query('SELECT quantity, initialized, revision FROM inventory_balances WHERE product_id=? AND size=?', [$productId, $size])->fetch();
    $variant = mahara_inventory_variant($size, $row ?: null);
    if ($variant['revision'] !== $expectedRevision) throw new MaharaHttpError(409, 'Остаток уже изменён другим пользователем. Обновите остатки и повторите действие.');
    return $variant;
}

function mahara_inventory_save(array $product, array $variant): void {
    mahara_query('INSERT INTO inventory_balances (product_id, size, quantity, threshold, initialized, revision, product_snapshot, updated_at)
        VALUES (?, ?, ?, 0, ?, ?, ?, ?) ON CONFLICT(product_id, size) DO UPDATE SET
        quantity=excluded.quantity, initialized=excluded.initialized,
        revision=excluded.revision, product_snapshot=excluded.product_snapshot, updated_at=excluded.updated_at', [
        $product['id'], $variant['size'], $variant['quantity'], (int)$variant['initialized'],
        $variant['revision'] + 1, json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), time(),
    ]);
    mahara_query('UPDATE inventory_meta SET revision=revision+1 WHERE id=1');
}

function mahara_inventory_movement(mixed $input, string $actor): array {
    mahara_object($input, ['requestId', 'productId', 'size', 'type', 'quantity', 'expectedRevision', 'note'], 'Движение товара');
    if (!is_string($input['requestId']) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $input['requestId'])) mahara_fail('Некорректный идентификатор операции.');
    [$productId, $size] = mahara_inventory_identity($input['productId'], $input['size']);
    if (!is_string($input['type']) || !in_array($input['type'], ['receipt', 'writeoff', 'set'], true)) mahara_fail('Неизвестное движение товара.');
    $type = $input['type'];
    $quantity = mahara_integer($input['quantity'], $type === 'set' ? 0 : 1, 1000000, 'Количество');
    $expectedRevision = mahara_integer($input['expectedRevision'], 0, MAHARA_INVENTORY_MAX_INTEGER, 'Версия остатка');
    $note = mahara_text($input['note'], 'Комментарий', 300, $type !== 'receipt');
    $requestId = strtolower($input['requestId']);
    $payload = json_encode([
        'requestId' => $requestId, 'productId' => $productId, 'size' => $size, 'type' => $type,
        'quantity' => $quantity, 'expectedRevision' => $expectedRevision, 'note' => $note,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return mahara_inventory_transaction(static function () use ($requestId, $payload, $productId, $size, $type, $quantity, $expectedRevision, $note, $actor): array {
        $previous = mahara_query('SELECT request_payload FROM inventory_movements WHERE request_id=?', [$requestId])->fetchColumn();
        if ($previous !== false) {
            if ($previous !== $payload) throw new MaharaHttpError(409, 'Идентификатор операции уже использован с другими данными.');
            return ['snapshot' => mahara_inventory_read(), 'created' => false];
        }
        $product = mahara_inventory_product($productId, $size);
        $variant = mahara_inventory_current($productId, $size, $expectedRevision);
        $before = $variant['quantity']; $beforeInitialized = $variant['initialized'];
        $after = match ($type) { 'receipt' => $before + $quantity, 'writeoff' => $before - $quantity, 'set' => $quantity };
        if ($after < 0) throw new MaharaHttpError(409, 'Недостаточно товара для списания. Обновите остатки.');
        if ($after > MAHARA_INVENTORY_MAX_BALANCE) mahara_fail('Остаток превышает допустимое количество (1 миллиард единиц).');
        // Pending online orders own their reserved units until the bank confirms
        // payment or cancellation. Check inside the same write transaction.
        if (mahara_query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='checkout_reservations'")->fetchColumn()) {
            $reserved = (int)mahara_query('SELECT COALESCE(SUM(quantity),0) FROM checkout_reservations WHERE product_id=? AND size=?', [$productId, $size])->fetchColumn();
            if ($after < $reserved) throw new MaharaHttpError(409, 'Часть товара зарезервирована в заказах. Остаток нельзя уменьшить ниже резерва.');
        }
        $variant['quantity'] = $after; $variant['initialized'] = true;
        mahara_inventory_save($product, $variant);
        mahara_query('INSERT INTO inventory_movements (request_id, request_payload, product_id, product_title, size, type, quantity, before_quantity, after_quantity, note, actor, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $requestId, $payload, $productId, $product['title'], $size, $type, $quantity, $beforeInitialized ? $before : null, $after, $note, $actor, time(),
        ]);
        return ['snapshot' => mahara_inventory_read(), 'created' => true];
    }, true);
}

function mahara_inventory_api(string $path, string $method): bool {
    $methods = ['/api/admin/inventory' => 'GET', '/api/admin/inventory/movements' => 'POST'];
    if (!isset($methods[$path])) return false;
    if ($method !== $methods[$path]) throw new MaharaHttpError(405, 'Метод не поддерживается.');
    $session = mahara_require_session();
    if ($method === 'GET') mahara_json(mahara_inventory_snapshot());
    mahara_require_csrf($session);
    $input = mahara_read_json(8192);
    $result = mahara_inventory_movement($input, $session['username']);
    mahara_json($result['snapshot'], $result['created'] ? 201 : 200);
}
