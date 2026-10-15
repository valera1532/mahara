<?php
declare(strict_types=1);

// Read-only journal. Opening this list never queries the bank or changes stock.
function mahara_operations_list(array $input): array {
    if (array_diff(array_keys($input), ['page', 'pageSize', 'q', 'kind'])) mahara_fail('Неизвестные параметры журнала.');
    $page = mahara_integer(isset($input['page']) && is_string($input['page']) && preg_match('/^[1-9][0-9]{0,8}$/D', $input['page']) ? (int)$input['page'] : ($input['page'] ?? 1), 1, 999999999, 'Страница');
    $pageSize = mahara_integer(isset($input['pageSize']) && is_string($input['pageSize']) && preg_match('/^[0-9]{1,3}$/D', $input['pageSize']) ? (int)$input['pageSize'] : ($input['pageSize'] ?? 50), 1, 100, 'Операций на странице');
    $query = mahara_text($input['q'] ?? '', 'Поиск', 200);
    $kind = $input['kind'] ?? 'all';
    if (!is_string($kind) || !in_array($kind, ['all', 'receipt', 'order', 'writeoff', 'set'], true)) mahara_fail('Неизвестный тип операции.');
    mahara_payments_schema();
    $fold = static function (string $value): string {
        if (function_exists('mb_strtolower')) return mb_strtolower($value, 'UTF-8');
        static $russian;
        $russian ??= array_combine(preg_split('//u', 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', -1, PREG_SPLIT_NO_EMPTY), preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY));
        return strtr(strtolower($value), $russian);
    };
    mahara_db()->sqliteCreateFunction('mahara_operations_search', static function (string $context, string $items) use ($fold): string {
        $values = [$context];
        if ($items !== '') foreach (json_decode($items, true, 64, JSON_THROW_ON_ERROR) as $item) array_push($values, $item['title'] ?? '', $item['id'] ?? '', $item['size'] ?? '');
        return $fold(implode(' ', $values));
    }, 2);
    // A paid order owns its payment:* movements: show the order once, including
    // its actual stock changes. Orphan movements remain visible as writeoffs.
    $journal = "WITH journal AS (
        SELECT m.type kind,m.id movement_id,'' order_id,m.created_at occurred_at,
            'm:'||printf('%020d',m.id) sort_id,
            m.product_title||' '||m.product_id||' '||m.size||' '||m.actor||' '||m.note context,'' items
        FROM inventory_movements m
        WHERE NOT EXISTS (SELECT 1 FROM checkout_orders o WHERE o.id=substr(m.request_id,9,32) AND m.request_id='payment:'||o.id||':'||m.product_id||':'||m.size)
        UNION ALL
        SELECT 'order',NULL,o.id,o.created_at,'o:'||o.id,o.number,o.items_json FROM checkout_orders o
    ) ";
    $where = []; $params = [];
    if ($kind !== 'all') { $where[] = 'kind=?'; $params[] = $kind; }
    if ($query !== '') { $where[] = 'instr(mahara_operations_search(context,items),?)>0'; $params[] = $fold($query); }
    $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $db = mahara_db(); $db->exec('BEGIN');
    try {
        $total = (int)mahara_query($journal . 'SELECT COUNT(*) FROM journal' . $condition, $params)->fetchColumn();
        $pages = max(1, (int)ceil($total / $pageSize)); $page = min($page, $pages);
        $entries = [];
        foreach (mahara_query($journal . 'SELECT kind,movement_id,order_id,occurred_at FROM journal' . $condition . ' ORDER BY occurred_at DESC,sort_id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize), $params)->fetchAll() as $row) {
            if ($row['kind'] === 'order') {
                $order = mahara_query('SELECT id,number,status,total_amount,stock_applied,items_json FROM checkout_orders WHERE id=?', [$row['order_id']])->fetch();
                $items = array_map(static fn(array $item): array => ['productId' => $item['id'], 'title' => $item['title'], 'size' => $item['size'], 'quantity' => (int)$item['qty']], json_decode($order['items_json'], true, 64, JSON_THROW_ON_ERROR));
                $prefix = 'payment:' . $order['id'] . ':';
                $changes = [];
                foreach (mahara_query("SELECT product_id,size,quantity,before_quantity,after_quantity FROM inventory_movements WHERE request_id>=? AND request_id<? AND request_id=?||product_id||':'||size ORDER BY id", [$prefix, $prefix . '~', $prefix])->fetchAll() as $movement) {
                    $changes[] = ['productId' => $movement['product_id'], 'size' => $movement['size'], 'quantity' => (int)$movement['quantity'], 'before' => $movement['before_quantity'] === null ? null : (int)$movement['before_quantity'], 'after' => (int)$movement['after_quantity']];
                }
                $entries[] = ['id' => 'order:' . $order['id'], 'kind' => 'order', 'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['occurred_at']), 'actor' => (bool)$order['stock_applied'] ? 'T-Bank' : 'Покупатель', 'items' => $items, 'stockChanges' => $changes, 'comment' => '', 'order' => ['id' => $order['id'], 'number' => $order['number'], 'status' => $order['status'], 'totalAmount' => (int)$order['total_amount'], 'stockApplied' => (bool)$order['stock_applied']]];
            } else {
                $movement = mahara_query('SELECT id,product_id,product_title,size,type,quantity,before_quantity,after_quantity,note,actor FROM inventory_movements WHERE id=?', [$row['movement_id']])->fetch();
                $change = ['productId' => $movement['product_id'], 'size' => $movement['size'], 'quantity' => (int)$movement['quantity'], 'before' => $movement['before_quantity'] === null ? null : (int)$movement['before_quantity'], 'after' => (int)$movement['after_quantity']];
                $entries[] = ['id' => 'movement:' . $movement['id'], 'kind' => $movement['type'], 'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['occurred_at']), 'actor' => $movement['actor'], 'items' => [['productId' => $movement['product_id'], 'title' => $movement['product_title'], 'size' => $movement['size'], 'quantity' => (int)$movement['quantity']]], 'stockChanges' => [$change], 'comment' => $movement['note'], 'order' => null];
            }
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK'); throw $error;
    }
    return ['entries' => $entries, 'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'pages' => $pages, 'total' => $total], 'query' => $query, 'kind' => $kind];
}

function mahara_operations_api(string $path, string $method): bool {
    if ($path !== '/api/admin/operations') return false;
    mahara_require_session();
    if ($method !== 'GET') throw new MaharaHttpError(405, 'Метод не поддерживается.');
    mahara_json(mahara_operations_list($_GET));
}
