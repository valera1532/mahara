<?php
declare(strict_types=1);

// Public stock view: never initialise balances or release reservations here.
// Unknown stock is deliberately not offered for purchase.
function mahara_catalog_availability(): array {
    $db = mahara_db();
    $db->beginTransaction();
    try {
        $products = mahara_content()['products'];
        $tables = mahara_query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('inventory_balances','checkout_reservations')")->fetchAll(PDO::FETCH_COLUMN);
        $balances = []; $reserved = [];
        if (in_array('inventory_balances', $tables, true)) {
            foreach (mahara_query('SELECT product_id,size,quantity,initialized FROM inventory_balances')->fetchAll() as $row) $balances[$row['product_id']][$row['size']] = $row;
        }
        if (in_array('checkout_reservations', $tables, true)) {
            foreach (mahara_query('SELECT product_id,size,SUM(quantity) AS quantity FROM checkout_reservations GROUP BY product_id,size')->fetchAll() as $row) $reserved[$row['product_id']][$row['size']] = (int)$row['quantity'];
        }
        $result = [];
        foreach ($products as $product) {
            $variants = [];
            foreach ($product['sizes'] as $size) {
                $stock = $balances[$product['id']][$size] ?? null;
                $known = $stock && (bool)$stock['initialized'];
                $quantity = $known ? max(0, (int)$stock['quantity'] - ($reserved[$product['id']][$size] ?? 0)) : 0;
                $variants[] = ['size' => $size, 'status' => !$known ? 'untracked' : ($quantity > 0 ? 'available' : 'out'), 'maxQty' => min(20, $quantity)];
            }
            $result[] = ['id' => $product['id'], 'variants' => $variants];
        }
        $db->commit();
        return ['products' => $result];
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
