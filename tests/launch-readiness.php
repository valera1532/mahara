<?php
declare(strict_types=1);

// Isolated legal/availability/backup integration. No private config or providers.
if (($argv[1] ?? '') !== '--worker') {
    $directory = sys_get_temp_dir() . '/mahara-launch-' . bin2hex(random_bytes(10));
    mkdir($directory, 0700);
    $GLOBALS['launch_test_root'] = $directory;
    $status = 1;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $directory], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start readiness fixtures.');
        fclose($pipes[0]); echo stream_get_contents($pipes[1]); fwrite(STDERR, stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
    } finally { launch_cleanup($directory); }
    exit($status);
}
$directory = $argv[2] ?? '';
if (!is_dir($directory) || dirname(str_replace('\\', '/', $directory)) !== str_replace('\\', '/', sys_get_temp_dir()) || !preg_match('/^mahara-launch-[a-f0-9]{20}$/D', basename($directory))) throw new RuntimeException('Invalid readiness fixture path.');
mkdir($directory . '/data', 0700);
$dataAlias = $directory . '/data-alias';
$symlinkAvailable = @symlink($directory . '/data', $dataAlias);
define('MAHARA_LIBRARY_ONLY', true);
$GLOBALS['mahara_app_test_config'] = ['data_dir' => $symlinkAvailable ? $dataAlias : $directory . '/data', 'origin' => 'https://launch-fixture.example', 'production' => true];
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/merchant.php';
require_once dirname(__DIR__) . '/server/availability.php';
require_once dirname(__DIR__) . '/server/inventory.php';
require_once dirname(__DIR__) . '/server/backup.php';
$checks = 0;
function launch_check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function launch_reject(callable $operation, string $message): void { try { $operation(); } catch (RuntimeException) { launch_check(true, $message); return; } throw new RuntimeException($message); }
function launch_cleanup(string $path): void {
    if (!str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $GLOBALS['launch_test_root']) . '/') && $path !== $GLOBALS['launch_test_root']) throw new RuntimeException('Unsafe test cleanup.');
    if (is_dir($path) && !is_link($path)) { foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) launch_cleanup($entry->getPathname()); rmdir($path); }
    else if (is_file($path) || is_link($path)) unlink($path);
}
$GLOBALS['launch_test_root'] = $directory;
try {
    launch_check(!mahara_merchant_legal_status()['ready'], 'Empty merchant never enables legal checkout.');
    foreach (['privacy', 'terms', 'delivery', 'returns', 'contacts'] as $name) {
        $html = mahara_merchant_render('/' . $name . '.html');
        launch_check(is_string($html) && str_contains($html, '<article class="legal-document">') && !str_contains($html, 'legal-draft'), $name . ' document rendered without an internal draft notice.');
        launch_check(str_contains($html, 'noindex, nofollow'), $name . ' draft excluded from indexing.');
        launch_check(!str_contains($html, '<script'), $name . ' content does not require scripts.');
    }
    launch_check(mahara_merchant_render('/unrelated.html') === null, 'Unknown legal route not swallowed.');
    $merchant = mahara_merchant_config();
    foreach (mahara_legal_requirements() as $path => $requirement) {
        if ($path === 'published') { $merchant['published'] = true; continue; }
        if (!$requirement['required']) continue;
        $cursor =& $merchant;
        $parts = explode('.', $path);
        foreach ($parts as $index => $part) { if ($index === count($parts) - 1) $cursor[$part] = 'Подтверждённое значение'; else { if (!isset($cursor[$part])) $cursor[$part] = []; $cursor =& $cursor[$part]; } }
        unset($cursor);
    }
    launch_check(count(array_filter(mahara_legal_content($merchant), static fn(array $doc): bool => $doc['draft'])) === 0, 'Complete explicitly approved settings publish documents.');
    $merchant['published'] = 'true';
    launch_check(mahara_legal_content($merchant)['terms']['draft'], 'Truthy string does not approve publication.');
    $old = json_decode(file_get_contents(dirname(__DIR__) . '/site-defaults.json'), true, 64, JSON_THROW_ON_ERROR);
    $validated = mahara_validate_content($old, false);
    launch_check($validated['products'][0]['composition'] === '', 'Legacy catalog facts stay empty.');
    $old['products'][0]['composition'] = 'Хлопок <>&';
    launch_check(mahara_validate_content($old, false)['products'][0]['composition'] === 'Хлопок <>&', 'Factual plain text survives validation.');
    $old['products'][0]['care'] = str_repeat('а', 2001);
    launch_reject(static fn() => mahara_validate_content($old, false), 'Oversized product fact rejected.');

    $view = mahara_catalog_availability();
    launch_check(count($view['products']) === count(mahara_content()['products']), 'Availability corresponds to published catalog.');
    launch_check($view['products'][0]['variants'][0]['status'] === 'untracked' && $view['products'][0]['variants'][0]['maxQty'] === 0, 'Unknown stock is not sold.');
    launch_check(!mahara_query("SELECT name FROM sqlite_master WHERE name='inventory_balances'")->fetch(), 'Public read does not initialise inventory.');
    $product = mahara_content()['products'][0]; $size = $product['sizes'][0];
    mahara_inventory_schema();
    mahara_query('INSERT INTO inventory_balances(product_id,size,quantity,threshold,initialized,revision,product_snapshot,updated_at) VALUES(?,?,5,0,1,1,?,?)', [$product['id'], $size, json_encode($product), time()]);
    mahara_db()->exec('CREATE TABLE checkout_reservations(product_id TEXT,size TEXT,quantity INTEGER)');
    mahara_query('INSERT INTO checkout_reservations VALUES(?,?,2)', [$product['id'], $size]);
    $before = mahara_query('SELECT * FROM inventory_balances')->fetchAll();
    $view = mahara_catalog_availability();
    launch_check($view['products'][0]['variants'][0] === ['size' => $size, 'status' => 'available', 'maxQty' => 3], 'Reservations reduce publicly purchasable amount.');
    launch_check(mahara_query('SELECT * FROM inventory_balances')->fetchAll() === $before, 'Availability does not write inventory.');
    mahara_query('UPDATE inventory_balances SET quantity=100');
    launch_check(mahara_catalog_availability()['products'][0]['variants'][0]['maxQty'] === 20, 'Public limit matches checkout max20.');
    mahara_query('UPDATE inventory_balances SET quantity=1');
    launch_check(mahara_catalog_availability()['products'][0]['variants'][0]['status'] === 'out', 'Over-reserved stock is out, never negative.');

    mkdir($directory . '/data/uploads', 0700);
    $uploadName = str_repeat('a', 32) . '.webp';
    file_put_contents($directory . '/data/uploads/' . $uploadName, 'fixture-image-bytes');
    file_put_contents($directory . '/data/tbank-secrets.php', '<?php return [];');
    $snapshot = mahara_backup_create($directory . '/backups');
    launch_check($snapshot['verified'] && $snapshot['files'] === 2, 'Consistent DB + uploads snapshot verified.');
    launch_check(!is_file($snapshot['directory'] . '/tbank-secrets.php'), 'Credentials excluded from snapshots.');
    $copy = new PDO('sqlite:' . $snapshot['directory'] . '/mahara.sqlite');
    launch_check((int)$copy->query('SELECT quantity FROM inventory_balances')->fetchColumn() === 1, 'Snapshot restores recorded stock.');
    mahara_query('UPDATE inventory_balances SET quantity=7');
    launch_check((int)$copy->query('SELECT quantity FROM inventory_balances')->fetchColumn() === 1, 'Snapshot independent from later writes.');
    unset($copy);
    file_put_contents($snapshot['directory'] . '/uploads/' . $uploadName, 'tampered');
    launch_reject(static fn() => mahara_backup_verify($snapshot['directory']), 'Corrupt upload rejected by checksum.');
    launch_reject(static fn() => mahara_private_backup_base(dirname(__DIR__) . '/public-backup'), 'Backup refuses public website path.');
    launch_reject(static fn() => mahara_private_backup_base('relative'), 'Backup requires absolute private path.');
    $missingSnapshot = $directory . '/missing-parent/missing-snapshot';
    launch_reject(static fn() => mahara_backup_verify($missingSnapshot), 'Verifying a nonexistent snapshot fails.');
    launch_check(!file_exists($directory . '/missing-parent'), 'Read-only verification does not create the missing directory or ancestors.');
    if ($symlinkAvailable) {
        launch_check(realpath(mahara_config()['data_dir']) === realpath($directory . '/data') && mahara_config()['production'], 'Production accepts the private data symlink outside the public site.');
        $insideUploads = $directory . '/data/uploads/backup-must-not-exist';
        launch_reject(static fn() => mahara_backup_create($insideUploads), 'Physical uploads boundary rejects backup when configured data_dir uses a symlink ancestor.');
        launch_check(!file_exists($insideUploads), 'Rejected backup never creates a directory inside live uploads.');
        launch_reject(static fn() => mahara_private_backup_base($dataAlias . '/uploads/aliased-backup'), 'Aliased uploads boundary is also rejected.');
        launch_check(!file_exists($directory . '/data/uploads/aliased-backup'), 'Aliased rejection does not create an uploads entry.');
    } else {
        echo "SKIP: private-data symlink regression (OS permission or symlink support unavailable).\n";
    }
    echo 'PASS: ', $checks, " launch-readiness checks\n";
} finally { /* The parent removes the private fixture after SQLite closes. */ }
