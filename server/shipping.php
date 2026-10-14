<?php
declare(strict_types=1);

// CDEK API v2: https://apidoc.cdek.ru/ and the official cdek-it/widget service.
// Estimates are separate from the goods payment. This module does not register
// shipments or promise that a contract permits collection from the recipient.

function mahara_shipping_config(): array {
    $config = array_merge(['enabled' => false, 'estimate_enabled' => true, 'origin_city_code' => 0, 'tariff_codes' => [], 'packages_by_category' => []], mahara_config()['shipping'] ?? []);
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_shipping_test_config'])) $config = array_merge($config, $GLOBALS['mahara_shipping_test_config']);
    return $config;
}

function mahara_shipping_secrets(): array {
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_shipping_test_secrets'])) return $GLOBALS['mahara_shipping_test_secrets'];
    $app = mahara_config();
    $path = getenv('MAHARA_CDEK_SECRETS') ?: ($app['production'] ? $app['data_dir'] . '/cdek-secrets.php' : $app['root'] . '/.local/cdek-secrets.php');
    if (!is_file($path) || is_link($path)) return ['account' => '', 'password' => ''];
    ob_start();
    try { $value = require $path; }
    catch (Throwable) { throw new MaharaHttpError(503, 'Проверьте подключение доставки СДЭК.'); }
    finally { ob_end_clean(); }
    if (!is_array($value) || !is_string($value['account'] ?? null) || !is_string($value['password'] ?? null)) throw new MaharaHttpError(503, 'Проверьте подключение доставки СДЭК.');
    return ['account' => $value['account'], 'password' => $value['password']];
}

function mahara_shipping_package(mixed $value): ?array {
    if (!is_array($value) || array_is_list($value)) return null;
    $result = [];
    foreach (['weight' => 100000, 'width' => 500, 'height' => 500, 'length' => 500] as $field => $max) {
        if (!is_int($value[$field] ?? null) || $value[$field] < 1 || $value[$field] > $max) return null;
        $result[$field] = $value[$field];
    }
    return $result;
}

function mahara_shipping_public_config(): array {
    $config = mahara_shipping_config(); $secrets = mahara_shipping_secrets();
    $estimate = $config['estimate_enabled'] === true;
    $valid = $config['enabled'] === true && is_bool($config['estimate_enabled'])
        && $secrets['account'] !== '' && strlen($secrets['account']) <= 200 && $secrets['password'] !== '' && strlen($secrets['password']) <= 500;
    if ($estimate) {
        $valid = $valid && is_int($config['origin_city_code']) && $config['origin_city_code'] > 0 && $config['origin_city_code'] <= 999999999
            && is_array($config['tariff_codes']) && array_is_list($config['tariff_codes']) && count($config['tariff_codes']) > 0 && count($config['tariff_codes']) <= 30;
        foreach (is_array($config['tariff_codes']) ? $config['tariff_codes'] : [] as $code) if (!is_int($code) || $code < 1 || $code > 999999) $valid = false;
        foreach (['hoodie', 'tee'] as $category) if (!mahara_shipping_package($config['packages_by_category'][$category] ?? null)) $valid = false;
    }
    return ['enabled' => $valid, 'mode' => 'cdek_receiver', 'estimateEnabled' => $estimate,
        'message' => $valid ? '' : ($estimate ? 'Выбор пункта выдачи и расчёт СДЭК пока не подключены.' : 'Выбор пунктов выдачи СДЭК пока не подключён.')];
}

function mahara_shipping_require_enabled(): void {
    $public = mahara_shipping_public_config();
    if (!$public['enabled']) throw new MaharaHttpError(503, $public['message']);
}

function mahara_shipping_schema(): void {
    static $ready = false;
    if ($ready) return;
    mahara_db()->exec('CREATE TABLE IF NOT EXISTS shipping_cache (cache_key TEXT PRIMARY KEY, body TEXT NOT NULL, expires_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS shipping_quotes (id TEXT PRIMARY KEY, items_hash TEXT NOT NULL, settings_hash TEXT NOT NULL, quote_json TEXT NOT NULL, expires_at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS shipping_quote_expiry ON shipping_quotes(expires_at);');
    $ready = true;
}

function mahara_shipping_cache_get(string $key): mixed {
    mahara_shipping_schema();
    $body = mahara_query('SELECT body FROM shipping_cache WHERE cache_key=? AND expires_at>?', [$key, time()])->fetchColumn();
    return $body === false ? null : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
}

function mahara_shipping_cache_set(string $key, mixed $value, int $ttl): void {
    mahara_shipping_schema();
    mahara_query('INSERT INTO shipping_cache (cache_key,body,expires_at) VALUES (?,?,?) ON CONFLICT(cache_key) DO UPDATE SET body=excluded.body,expires_at=excluded.expires_at', [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), time() + $ttl]);
}

function mahara_shipping_request(string $method, string $path, array $data = [], ?string $token = null): array {
    if (!in_array($path, ['oauth/token', 'location/cities', 'deliverypoints', 'calculator/tarifflist'], true) || !in_array($method, ['GET', 'POST'], true)) throw new RuntimeException('Unsupported delivery operation.');
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_shipping_test_transport'])) {
        $result = ($GLOBALS['mahara_shipping_test_transport'])($method, $path, $data, $token);
    } else {
        if (!function_exists('curl_init')) throw new MaharaHttpError(503, 'Для расчёта доставки настройте PHP cURL на сервере.');
        // OAuth plus pagination/calculation share one request budget, so a slow
        // provider cannot outlive the checkout's 25-second browser timeout.
        static $deadline = null;
        $deadline ??= microtime(true) + 20;
        $remaining = min(12000, (int)floor(($deadline - microtime(true)) * 1000));
        if ($remaining < 200) throw new MaharaHttpError(502, 'СДЭК отвечает слишком долго. Повторите расчёт доставки.');
        $url = 'https://api.cdek.ru/v2/' . $path;
        if ($method === 'GET' && $data) $url .= '?' . http_build_query($data);
        $headers = ['Accept: application/json', 'X-App-Name: mahara_store'];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        $body = ''; $handle = curl_init($url);
        $options = [CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining), CURLOPT_TIMEOUT_MS => $remaining,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int { if (strlen($body) + strlen($chunk) > 2097152) return 0; $body .= $chunk; return strlen($chunk); }];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($path === 'oauth/token') {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                $options[CURLOPT_POSTFIELDS] = http_build_query($data);
            } else {
                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle); $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
        if ($ok === false || $code !== 200) throw new MaharaHttpError(502, 'СДЭК временно недоступен. Повторите расчёт доставки.');
        try { $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new MaharaHttpError(502, 'Не удалось проверить ответ СДЭК.'); }
    }
    if (!is_array($result) || isset($result['errors']) || isset($result['error'])) throw new MaharaHttpError(502, 'Не удалось получить данные доставки СДЭК.');
    return $result;
}

function mahara_shipping_token(): string {
    $secret = mahara_shipping_secrets();
    $key = 'oauth:' . hash('sha256', $secret['account'] . "\0" . $secret['password']);
    $cached = mahara_shipping_cache_get($key);
    if (is_string($cached) && $cached !== '') return $cached;
    $reply = mahara_shipping_request('POST', 'oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $secret['account'], 'client_secret' => $secret['password']]);
    $token = $reply['access_token'] ?? null; $expires = $reply['expires_in'] ?? null;
    if (!is_string($token) || $token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token) || !is_int($expires) || $expires < 60) throw new MaharaHttpError(502, 'СДЭК не подтвердил подключение доставки.');
    mahara_shipping_cache_set($key, $token, min(3600, $expires - 30));
    return $token;
}

function mahara_shipping_code(mixed $code, string $label = 'Город'): int {
    if (is_string($code) && preg_match('/^[1-9][0-9]{0,8}$/D', $code)) $code = (int)$code;
    return mahara_integer($code, 1, 999999999, $label);
}

function mahara_shipping_point_code(mixed $code): string {
    if (!is_string($code) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,39}$/D', $code)) mahara_fail('Некорректный код пункта выдачи СДЭК.');
    return $code;
}

function mahara_shipping_provider_text(mixed $value, int $max = 250): string {
    if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match_all('/./us', $value) > $max || trim($value) === '') throw new MaharaHttpError(502, 'Не удалось проверить адрес доставки СДЭК.');
    return trim($value);
}

function mahara_shipping_cities(mixed $query): array {
    mahara_shipping_require_enabled(); $query = mahara_text($query, 'Город', 100, true);
    if (preg_match_all('/./us', $query) < 2) mahara_fail('Введите не менее двух букв названия города.');
    $key = 'cities:' . hash('sha256', $query); $cached = mahara_shipping_cache_get($key);
    if (is_array($cached)) return $cached;
    $reply = mahara_shipping_request('GET', 'location/cities', ['city' => $query, 'country_codes' => 'RU', 'size' => 20, 'page' => 0], mahara_shipping_token());
    if (!array_is_list($reply)) throw new MaharaHttpError(502, 'Не удалось получить список городов СДЭК.');
    $cities = [];
    foreach (array_slice($reply, 0, 20) as $city) {
        if (!is_array($city) || !is_int($city['code'] ?? null) || $city['code'] < 1 || $city['code'] > 999999999 || ($city['country_code'] ?? '') !== 'RU') continue;
        $cities[] = ['code' => $city['code'], 'name' => mahara_shipping_provider_text($city['city'] ?? null, 150), 'region' => isset($city['region']) && $city['region'] !== '' ? mahara_shipping_provider_text($city['region'], 150) : ''];
    }
    mahara_shipping_cache_set($key, $cities, 86400); return $cities;
}

function mahara_shipping_point(array $row): ?array {
    $location = $row['location'] ?? null;
    if (!is_array($location) || ($row['type'] ?? '') !== 'PVZ' || ($location['country_code'] ?? '') !== 'RU' || ($row['is_handout'] ?? false) !== true
        || !is_int($location['city_code'] ?? null) || $location['city_code'] < 1 || $location['city_code'] > 999999999
        || !is_string($row['code'] ?? null) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,39}$/D', $row['code'])) return null;
    return ['code' => $row['code'], 'city' => mahara_shipping_provider_text($location['city'] ?? null, 150), 'cityCode' => $location['city_code'],
        'address' => mahara_shipping_provider_text($location['address'] ?? null, 500), 'name' => mahara_shipping_provider_text($row['name'] ?? $row['code'], 250)];
}

function mahara_shipping_points(mixed $cityCode): array {
    mahara_shipping_require_enabled(); $cityCode = mahara_shipping_code($cityCode);
    $key = 'points:' . $cityCode; $cached = mahara_shipping_cache_get($key);
    if (is_array($cached)) return $cached;
    $points = [];
    for ($page = 0; $page < 5; $page++) {
        $reply = mahara_shipping_request('GET', 'deliverypoints', ['city_code' => $cityCode, 'type' => 'PVZ', 'is_handout' => 'true', 'size' => 500, 'page' => $page], mahara_shipping_token());
        if (!array_is_list($reply)) throw new MaharaHttpError(502, 'Не удалось получить пункты выдачи СДЭК.');
        foreach ($reply as $row) {
            $point = is_array($row) ? mahara_shipping_point($row) : null;
            if ($point && $point['cityCode'] === $cityCode) $points[$point['code']] = $point;
        }
        if (count($reply) < 500) break;
    }
    $points = array_values($points); usort($points, static fn(array $a, array $b): int => strcmp($a['address'], $b['address']));
    mahara_shipping_cache_set($key, $points, 3600); return $points;
}

function mahara_shipping_verified_point(string $code): array {
    $key = 'point:' . $code; $cached = mahara_shipping_cache_get($key);
    if (is_array($cached)) return $cached;
    $reply = mahara_shipping_request('GET', 'deliverypoints', ['code' => $code, 'type' => 'PVZ', 'is_handout' => 'true'], mahara_shipping_token());
    if (!array_is_list($reply)) throw new MaharaHttpError(502, 'Не удалось проверить пункт выдачи СДЭК.');
    foreach ($reply as $row) {
        $point = is_array($row) ? mahara_shipping_point($row) : null;
        if ($point && $point['code'] === $code) { mahara_shipping_cache_set($key, $point, 300); return $point; }
    }
    throw new MaharaHttpError(409, 'Пункт выдачи недоступен. Выберите другой пункт СДЭК.');
}

function mahara_shipping_packages(array $items): array {
    $config = mahara_shipping_config(); $packages = [];
    foreach ($items as $item) {
        $product = mahara_inventory_product($item['id'], $item['size']);
        $package = mahara_shipping_package($config['packages_by_category'][$product['category']] ?? null);
        if (!$package) throw new MaharaHttpError(503, 'Магазин ещё не указал параметры упаковки товара.');
        for ($n = 0; $n < $item['qty']; $n++) $packages[] = $package;
    }
    return $packages;
}

function mahara_shipping_settings_hash(array $packages): string {
    $config = mahara_shipping_config(); $secrets = mahara_shipping_secrets();
    $mode = function_exists('mahara_payments_config') ? mahara_payments_config()['delivery_mode'] : 'cdek_receiver';
    return hash('sha256', json_encode([2, $mode, $config['estimate_enabled'], $config['origin_city_code'], $config['tariff_codes'],
        $config['packages_by_category'], $packages, $secrets['account'], $secrets['password']], JSON_THROW_ON_ERROR));
}

function mahara_shipping_quote(mixed $input): array {
    mahara_shipping_require_enabled(); mahara_object($input, ['items', 'pointCode'], 'Доставка');
    $items = mahara_payments_items($input['items']); $pointCode = mahara_shipping_point_code($input['pointCode']);
    $config = mahara_shipping_config(); $estimate = $config['estimate_enabled'] === true;
    $packages = $estimate ? mahara_shipping_packages($items) : [];
    if (!$estimate) foreach ($items as $item) mahara_inventory_product($item['id'], $item['size']);
    $point = mahara_shipping_verified_point($pointCode);
    $selection = ['amount' => null, 'tariffCode' => null, 'periodMin' => null, 'periodMax' => null];
    if ($estimate) {
        $reply = mahara_shipping_request('POST', 'calculator/tarifflist', ['type' => 1, 'currency' => 1, 'lang' => 'rus', 'from_location' => ['code' => $config['origin_city_code']], 'to_location' => ['code' => $point['cityCode']], 'packages' => $packages], mahara_shipping_token());
        if (!is_array($reply['tariff_codes'] ?? null) || !array_is_list($reply['tariff_codes'])) throw new MaharaHttpError(502, 'СДЭК не вернул стоимость доставки.');
        $options = [];
        foreach ($reply['tariff_codes'] as $tariff) {
            if (!is_array($tariff) || !in_array($tariff['tariff_code'] ?? null, $config['tariff_codes'], true) || !in_array($tariff['delivery_mode'] ?? null, [2, 4], true)
                || (!is_int($tariff['delivery_sum'] ?? null) && !is_float($tariff['delivery_sum'] ?? null)) || !is_finite((float)$tariff['delivery_sum']) || $tariff['delivery_sum'] < 0 || $tariff['delivery_sum'] > 100000
                || !is_int($tariff['period_min'] ?? null) || !is_int($tariff['period_max'] ?? null) || $tariff['period_min'] < 0 || $tariff['period_max'] < $tariff['period_min'] || $tariff['period_max'] > 365) continue;
            $options[] = ['amount' => (int)round($tariff['delivery_sum'] * 100), 'tariffCode' => $tariff['tariff_code'], 'periodMin' => $tariff['period_min'], 'periodMax' => $tariff['period_max']];
        }
        if (!$options) throw new MaharaHttpError(409, 'Для этого пункта нет доступного тарифа по договору магазина. Выберите другой пункт СДЭК.');
        usort($options, static fn(array $a, array $b): int => [$a['amount'], $a['tariffCode']] <=> [$b['amount'], $b['tariffCode']]);
        $selection = $options[0];
    }
    $now = time(); $quote = $selection + ['quoteId' => bin2hex(random_bytes(24)), 'currency' => 'RUB', 'point' => $point, 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $now + 900), 'estimated' => $estimate];
    mahara_shipping_schema();
    mahara_query('DELETE FROM shipping_quotes WHERE expires_at<=?', [$now]);
    mahara_query('DELETE FROM shipping_cache WHERE expires_at<=?', [$now]);
    mahara_query('INSERT INTO shipping_quotes (id,items_hash,settings_hash,quote_json,expires_at) VALUES (?,?,?,?,?)', [$quote['quoteId'], hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)), mahara_shipping_settings_hash($packages), json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $now + 900]);
    return $quote;
}

// Pure local validation, safe to call while reserving an order under a DB lock.
function mahara_shipping_validate(mixed $delivery, mixed $items): array {
    mahara_shipping_require_enabled();
    if (!is_array($delivery) || array_is_list($delivery) || array_diff(array_keys($delivery), ['quoteId', 'city', 'point', 'address']) || !is_string($delivery['quoteId'] ?? null) || !preg_match('/^[a-f0-9]{48}$/D', $delivery['quoteId'])) mahara_fail('Выберите пункт выдачи СДЭК и подтвердите его.');
    $items = mahara_payments_items($items); mahara_shipping_schema();
    $estimate = mahara_shipping_config()['estimate_enabled'] === true;
    $packages = $estimate ? mahara_shipping_packages($items) : [];
    if (!$estimate) foreach ($items as $item) mahara_inventory_product($item['id'], $item['size']);
    $row = mahara_query('SELECT * FROM shipping_quotes WHERE id=? AND expires_at>?', [$delivery['quoteId'], time()])->fetch();
    if (!$row || !hash_equals($row['items_hash'], hash('sha256', json_encode($items, JSON_THROW_ON_ERROR))) || !hash_equals($row['settings_hash'], mahara_shipping_settings_hash($packages))) throw new MaharaHttpError(409, 'Подтверждение пункта устарело или корзина изменилась. Выберите пункт выдачи повторно.');
    $quote = json_decode($row['quote_json'], true, 64, JSON_THROW_ON_ERROR);
    if (($quote['estimated'] ?? null) !== $estimate) throw new MaharaHttpError(409, 'Подтверждение пункта устарело. Выберите пункт выдачи повторно.');
    if (!$estimate) foreach (['amount', 'tariffCode', 'periodMin', 'periodMax'] as $field) {
        if (!array_key_exists($field, $quote) || $quote[$field] !== null) throw new MaharaHttpError(409, 'Подтверждение пункта устарело. Выберите пункт выдачи повторно.');
    }
    return ['city' => $quote['point']['city'], 'point' => $quote['point']['code'], 'address' => $quote['point']['address'], 'quoteId' => $quote['quoteId'], 'shippingAmount' => $quote['amount'], 'tariffCode' => $quote['tariffCode'], 'estimated' => $estimate];
}

function mahara_shipping_api(string $path, string $method): void {
    $routes = ['/api/shipping/config' => 'config', '/api/shipping/cities' => 'cities', '/api/shipping/points' => 'points', '/api/shipping/quote' => 'quote'];
    if (!isset($routes[$path])) return;
    $route = $routes[$path];
    if ($method !== ($route === 'quote' ? 'POST' : 'GET')) throw new MaharaHttpError(405, 'Метод не поддерживается.');
    if ($route === 'config') mahara_json(mahara_shipping_public_config());
    if ($route === 'quote') mahara_require_origin();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $retry = mahara_throttle(hash('sha256', 'shipping:' . $ip), 90);
    if ($retry) { header('Retry-After: ' . $retry); throw new MaharaHttpError(429, 'Слишком много запросов доставки. Повторите позже.'); }
    if ($route === 'cities') mahara_json(['cities' => mahara_shipping_cities($_GET['q'] ?? null)]);
    if ($route === 'points') mahara_json(['points' => mahara_shipping_points($_GET['cityCode'] ?? null)]);
    mahara_json(mahara_shipping_quote(mahara_read_json(16384)));
}
