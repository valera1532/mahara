<?php
declare(strict_types=1);

// A child process owns an isolated DB. No real credentials or external requests.
if (($argv[1] ?? '') === '--status-fixture') {
    $GLOBALS['mahara_app_test_config'] = ['production' => false, 'origin' => 'http://127.0.0.1:8765',
        'shipping' => ['enabled' => true, 'estimate_enabled' => ($argv[2] ?? '') === 'estimate']];
    $GLOBALS['mahara_shipping_test_secrets'] = ['account' => 'test-only-account', 'password' => 'test-only-password'];
    $GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => 'TEST-ONLY', 'password' => 'test-only-bank-password'];
    $argv = [__FILE__, 'status']; require dirname(__DIR__) . '/tools/commerce.php'; exit;
}
$worker = ($argv[1] ?? '') === '--worker';
if (!$worker) {
    $privateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mahara-shipping-' . bin2hex(random_bytes(12));
    if (!mkdir($privateDir, 0700)) throw new RuntimeException('Cannot create test directory.');
    putenv('APP_ENV=development'); putenv('DATA_DIR=' . $privateDir); putenv('APP_ORIGIN=http://127.0.0.1:8765');
    $status = 1;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start shipping test.');
        fclose($pipes[0]); echo stream_get_contents($pipes[1]); fwrite(STDERR, stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
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
$GLOBALS['mahara_app_test_config'] = []; // Never load the shop's private config.
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/payments.php';
require_once dirname(__DIR__) . '/server/shipping.php';
$GLOBALS['mahara_shipping_test_config'] = ['enabled' => true, 'origin_city_code' => 44, 'tariff_codes' => [136],
    'packages_by_category' => ['hoodie' => ['weight' => 800, 'width' => 30, 'height' => 8, 'length' => 40], 'tee' => ['weight' => 300, 'width' => 20, 'height' => 4, 'length' => 30]]];
$GLOBALS['mahara_shipping_test_secrets'] = ['account' => 'test-only-account', 'password' => 'test-only-password'];
$checks = 0; $calls = []; $responseMode = 'success';
function shipping_verify(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function shipping_error(int $status, callable $action, string $message): void {
    try { $action(); }
    catch (MaharaHttpError $error) { shipping_verify($error->status === $status, $message . ': wrong status ' . $error->status); return; }
    throw new RuntimeException($message . ': expected error.');
}
function shipping_point_fixture(string $code = 'MSK1', int $city = 44): array {
    return ['code' => $code, 'type' => 'PVZ', 'is_handout' => true, 'name' => 'Тестовый ПВЗ',
        'location' => ['city_code' => $city, 'city' => 'Москва', 'country_code' => 'RU', 'address' => 'Тестовая улица, 1']];
}
$GLOBALS['mahara_shipping_test_transport'] = static function (string $method, string $path, array $data, ?string $token) use (&$calls, &$responseMode): array {
    $calls[] = [$method, $path, $data, $token];
    if ($path === 'oauth/token') {
        shipping_verify($method === 'POST' && $data === ['grant_type' => 'client_credentials', 'client_id' => 'test-only-account', 'client_secret' => 'test-only-password'] && $token === null, 'OAuth stays server-side and uses credentials form.');
        return ['access_token' => 'test-only-bearer', 'expires_in' => 3600];
    }
    shipping_verify($token === 'test-only-bearer', 'Only Bearer token is sent to CDEK endpoints.');
    if ($path === 'location/cities') return [['code' => 44, 'city' => 'Москва', 'region' => 'Москва', 'country_code' => 'RU'], ['code' => 999, 'city' => 'Foreign', 'country_code' => 'XX']];
    if ($path === 'deliverypoints') {
        if (($data['code'] ?? '') === 'UNKNOWN') return [];
        if (($data['code'] ?? '') === 'POINT_ONLY') return [shipping_point_fixture('POINT_ONLY')];
        if (($data['code'] ?? '') === 'FOREIGN') return [array_replace_recursive(shipping_point_fixture('FOREIGN'), ['location' => ['country_code' => 'XX']])];
        return [shipping_point_fixture(), shipping_point_fixture('OTHER_CITY', 137), array_replace(shipping_point_fixture('LOCKER'), ['type' => 'POSTAMAT']), array_replace(shipping_point_fixture('DISABLED'), ['is_handout' => false])];
    }
    if ($path === 'calculator/tarifflist') {
        shipping_verify($method === 'POST' && $data['from_location'] === ['code' => 44] && $data['to_location'] === ['code' => 44] && $data['currency'] === 1 && $data['type'] === 1, 'Calculator uses verified city codes and RUB.');
        if ($responseMode === 'malformed') return ['tariff_codes' => 'bad'];
        if ($responseMode === 'unavailable') return ['tariff_codes' => [['tariff_code' => 999, 'delivery_mode' => 4, 'delivery_sum' => 10, 'period_min' => 1, 'period_max' => 2]]];
        return ['tariff_codes' => [
            ['tariff_code' => 999, 'delivery_mode' => 4, 'delivery_sum' => 1, 'period_min' => 1, 'period_max' => 2],
            ['tariff_code' => 136, 'delivery_mode' => 3, 'delivery_sum' => 2, 'period_min' => 1, 'period_max' => 2],
            ['tariff_code' => 136, 'delivery_mode' => 4, 'delivery_sum' => 345.67, 'period_min' => 2, 'period_max' => 4],
        ]];
    }
    throw new RuntimeException('Unexpected test endpoint.');
};

shipping_verify(mahara_shipping_public_config()['enabled'] && mahara_shipping_public_config()['estimateEnabled'] === true, 'Configured delivery keeps estimates enabled by default.');
shipping_verify(!str_contains(json_encode(mahara_shipping_public_config(), JSON_THROW_ON_ERROR), 'test-only'), 'Public configuration never leaks credentials.');
$GLOBALS['mahara_shipping_test_config']['packages_by_category']['tee']['weight'] = 0;
shipping_verify(!mahara_shipping_public_config()['enabled'], 'Missing real package parameters disable delivery.');
$GLOBALS['mahara_shipping_test_config']['packages_by_category']['tee']['weight'] = 300;
shipping_error(400, static fn() => mahara_shipping_cities('М'), 'Too short search rejected before network.');
shipping_error(400, static fn() => mahara_shipping_points('44 OR 1=1'), 'Untrusted city codes rejected.');
shipping_error(400, static fn() => mahara_shipping_point_code('https://attacker.example'), 'Untrusted point URL rejected.');
$cities = mahara_shipping_cities('Моск');
shipping_verify(count($cities) === 1 && $cities[0] === ['code' => 44, 'name' => 'Москва', 'region' => 'Москва'], 'City search is canonical and Russia only.');
$count = count($calls); shipping_verify(mahara_shipping_cities('Моск') === $cities && count($calls) === $count, 'City cache avoids repeat external calls.');
$points = mahara_shipping_points(44);
shipping_verify(count($points) === 1 && $points[0]['code'] === 'MSK1' && $points[0]['cityCode'] === 44, 'Only active PVZ in selected city are listed.');
$count = count($calls); shipping_verify(mahara_shipping_points(44) === $points && count($calls) === $count, 'PVZ cache avoids repeat external calls.');
shipping_verify(count(array_filter($calls, static fn(array $call): bool => $call[1] === 'oauth/token')) === 1, 'OAuth token is reused.');

$product = mahara_content()['products'][0];
$items = [['id' => $product['id'], 'size' => $product['sizes'][0], 'qty' => 2]];
$quote = mahara_shipping_quote(['items' => $items, 'pointCode' => 'MSK1']);
shipping_verify($quote['amount'] === 34567 && $quote['tariffCode'] === 136 && $quote['periodMin'] === 2 && $quote['periodMax'] === 4 && $quote['currency'] === 'RUB' && $quote['estimated'] === true, 'Quote uses contract tariff to PVZ and integer kopecks.');
shipping_verify((bool)preg_match('/^[a-f0-9]{48}$/D', $quote['quoteId']) && $quote['point']['code'] === 'MSK1', 'Quote has unguessable ID and verified point.');
$lastCalc = array_values(array_filter($calls, static fn(array $call): bool => $call[1] === 'calculator/tarifflist'));
$expectedPackage = $GLOBALS['mahara_shipping_test_config']['packages_by_category'][$product['category']];
shipping_verify($lastCalc[0][2]['packages'] === [$expectedPackage, $expectedPackage], 'Each unit uses configured package in grams and cm.');
shipping_verify(!str_contains(json_encode($quote, JSON_THROW_ON_ERROR), 'test-only'), 'Quote never exposes account, password or OAuth token.');
$count = count($calls);
$delivery = mahara_shipping_validate(['quoteId' => $quote['quoteId'], 'city' => 'Подмена', 'point' => 'Подмена', 'address' => 'Подмена'], $items);
shipping_verify($delivery['city'] === 'Москва' && $delivery['point'] === 'MSK1' && $delivery['address'] === 'Тестовая улица, 1' && $delivery['shippingAmount'] === 34567 && $delivery['estimated'] === true, 'Order ignores handwritten destination and uses canonical server quote.');
shipping_verify(count($calls) === $count, 'Order validation never makes a network call.');
$changed = $items; $changed[0]['qty'] = 1;
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $quote['quoteId']], $changed), 'Quote cannot be reused for another cart.');
shipping_error(400, static fn() => mahara_shipping_validate(['city' => 'Москва', 'point' => 'MSK1', 'address' => 'fake'], $items), 'No payment accepted with unquoted PVZ.');
shipping_error(409, static fn() => mahara_shipping_quote(['items' => $items, 'pointCode' => 'UNKNOWN']), 'Unknown point is rejected.');
$GLOBALS['mahara_shipping_test_config']['origin_city_code'] = 137;
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $quote['quoteId']], $items), 'Sender settings invalidate old quote.');
$GLOBALS['mahara_shipping_test_config']['origin_city_code'] = 44;
mahara_query('UPDATE shipping_quotes SET expires_at=? WHERE id=?', [time() - 1, $quote['quoteId']]);
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $quote['quoteId']], $items), 'Expired quote rejected.');
$responseMode = 'unavailable';
shipping_error(409, static fn() => mahara_shipping_quote(['items' => $items, 'pointCode' => 'MSK1']), 'Out-of-contract tariffs rejected.');
$responseMode = 'malformed';
shipping_error(502, static fn() => mahara_shipping_quote(['items' => $items, 'pointCode' => 'MSK1']), 'Malformed provider response rejected.');
$estimatedSettings = $GLOBALS['mahara_shipping_test_config'];
$GLOBALS['mahara_shipping_test_config'] = ['enabled' => true, 'estimate_enabled' => false];
shipping_verify(mahara_shipping_public_config()['enabled'] && mahara_shipping_public_config()['estimateEnabled'] === false, 'Point-only requires no sender city, tariffs or packages.');
$GLOBALS['mahara_shipping_test_config']['estimate_enabled'] = 'false';
shipping_verify(!mahara_shipping_public_config()['enabled'], 'A string cannot select point-only mode.');
$GLOBALS['mahara_shipping_test_config']['estimate_enabled'] = false;
$originalPassword = $GLOBALS['mahara_shipping_test_secrets']['password']; $GLOBALS['mahara_shipping_test_secrets']['password'] = '';
shipping_verify(!mahara_shipping_public_config()['enabled'], 'Point-only still requires private API credentials.'); $GLOBALS['mahara_shipping_test_secrets']['password'] = $originalPassword;
$calculatorCalls = count(array_filter($calls, static fn(array $call): bool => $call[1] === 'calculator/tarifflist')); $beforePoint = count($calls);
$pointQuote = mahara_shipping_quote(['items' => $items, 'pointCode' => 'POINT_ONLY']);
shipping_verify($pointQuote['estimated'] === false && $pointQuote['amount'] === null && $pointQuote['tariffCode'] === null && $pointQuote['periodMin'] === null && $pointQuote['periodMax'] === null && $pointQuote['currency'] === 'RUB', 'Point-only DTO has explicit null price, tariff and dates, never a fake zero.');
shipping_verify(count($calls) === $beforePoint + 1 && $calls[array_key_last($calls)][0] === 'GET' && $calls[array_key_last($calls)][1] === 'deliverypoints' && $calls[array_key_last($calls)][2]['code'] === 'POINT_ONLY', 'Point-only snapshot comes from an actual provider PVZ lookup.');
shipping_verify(count(array_filter($calls, static fn(array $call): bool => $call[1] === 'calculator/tarifflist')) === $calculatorCalls, 'Point-only never invokes calculator, even with malformed calculator response fixture.');
$stored = mahara_query('SELECT * FROM shipping_quotes WHERE id=?', [$pointQuote['quoteId']])->fetch();
shipping_verify(json_decode($stored['quote_json'], true) === $pointQuote && (int)$stored['expires_at'] >= time() + 898 && (int)$stored['expires_at'] <= time() + 900, 'Verified snapshot is persisted for fifteen minutes.');
$count = count($calls); $selected = mahara_shipping_validate(['quoteId' => $pointQuote['quoteId'], 'city' => 'Подмена', 'point' => 'Подмена', 'address' => 'Подмена'], $items);
shipping_verify($selected === ['city' => 'Москва', 'point' => 'POINT_ONLY', 'address' => 'Тестовая улица, 1', 'quoteId' => $pointQuote['quoteId'], 'shippingAmount' => null, 'tariffCode' => null, 'estimated' => false], 'Order destination is canonical server snapshot with unknown delivery amount.');
shipping_verify(count($calls) === $count, 'Point-only validation stays local under order transaction.');
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $pointQuote['quoteId']], $changed), 'Point-only remains bound to cart quantities.');
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => str_repeat('f', 48)], $items), 'Forged confirmation IDs are rejected.');
shipping_error(400, static fn() => mahara_shipping_validate(['quoteId' => $pointQuote['quoteId'], 'shippingAmount' => 0], $items), 'Client cannot submit a fake shipping price.');
shipping_error(400, static fn() => mahara_shipping_quote(['items' => [['id' => $product['id'], 'size' => $product['sizes'][0], 'qty' => 0]], 'pointCode' => 'POINT_ONLY']), 'Point-only retains quantity validation.');
shipping_error(409, static fn() => mahara_shipping_quote(['items' => [['id' => 'not-in-catalog', 'size' => 'S', 'qty' => 1]], 'pointCode' => 'POINT_ONLY']), 'Skipping packages never bypasses catalog membership.');
foreach (['FOREIGN', 'DISABLED', 'LOCKER', 'UNKNOWN'] as $invalidPoint) shipping_error(409, static fn() => mahara_shipping_quote(['items' => $items, 'pointCode' => $invalidPoint]), 'Only actual handout RU PVZ can be confirmed.');
$GLOBALS['mahara_shipping_test_config'] = $estimatedSettings;
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $pointQuote['quoteId']], $items), 'Point-only cannot be used after switching to estimates.');
$GLOBALS['mahara_shipping_test_config'] = ['enabled' => true, 'estimate_enabled' => false]; $GLOBALS['mahara_payments_test_config'] = ['delivery_mode' => 'fixed'];
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $pointQuote['quoteId']], $items), 'Changed payment delivery mode invalidates confirmation.'); $GLOBALS['mahara_payments_test_config'] = ['delivery_mode' => 'cdek_receiver'];
foreach (['password', 'account'] as $field) {
    $old = $GLOBALS['mahara_shipping_test_secrets'][$field]; $GLOBALS['mahara_shipping_test_secrets'][$field] = 'changed-test-only';
    shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $pointQuote['quoteId']], $items), 'Changed credentials invalidate confirmation without provider calls.'); $GLOBALS['mahara_shipping_test_secrets'][$field] = $old;
}
$GLOBALS['mahara_shipping_test_config']['origin_city_code'] = 137;
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $pointQuote['quoteId']], $items), 'Changed settings invalidate point-only confirmation.'); unset($GLOBALS['mahara_shipping_test_config']['origin_city_code']);
foreach (['amount', 'tariffCode', 'periodMin', 'periodMax'] as $field) {
    $tampered = $pointQuote; $tampered[$field] = 0; mahara_query('UPDATE shipping_quotes SET quote_json=? WHERE id=?', [json_encode($tampered), $pointQuote['quoteId']]);
    shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $pointQuote['quoteId']], $items), 'Malformed stored DTO cannot masquerade as point-only.');
}
$tampered = $pointQuote; unset($tampered['amount']); mahara_query('UPDATE shipping_quotes SET quote_json=? WHERE id=?', [json_encode($tampered), $pointQuote['quoteId']]);
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $pointQuote['quoteId']], $items), 'Missing amount does not substitute for explicit null.');
mahara_query('UPDATE shipping_quotes SET quote_json=? WHERE id=?', [json_encode($pointQuote), $pointQuote['quoteId']]);
shipping_verify(mahara_shipping_validate(['quoteId' => $pointQuote['quoteId']], $items)['shippingAmount'] === null, 'Genuine snapshot still validates without fake price.');
mahara_query('UPDATE shipping_quotes SET expires_at=? WHERE id=?', [time() - 1, $pointQuote['quoteId']]);
shipping_error(409, static fn() => mahara_shipping_validate(['quoteId' => $pointQuote['quoteId']], $items), 'Expired point-only confirmation is rejected.');
shipping_verify(!str_contains(json_encode($pointQuote), 'test-only') && !str_contains($stored['quote_json'], 'test-only'), 'Snapshot never exposes credentials or OAuth token.');
shipping_verify(!mahara_query("SELECT name FROM sqlite_master WHERE name IN ('checkout_orders','checkout_reservations','inventory_balances','inventory_movements')")->fetch(), 'Point selection never creates orders, reservations or stock.');
foreach (['point', 'estimate'] as $statusMode) {
    $process = proc_open([PHP_BINARY, __FILE__, '--status-fixture', $statusMode], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start status fixture.');
    fclose($pipes[0]); $body = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    shipping_verify(proc_close($process) === 0 && $errors === '', 'Readiness runs only against isolated fixture settings.'); $report = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    shipping_verify($report['shippingEstimateEnabled'] === ($statusMode === 'estimate') && $report['shippingEnabled'] === ($statusMode === 'point'), 'Readiness distinguishes point-only from estimates without invented measurements.');
    $missing = implode(' ', $report['missing']); shipping_verify($statusMode === 'estimate' ? str_contains($missing, 'вес и размеры') : !str_contains($missing, 'вес и размеры'), 'Point-only readiness never falsely requires estimate inputs.');
}
echo 'Shipping checks passed: ' . $checks . PHP_EOL;
