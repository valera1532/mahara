<?php
declare(strict_types=1);

// T-Bank EACQ v2. All amounts are integer kopecks. A browser redirect is never
// evidence of payment. Network calls run outside SQLite write transactions.
// Protocol: https://developer.tbank.ru/eacq/api/init and /intro/developer/notification
require_once __DIR__ . '/inventory.php';

// A validated API failure is distinct from an unreadable/late transport result.
// Never expose the provider body, which can contain customer/payment details.
final class MaharaTbankRejection extends RuntimeException {
    public function __construct(public readonly string $operation, public readonly string $providerCode) {
        parent::__construct('Банк отклонил запрос. Проверьте статус операции.');
    }
}

function mahara_payments_definite_rejection(string $code): bool {
    // Only documented request-validation failures are safe to recover as rejected.
    // Duplicate OrderId (20), internal errors and unfamiliar codes stay uncertain.
    // https://developer.tbank.ru/eacq/intro/errors/error-codes
    return in_array($code, ['1', '2', '12', '64', '501'], true);
}

function mahara_payments_legal_snapshot(): array {
    $config = mahara_payments_config();
    $status = function_exists('mahara_merchant_legal_status') ? mahara_merchant_legal_status() : [];
    return ['termsUrl' => $config['terms_url'], 'privacyUrl' => $config['privacy_url'],
        'legalVersion' => is_string($status['version'] ?? null) && $status['version'] !== '' ? $status['version'] : 'external'];
}

function mahara_payments_builtin_legal_url(?string $url): bool {
    if ($url === null) return false;
    $parts = parse_url($url);
    if (!is_array($parts)) return false;
    // Browsers resolve dot segments before following a link. An alternate spelling
    // of our own draft must not turn it into an apparently external document.
    $segments = [];
    foreach (explode('/', rawurldecode($parts['path'] ?? '')) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') { array_pop($segments); continue; }
        $segments[] = $segment;
    }
    if (!in_array('/' . implode('/', $segments), ['/terms.html', '/privacy.html'], true)) return false;
    if (!isset($parts['host'])) return str_starts_with($url, '/');
    $origin = parse_url(mahara_config()['origin']);
    if (!is_array($origin)) return false;
    $port = static fn(array $value): int => (int)($value['port'] ?? (($value['scheme'] ?? '') === 'https' ? 443 : 80));
    return strtolower($parts['host']) === strtolower($origin['host'] ?? '') && ($parts['scheme'] ?? '') === ($origin['scheme'] ?? '') && $port($parts) === $port($origin);
}

function mahara_payments_schedule_notification(string $id, string $event): void {
    if (!function_exists('mahara_order_notification_schedule')) return;
    try { mahara_order_notification_schedule($id, $event); }
    catch (Throwable) { error_log('MAHARA order notification scheduling failed.'); }
}

function mahara_payments_config(): array {
    $config = array_merge([
        'enabled' => false, 'taxation' => '', 'item_tax' => '', 'receipt_enabled' => false,
        'delivery_mode' => 'cdek_receiver', 'delivery_amount' => 0, 'delivery_label' => '',
        'payment_ttl' => 900, 'terms_url' => '', 'privacy_url' => '', 'ffd_version' => '1.2',
    ], mahara_config()['payments'] ?? []);
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_payments_test_config'])) {
        $config = array_merge($config, $GLOBALS['mahara_payments_test_config']);
    }
    return $config;
}

function mahara_tbank_certificates_ready(): bool {
    $path = __DIR__ . '/certs/tbank-ca.pem';
    if (!is_file($path) || is_link($path)) return false;
    // Windows restricted tokens can make is_readable() disagree with a working
    // file handle. Check actual access instead of that ACL approximation.
    $handle = @fopen($path, 'rb');
    if ($handle === false) return false;
    fclose($handle);
    return true;
}

function mahara_payments_secrets(): array {
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_payments_test_secrets'])) return $GLOBALS['mahara_payments_test_secrets'];
    $app = mahara_config();
    $path = getenv('MAHARA_TBANK_SECRETS') ?: ($app['production'] ? $app['data_dir'] . '/tbank-secrets.php' : $app['root'] . '/.local/tbank-secrets.php');
    if (!is_file($path) || is_link($path)) return ['terminal_key' => '', 'password' => ''];
    // Never leak accidental output, syntax errors, paths or credentials to logs.
    ob_start();
    try { $secrets = require $path; }
    catch (Throwable) { throw new MaharaHttpError(503, 'Проверьте настройки подключения оплаты.'); }
    finally { ob_end_clean(); }
    if (!is_array($secrets) || !is_string($secrets['terminal_key'] ?? null) || !is_string($secrets['password'] ?? null)) throw new MaharaHttpError(503, 'Проверьте настройки подключения оплаты.');
    return ['terminal_key' => $secrets['terminal_key'], 'password' => $secrets['password']];
}

function mahara_payments_legal_url(mixed $url): ?string {
    if (!is_string($url) || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
    if (preg_match('~^/(?!/)[a-zA-Z0-9/_?&=.%#-]+$~D', $url)) return $url;
    $parts = parse_url($url);
    if (is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && isset($parts['host'])) return $url;
    return null;
}

function mahara_payments_public_config(): array {
    $config = mahara_payments_config();
    $mode = in_array($config['delivery_mode'], ['fixed', 'cdek_receiver'], true) ? $config['delivery_mode'] : 'unconfigured';
    $amount = $mode === 'fixed' && is_int($config['delivery_amount']) && $config['delivery_amount'] >= 0 && $config['delivery_amount'] <= 10000000 ? $config['delivery_amount'] : 0;
    $validDelivery = $mode !== 'unconfigured' && ($mode !== 'fixed' || $amount === $config['delivery_amount']);
    if ($mode === 'cdek_receiver') $validDelivery = $validDelivery && function_exists('mahara_shipping_public_config') && mahara_shipping_public_config()['enabled'] === true;
    $terms = mahara_payments_legal_url($config['terms_url']); $privacy = mahara_payments_legal_url($config['privacy_url']);
    $builtInLegal = mahara_payments_builtin_legal_url($terms) || mahara_payments_builtin_legal_url($privacy);
    $legalReady = !$builtInLegal || (function_exists('mahara_merchant_legal_status') && (mahara_merchant_legal_status()['ready'] ?? false) === true);
    $taxes = ['none', 'vat0', 'vat5', 'vat7', 'vat10', 'vat22', 'vat105', 'vat107', 'vat110', 'vat122'];
    $validReceipt = $config['receipt_enabled'] === true && in_array($config['taxation'], ['osn', 'usn_income', 'usn_income_outcome', 'esn', 'patent'], true)
        && in_array($config['item_tax'], $taxes, true) && in_array($config['ffd_version'], ['1.05', '1.2'], true);
    $secrets = mahara_payments_secrets();
    $credentials = $secrets['terminal_key'] !== '' && strlen($secrets['terminal_key']) <= 64 && $secrets['password'] !== '';
    $origin = mahara_config()['origin'];
    $validOrigin = is_string($origin) && $origin !== '' && (!mahara_config()['production'] || str_starts_with($origin, 'https://'));
    $certificatesReady = mahara_tbank_certificates_ready();
    $enabled = $config['enabled'] === true && $validDelivery && $validReceipt && $credentials && $terms !== null && $privacy !== null && $legalReady && $validOrigin && $certificatesReady;
    return ['enabled' => $enabled, 'currency' => 'RUB',
        'delivery' => ['mode' => $mode, 'label' => is_string($config['delivery_label']) && $config['delivery_label'] !== '' ? $config['delivery_label'] : ($mode === 'cdek_receiver' ? 'СДЭК — доставка оплачивается при получении отдельно' : 'Доставка'), 'amount' => $amount],
        'legal' => ['termsUrl' => $terms, 'privacyUrl' => $privacy],
        'message' => $enabled ? '' : 'Онлайн-оплата пока не подключена. Уточните заказ у магазина.'];
}

function mahara_payments_schema(): void {
    static $ready = false;
    if ($ready) return;
    mahara_inventory_schema();
    mahara_db()->exec('CREATE TABLE IF NOT EXISTS checkout_orders (
        id TEXT PRIMARY KEY, number TEXT NOT NULL UNIQUE, request_id TEXT NOT NULL UNIQUE,
        request_payload TEXT NOT NULL, access_token TEXT NOT NULL, items_json TEXT NOT NULL,
        customer_json TEXT NOT NULL, delivery_json TEXT NOT NULL, total_amount INTEGER NOT NULL,
        delivery_amount INTEGER NOT NULL, status TEXT NOT NULL, bank_status TEXT NOT NULL DEFAULT \'\',
        terminal_key TEXT NOT NULL, payment_id TEXT UNIQUE, payment_url TEXT, qr_svg TEXT,
        stock_applied INTEGER NOT NULL DEFAULT 0, expires_at INTEGER NOT NULL,
        last_checked INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
        fulfillment_status TEXT NOT NULL DEFAULT \'processing\', tracking_number TEXT NOT NULL DEFAULT \'\',
        refund_request_id TEXT UNIQUE, refund_requested_at INTEGER,
        receipt_status TEXT NOT NULL DEFAULT \'not_verified\',
        init_outcome TEXT NOT NULL DEFAULT \'unknown\', init_error_code TEXT NOT NULL DEFAULT \'\',
        refund_outcome TEXT NOT NULL DEFAULT \'none\', refund_error_code TEXT NOT NULL DEFAULT \'\',
        accepted_at INTEGER, terms_url TEXT NOT NULL DEFAULT \'\', privacy_url TEXT NOT NULL DEFAULT \'\', legal_version TEXT NOT NULL DEFAULT \'\'
    );
    CREATE TABLE IF NOT EXISTS checkout_reservations (
        order_id TEXT NOT NULL REFERENCES checkout_orders(id), product_id TEXT NOT NULL,
        size TEXT NOT NULL, quantity INTEGER NOT NULL CHECK(quantity>0),
        PRIMARY KEY(order_id, product_id, size)
    );
    CREATE INDEX IF NOT EXISTS checkout_reservations_variant ON checkout_reservations(product_id, size);
    CREATE INDEX IF NOT EXISTS checkout_orders_created ON checkout_orders(created_at DESC);
    CREATE INDEX IF NOT EXISTS checkout_orders_reconcile ON checkout_orders(status, last_checked);
    CREATE TABLE IF NOT EXISTS checkout_refund_attempts (
        request_id TEXT PRIMARY KEY, order_id TEXT NOT NULL REFERENCES checkout_orders(id),
        outcome TEXT NOT NULL, error_code TEXT NOT NULL DEFAULT \'\', requested_at INTEGER NOT NULL
    );');
    // Existing shops retain orders. Concurrent first requests may both observe a
    // missing column; accept only an ALTER failure whose column now exists.
    $columns = ['init_outcome' => "TEXT NOT NULL DEFAULT 'unknown'", 'init_error_code' => "TEXT NOT NULL DEFAULT ''",
        'refund_outcome' => "TEXT NOT NULL DEFAULT 'none'", 'refund_error_code' => "TEXT NOT NULL DEFAULT ''",
        'accepted_at' => 'INTEGER', 'terms_url' => "TEXT NOT NULL DEFAULT ''", 'privacy_url' => "TEXT NOT NULL DEFAULT ''", 'legal_version' => "TEXT NOT NULL DEFAULT ''"];
    foreach ($columns as $name => $definition) {
        $present = array_column(mahara_query('PRAGMA table_info(checkout_orders)')->fetchAll(), 'name');
        if (in_array($name, $present, true)) continue;
        try { mahara_db()->exec('ALTER TABLE checkout_orders ADD COLUMN ' . $name . ' ' . $definition); }
        catch (PDOException $error) {
            if (!in_array($name, array_column(mahara_query('PRAGMA table_info(checkout_orders)')->fetchAll(), 'name'), true)) throw $error;
        }
    }
    $ready = true;
}

function mahara_payments_transaction(callable $operation): mixed {
    mahara_payments_schema();
    return mahara_inventory_transaction($operation, true);
}

// Use inside the inventory mutation transaction before allowing a lower balance.
function mahara_payments_reserved(string $productId, string $size): int {
    mahara_payments_schema();
    return (int)mahara_query('SELECT COALESCE(SUM(quantity),0) FROM checkout_reservations WHERE product_id=? AND size=?', [$productId, $size])->fetchColumn();
}

function mahara_payments_items(mixed $input): array {
    if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 30) mahara_fail('Корзина: от 1 до 30 позиций.');
    $items = []; $units = 0;
    foreach ($input as $item) {
        mahara_object($item, ['id', 'size', 'qty'], 'Позиция заказа');
        [$id, $size] = mahara_inventory_identity($item['id'], $item['size']);
        $qty = mahara_integer($item['qty'], 1, 20, 'Количество'); $units += $qty;
        if ($units > 50) mahara_fail('В одном заказе доступно до 50 товаров.');
        $key = $id . ':' . $size;
        if (isset($items[$key])) mahara_fail('Размер товара повторяется в корзине.');
        $items[$key] = ['id' => $id, 'size' => $size, 'qty' => $qty];
    }
    ksort($items, SORT_STRING);
    return array_values($items);
}

// Call under a transaction when reserving: catalogue, price and stock form one snapshot.
function mahara_payments_quote(mixed $input): array {
    mahara_payments_schema(); $items = []; $subtotal = 0;
    foreach (mahara_payments_items($input) as $item) {
        $product = mahara_inventory_product($item['id'], $item['size']);
        $stock = mahara_query('SELECT quantity,initialized FROM inventory_balances WHERE product_id=? AND size=?', [$item['id'], $item['size']])->fetch();
        if (!$stock || !(bool)$stock['initialized']) throw new MaharaHttpError(409, 'Магазин ещё не подтвердил остаток товара «' . $product['title'] . '», размер ' . $item['size'] . '.');
        $available = (int)$stock['quantity'] - mahara_payments_reserved($item['id'], $item['size']);
        if ($available < $item['qty']) throw new MaharaHttpError(409, 'Недостаточно товара «' . $product['title'] . '», размер ' . $item['size'] . '. Обновите корзину.');
        $unit = (int)$product['price'] * 100;
        if ($unit < 1) throw new MaharaHttpError(409, 'Цена товара пока не указана.');
        $amount = $unit * $item['qty']; $subtotal += $amount;
        $items[] = $item + ['title' => $product['title'], 'image' => $product['image'], 'unitAmount' => $unit, 'amount' => $amount];
    }
    $delivery = mahara_payments_public_config()['delivery']['amount'];
    if ($subtotal + $delivery > 1000000000) mahara_fail('Сумма заказа превышает допустимую.');
    return ['items' => $items, 'subtotalAmount' => $subtotal, 'deliveryAmount' => $delivery, 'totalAmount' => $subtotal + $delivery, 'currency' => 'RUB'];
}

function mahara_tbank_token(array $payload, string $password): string {
    unset($payload['Token']); $payload['Password'] = $password; $scalars = [];
    foreach ($payload as $key => $value) {
        if (is_array($value) || is_object($value) || $value === null) continue;
        if (!is_scalar($value)) throw new RuntimeException('Invalid payment protocol value.');
        $scalars[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }
    ksort($scalars, SORT_STRING);
    return hash('sha256', implode('', $scalars));
}

function mahara_tbank_url(mixed $url): ?string {
    if (!is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
    $host = strtolower($parts['host'] ?? '');
    foreach (['tbank.ru', 'tinkoff.ru', 'qr.nspk.ru'] as $allowed) if ($host === $allowed || str_ends_with($host, '.' . $allowed)) return $url;
    return null;
}

function mahara_tbank_svg(mixed $svg): ?string {
    // Return only a passive SVG image, never active markup or remote resources.
    if (!is_string($svg) || strlen($svg) > 524288 || !class_exists('DOMDocument') || preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) return null;
    $doc = new DOMDocument(); $old = libxml_use_internal_errors(true);
    try { if (!$doc->loadXML($svg, LIBXML_NONET) || !$doc->documentElement || $doc->documentElement->localName !== 'svg') return null; }
    finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    $tags = ['svg', 'g', 'path', 'rect'];
    $attributes = ['xmlns', 'version', 'width', 'height', 'viewBox', 'fill', 'stroke', 'stroke-width', 'd', 'x', 'y', 'rx', 'ry', 'transform', 'shape-rendering'];
    foreach ($doc->getElementsByTagName('*') as $node) {
        if (!in_array($node->nodeName, $tags, true)) return null;
        foreach ($node->attributes as $attribute) {
            if (!in_array($attribute->nodeName, $attributes, true) || preg_match('/url\s*\(|[<>]/i', $attribute->value)) return null;
            if ($attribute->nodeName === 'xmlns' && $attribute->value !== 'http://www.w3.org/2000/svg') return null;
        }
    }
    return $doc->saveXML($doc->documentElement) ?: null;
}

function mahara_tbank_call(string $method, array $payload): array {
    if (!in_array($method, ['Init', 'GetQr', 'GetState', 'CheckOrder', 'Cancel'], true)) throw new RuntimeException('Unsupported payment operation.');
    if (mahara_payments_config()['enabled'] !== true) throw new MaharaHttpError(503, 'Онлайн-оплата пока не подключена.');
    $secrets = mahara_payments_secrets();
    if ($secrets['terminal_key'] === '' || $secrets['password'] === '') throw new MaharaHttpError(503, 'Проверьте настройки подключения оплаты.');
    $payload['TerminalKey'] = $secrets['terminal_key']; $payload['Token'] = mahara_tbank_token($payload, $secrets['password']);
    $url = 'https://securepay.tinkoff.ru/v2/' . $method;
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_payments_test_transport'])) {
        $response = ($GLOBALS['mahara_payments_test_transport'])($method, $payload, $url);
    } else {
        // This CA bundle includes the bank's required Russian Trusted Root CA.
        // It applies only to the fixed bank API, never to CDEK or system trust.
        $caFile = __DIR__ . '/certs/tbank-ca.pem';
        if (!mahara_tbank_certificates_ready()) throw new MaharaHttpError(503, 'Проверьте сертификаты подключения банка.');
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = ''; $httpCode = 0;
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $encoded,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                // Init + GetQr PAYLOAD + GetQr IMAGE must fit the storefront's
                // 25-second request budget. Unknown outcomes reconcile later.
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 7, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_CAINFO => $caFile,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int { if (strlen($body) + strlen($chunk) > 1048576) return 0; $body .= $chunk; return strlen($chunk); },
            ]);
            $ok = curl_exec($handle); $httpCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
            if ($ok === false) throw new MaharaHttpError(502, 'Банк временно недоступен. Статус заказа будет проверен повторно.');
        } else {
            $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n", 'content' => $encoded, 'timeout' => 7, 'follow_location' => 0, 'ignore_errors' => true],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'cafile' => $caFile, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
            $stream = @fopen($url, 'rb', false, $context);
            if (!$stream) throw new MaharaHttpError(502, 'Банк временно недоступен. Статус заказа будет проверен повторно.');
            $body = stream_get_contents($stream, 1048577); $meta = stream_get_meta_data($stream); fclose($stream);
            foreach (($meta['wrapper_data'] ?? []) as $header) if (preg_match('~^HTTP/\S+ (\d{3})~', $header, $match)) $httpCode = (int)$match[1];
            if ($body === false || strlen($body) > 1048576 || !empty($meta['timed_out'])) throw new MaharaHttpError(502, 'Не удалось проверить ответ банка.');
        }
        if ($httpCode !== 200) throw new MaharaHttpError(502, 'Банк временно недоступен. Статус заказа будет проверен повторно.');
        try { $response = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new MaharaHttpError(502, 'Не удалось проверить ответ банка.'); }
    }
    if (!is_array($response) || array_is_list($response)) throw new MaharaHttpError(502, 'Не удалось проверить ответ банка.');
    if (isset($response['TerminalKey']) && (!is_string($response['TerminalKey']) || !hash_equals($secrets['terminal_key'], $response['TerminalKey']))) throw new MaharaHttpError(502, 'Не удалось проверить ответ банка.');
    $errorCode = $response['ErrorCode'] ?? null;
    if (($response['Success'] ?? null) === false && (is_int($errorCode) || is_string($errorCode)) && preg_match('/^-?[0-9]{1,10}$/D', (string)$errorCode) && (string)$errorCode !== '0') {
        throw new MaharaTbankRejection($method, (string)$errorCode);
    }
    if (($response['Success'] ?? null) !== true || (!is_int($errorCode) && !is_string($errorCode)) || (string)$errorCode !== '0') throw new MaharaHttpError(502, 'Банк не подтвердил операцию. Проверьте статус заказа перед повторной оплатой.');
    return $response;
}

function mahara_payments_require_bank_connection(): void {
    // Local configuration failures precede the financial attempt. Do not journal
    // a refund as uncertain when no request can even be sent to the bank.
    if (mahara_payments_config()['enabled'] !== true) throw new MaharaHttpError(503, 'Оплата выключена. Включите подключение банка перед запросом возврата.');
    $secrets = mahara_payments_secrets();
    if ($secrets['terminal_key'] === '' || $secrets['password'] === '') throw new MaharaHttpError(503, 'Проверьте настройки подключения оплаты.');
    if (!(defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_payments_test_transport'])) && !mahara_tbank_certificates_ready()) throw new MaharaHttpError(503, 'Проверьте сертификаты подключения банка.');
}

function mahara_payments_row(string $id): array {
    mahara_payments_schema();
    $row = mahara_query('SELECT * FROM checkout_orders WHERE id=?', [$id])->fetch();
    if (!$row) throw new MaharaHttpError(404, 'Заказ не найден.');
    return $row;
}

function mahara_payments_view(array $row, bool $admin = false): array {
    $order = ['id' => $row['id'], 'number' => $row['number'], 'status' => $row['status'],
        'totalAmount' => (int)$row['total_amount'], 'currency' => 'RUB', 'items' => json_decode($row['items_json'], true, 64, JSON_THROW_ON_ERROR),
        'deliveryAmount' => (int)$row['delivery_amount'], 'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['created_at']),
        'fulfillmentStatus' => $row['fulfillment_status'], 'trackingNumber' => $row['tracking_number'], 'receiptStatus' => $row['receipt_status']];
    if ($admin) $order += ['customer' => json_decode($row['customer_json'], true, 64, JSON_THROW_ON_ERROR), 'delivery' => json_decode($row['delivery_json'], true, 64, JSON_THROW_ON_ERROR), 'bankStatus' => $row['bank_status'], 'paymentId' => $row['payment_id'],
        'initOutcome' => $row['init_outcome'], 'refundOutcome' => $row['refund_outcome'],
        'providerErrorCode' => $row['refund_error_code'] ?: $row['init_error_code'],
        'operatorMessage' => mahara_payments_operator_message($row), 'requiresReview' => mahara_payments_operator_message($row) !== '',
        'acceptance' => ['acceptedAt' => $row['accepted_at'] ? gmdate('Y-m-d\TH:i:s\Z', (int)$row['accepted_at']) : null,
            'termsUrl' => $row['terms_url'], 'privacyUrl' => $row['privacy_url'], 'legalVersion' => $row['legal_version']]];
    $active = $row['status'] === 'payment_pending' && (int)$row['expires_at'] > time();
    return ['order' => $order, 'payment' => ['url' => $active ? $row['payment_url'] : null, 'qrSvg' => $active ? $row['qr_svg'] : null, 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['expires_at'])]];
}

function mahara_payments_operator_message(array $row): string {
    if ($row['status'] === 'refund_pending') return 'Возврат пока не подтверждён банком. Проверьте статус в банке; не создавайте второй возврат при неопределённом результате. При необходимости сверьте операцию в кабинете Т-Кассы.';
    if ($row['refund_outcome'] === 'rejected' && $row['status'] === 'paid') return 'Банк отклонил возврат. Оплата остаётся подтверждённой. Устраните причину в Т-Кассе перед новым запросом возврата.';
    if ($row['status'] === 'failed' && $row['init_outcome'] === 'rejected') return 'Банк отклонил создание платежа; отсутствие платежа проверено, резерв снят. Проверьте настройки Т-Кассы перед новой попыткой.';
    if ($row['status'] === 'payment_pending' && !$row['payment_id']) return 'Создание платежа не подтверждено. Резерв сохранён. Проверьте статус в банке; не просите покупателя создавать второй заказ до сверки.';
    if ($row['status'] === 'payment_pending' && (int)$row['expires_at'] <= time()) return 'Срок ссылки истёк, но окончательный статус банка ещё не получен. Резерв сохранён до сверки с Т-Кассой.';
    if ($row['status'] === 'payment_pending' && !$row['payment_url']) return 'Ссылка СБП пока не получена. Проверьте статус в банке, затем повторите обновление этого заказа.';
    return '';
}

function mahara_payments_receipt(array $row): array {
    $config = mahara_payments_config(); $items = [];
    foreach (json_decode($row['items_json'], true, 64, JSON_THROW_ON_ERROR) as $item) {
        $name = $item['title'] . ', ' . $item['size']; preg_match('/^.{0,128}/us', $name, $match);
        $position = ['Name' => $match[0], 'Price' => $item['unitAmount'], 'Quantity' => $item['qty'], 'Amount' => $item['amount'], 'Tax' => $config['item_tax'], 'PaymentMethod' => 'full_prepayment', 'PaymentObject' => 'commodity'];
        if ($config['ffd_version'] === '1.2') $position['MeasurementUnit'] = 'шт';
        $items[] = $position;
    }
    if ((int)$row['delivery_amount'] > 0) {
        $position = ['Name' => 'Доставка', 'Price' => (int)$row['delivery_amount'], 'Quantity' => 1, 'Amount' => (int)$row['delivery_amount'], 'Tax' => $config['item_tax'], 'PaymentMethod' => 'full_prepayment', 'PaymentObject' => 'service'];
        if ($config['ffd_version'] === '1.2') $position['MeasurementUnit'] = 'шт';
        $items[] = $position;
    }
    $customer = json_decode($row['customer_json'], true, 64, JSON_THROW_ON_ERROR);
    $receipt = ['Email' => $customer['email'], 'Phone' => $customer['phone'], 'Taxation' => $config['taxation'], 'Items' => $items];
    if ($config['ffd_version'] === '1.2') $receipt['FfdVersion'] = '1.2';
    return $receipt;
}

function mahara_payments_create(mixed $input, ?bool &$orderNotCreated = null): array {
    $orderNotCreated = false;
    mahara_object($input, ['requestId', 'items', 'customer', 'delivery', 'acceptedTerms', 'expectedTotalAmount'], 'Заказ');
    $requestId = mahara_payments_uuid($input['requestId']);
    if ($input['acceptedTerms'] !== true) mahara_fail('Подтвердите условия заказа.');
    $expected = mahara_integer($input['expectedTotalAmount'], 1000, 1000000000, 'Сумма заказа');
    $items = mahara_payments_items($input['items']);
    mahara_object($input['customer'], ['name', 'email', 'phone'], 'Покупатель');
    $customer = ['name' => mahara_text($input['customer']['name'], 'Имя', 150, true), 'email' => mahara_text($input['customer']['email'], 'E-mail', 64, true), 'phone' => mahara_text($input['customer']['phone'], 'Телефон', 24, true)];
    if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) mahara_fail('Проверьте e-mail для чека.');
    $customer['phone'] = preg_replace('/[\s()-]/', '', $customer['phone']);
    if (preg_match('/^8[0-9]{10}$/D', $customer['phone'])) $customer['phone'] = '+7' . substr($customer['phone'], 1);
    if (!preg_match('/^\+[1-9][0-9]{9,14}$/D', $customer['phone'])) mahara_fail('Проверьте телефон, включая код страны.');
    $receiver = mahara_payments_config()['delivery_mode'] === 'cdek_receiver';
    mahara_object($input['delivery'], $receiver ? ['city', 'point', 'address', 'quoteId'] : ['city', 'point', 'address'], 'Доставка');
    $delivery = ['city' => mahara_text($input['delivery']['city'], 'Город', 150, true), 'point' => mahara_text($input['delivery']['point'], 'Пункт выдачи', 250), 'address' => mahara_text($input['delivery']['address'], 'Адрес', 500)];
    if ($receiver) $delivery['quoteId'] = mahara_text($input['delivery']['quoteId'], 'Расчёт доставки', 100, true);
    if ($delivery['point'] === '' && $delivery['address'] === '') mahara_fail('Укажите пункт выдачи или адрес доставки.');
    $payload = json_encode([$items, $customer, $delivery, $expected], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    mahara_payments_schema();
    $previous = mahara_query('SELECT * FROM checkout_orders WHERE request_id=?', [$requestId])->fetch();
    if ($previous) {
        if (!hash_equals($previous['request_payload'], $payload)) throw new MaharaHttpError(409, 'Идентификатор заказа уже использован с другими данными.');
        $row = mahara_payments_reconcile($previous['id']);
        return mahara_payments_view($row) + ['accessToken' => $row['access_token']];
    }
    if (!mahara_payments_public_config()['enabled']) throw new MaharaHttpError(503, 'Онлайн-оплата пока не подключена.');
    $row = mahara_payments_transaction(static function () use ($requestId, $payload, $items, $customer, $delivery, $expected, $receiver, &$orderNotCreated): array {
        $previous = mahara_query('SELECT * FROM checkout_orders WHERE request_id=?', [$requestId])->fetch();
        if ($previous) {
            if (!hash_equals($previous['request_payload'], $payload)) throw new MaharaHttpError(409, 'Идентификатор заказа уже использован с другими данными.');
            return $previous;
        }
        try {
            $quote = mahara_payments_quote($items);
            if ($quote['totalAmount'] !== $expected) throw new MaharaHttpError(409, 'Стоимость заказа изменилась. Проверьте сумму и подтвердите заказ повторно.');
            if ($quote['totalAmount'] < 1000) mahara_fail('Минимальная сумма оплаты СБП — 10 рублей.');
            if ($receiver) {
                if (!function_exists('mahara_shipping_validate')) throw new MaharaHttpError(503, 'Расчёт доставки пока не подключён.');
                // This hook validates a persisted, cart-bound CDEK quote locally;
                // it must not make network calls while SQLite's lock is held.
                $delivery = mahara_shipping_validate($delivery, $items);
            }
        } catch (MaharaHttpError $error) {
            // Only this locked, pre-INSERT validation proves that the request
            // has no order/reservation. A 409 from an existing order's bank
            // reconciliation must never authorize a new checkout attempt.
            if (in_array($error->status, [400, 409, 422], true)) $orderNotCreated = true;
            throw $error;
        }
        $config = mahara_payments_config(); $secrets = mahara_payments_secrets(); $now = time(); $legal = mahara_payments_legal_snapshot();
        $ttl = is_int($config['payment_ttl']) ? max(60, min(86400, $config['payment_ttl'])) : 900;
        $id = bin2hex(random_bytes(16)); $number = 'MAH-' . gmdate('Ymd') . '-' . strtoupper(substr($id, 0, 10));
        mahara_query('INSERT INTO checkout_orders (id,number,request_id,request_payload,access_token,items_json,customer_json,delivery_json,total_amount,delivery_amount,status,terminal_key,expires_at,created_at,updated_at,accepted_at,terms_url,privacy_url,legal_version) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $number, $requestId, $payload, mahara_random_token(), json_encode($quote['items'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($customer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($delivery, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $quote['totalAmount'], $quote['deliveryAmount'], 'payment_pending', $secrets['terminal_key'], $now + $ttl, $now, $now, $now, $legal['termsUrl'], $legal['privacyUrl'], $legal['legalVersion']]);
        foreach ($items as $item) mahara_query('INSERT INTO checkout_reservations (order_id,product_id,size,quantity) VALUES (?,?,?,?)', [$id, $item['id'], $item['size'], $item['qty']]);
        return mahara_payments_row($id) + ['new' => true];
    });
    // Only the process that inserted the order may send Init. A retry recovers
    // an ambiguous timeout through CheckOrder; it never issues a second Init.
    if (!empty($row['new'])) {
        try {
            $origin = mahara_config()['origin'];
            $reply = mahara_tbank_call('Init', ['Amount' => (int)$row['total_amount'], 'OrderId' => $row['number'], 'Description' => 'MAHARA, заказ ' . $row['number'], 'PayType' => 'O', 'Language' => 'ru',
                'NotificationURL' => $origin . '/api/payments/tbank/notify', 'SuccessURL' => $origin . '/checkout.html?order=' . $row['id'], 'FailURL' => $origin . '/checkout.html?order=' . $row['id'],
                'RedirectDueDate' => gmdate('Y-m-d\TH:i:s+00:00', (int)$row['expires_at']), 'Receipt' => mahara_payments_receipt($row), 'DATA' => ['OperationInitiatorType' => '0']]);
            mahara_payments_bind($row['id'], $reply);
            mahara_payments_qr($row['id']);
        } catch (MaharaTbankRejection $error) {
            mahara_payments_transaction(static function () use ($row, $error): void {
                mahara_query("UPDATE checkout_orders SET init_outcome='rejected',init_error_code=?,updated_at=? WHERE id=? AND payment_id IS NULL AND stock_applied=0 AND status='payment_pending'", [$error->providerCode, time(), $row['id']]);
            });
            // An API failure can also mean duplicate OrderId/internal processing.
            // Release only the narrow validation cases after a separate bank read.
            mahara_payments_reconcile($row['id'], true);
        } catch (MaharaHttpError) {
            // Keep the reservation: a timeout can follow successful bank Init.
            // The returned pending order can be recovered without another debit.
        }
        mahara_payments_schedule_notification($row['id'], 'order_created');
    }
    $row = mahara_payments_row($row['id']);
    return mahara_payments_view($row) + ['accessToken' => $row['access_token']];
}

function mahara_payments_uuid(mixed $id): string {
    if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $id)) mahara_fail('Некорректный идентификатор операции.');
    return strtolower($id);
}

function mahara_payments_bank_identity(array $row, array $reply, bool $requireOrder = true): void {
    $paymentId = $reply['PaymentId'] ?? '';
    $amount = $reply['Amount'] ?? null;
    $refundState = in_array($reply['Status'] ?? null, ['REFUNDING', 'PARTIAL_REFUNDED', 'REFUNDED'], true);
    if ((!is_string($paymentId) && !is_int($paymentId)) || !preg_match('/^[0-9]{1,30}$/D', (string)$paymentId)
        || (isset($row['payment_id']) && $row['payment_id'] !== '' && $row['payment_id'] !== (string)$paymentId)
        || ($requireOrder && ($reply['OrderId'] ?? null) !== $row['number'])
        || (isset($reply['TerminalKey']) && ($reply['TerminalKey'] !== $row['terminal_key']))
        || (!is_int($amount) && !is_string($amount)) || !preg_match('/^[0-9]{1,12}$/D', (string)$amount)
        || ($refundState ? ((int)$amount < 0 || (int)$amount > (int)$row['total_amount']) : (int)$amount !== (int)$row['total_amount'])) throw new MaharaHttpError(409, 'Параметры платежа не совпадают с заказом.');
}

function mahara_payments_bind(string $id, array $reply): void {
    mahara_payments_transaction(static function () use ($id, $reply): void {
        $row = mahara_payments_row($id); mahara_payments_bank_identity($row, $reply);
        // The generic Init URL can expose card methods. Only GetQr's verified
        // SBP payload is published to the customer.
        mahara_query('UPDATE checkout_orders SET payment_id=?,receipt_status=\'provider_requested\',init_outcome=\'accepted\',init_error_code=\'\',updated_at=? WHERE id=?', [(string)$reply['PaymentId'], time(), $id]);
    });
}

function mahara_payments_qr(string $id): void {
    $row = mahara_payments_row($id);
    if (!$row['payment_id'] || $row['status'] !== 'payment_pending' || (int)$row['expires_at'] <= time()) return;
    $link = mahara_tbank_call('GetQr', ['PaymentId' => $row['payment_id'], 'DataType' => 'PAYLOAD', 'PaymentMethod' => 'SBP']);
    if (isset($link['PaymentId']) && (string)$link['PaymentId'] !== $row['payment_id']) throw new MaharaHttpError(502, 'Не удалось проверить ответ банка.');
    $url = mahara_tbank_url($link['Data'] ?? null);
    if (!$url) throw new MaharaHttpError(502, 'Банк не вернул ссылку оплаты СБП.');
    mahara_query('UPDATE checkout_orders SET payment_url=?,updated_at=? WHERE id=?', [$url, time(), $id]);
    try {
        $image = mahara_tbank_call('GetQr', ['PaymentId' => $row['payment_id'], 'DataType' => 'IMAGE', 'PaymentMethod' => 'SBP']);
        if (isset($image['PaymentId']) && (string)$image['PaymentId'] !== $row['payment_id']) return;
        $svg = mahara_tbank_svg($image['Data'] ?? null);
        if ($svg) mahara_query('UPDATE checkout_orders SET qr_svg=?,updated_at=? WHERE id=?', [$svg, time(), $id]);
    } catch (MaharaHttpError) { /* The verified payment link remains usable. */ }
}

function mahara_payments_apply(string $id, array $reply, bool $authoritative = false): array {
    $event = null;
    $result = mahara_payments_transaction(static function () use ($id, $reply, $authoritative, &$event): array {
        $row = mahara_payments_row($id); mahara_payments_bank_identity($row, $reply);
        $status = $reply['Status'] ?? '';
        if (!is_string($status) || !in_array($status, ['NEW', 'FORM_SHOWED', 'AUTHORIZING', '3DS_CHECKING', '3DS_CHECKED', 'AUTHORIZED', 'PAY_CHECKING', 'CONFIRMING', 'CONFIRMED', 'REVERSING', 'PARTIAL_REVERSED', 'REVERSED', 'REFUNDING', 'PARTIAL_REFUNDED', 'REFUNDED', 'CANCELED', 'DEADLINE_EXPIRED', 'REJECTED', 'AUTH_FAIL'], true)) throw new MaharaHttpError(400, 'Неизвестный статус платежа.');
        // Refunded/partially refunded payments must never be changed back to
        // paid by delayed CONFIRMED notifications.
        if ($row['status'] === 'refunded') return $row;
        if ($row['status'] === 'partial_refunded' && !in_array($status, ['PARTIAL_REFUNDED', 'REFUNDING', 'REFUNDED'], true)) return $row;
        if ((bool)$row['stock_applied'] && !in_array($status, ['CONFIRMED', 'REFUNDING', 'PARTIAL_REFUNDED', 'REFUNDED'], true)) return $row;
        if (in_array($row['status'], ['canceled', 'expired', 'failed'], true) && !in_array($status, ['CANCELED', 'DEADLINE_EXPIRED', 'REJECTED', 'AUTH_FAIL', 'REVERSED'], true)) throw new MaharaHttpError(409, 'Статус платежа требует проверки магазина.');
        $next = $row['status'];
        if ($status === 'CONFIRMED' || $status === 'PARTIAL_REFUNDED') {
            if (!(bool)$row['stock_applied']) {
                foreach (json_decode($row['items_json'], true, 64, JSON_THROW_ON_ERROR) as $item) {
                    $stock = mahara_query('SELECT * FROM inventory_balances WHERE product_id=? AND size=?', [$item['id'], $item['size']])->fetch();
                    if (!$stock || !(bool)$stock['initialized'] || (int)$stock['quantity'] < $item['qty']) throw new MaharaHttpError(409, 'Оплата получена, но остаток требует проверки магазина.');
                    $product = json_decode($stock['product_snapshot'], true, 64, JSON_THROW_ON_ERROR);
                    $variant = mahara_inventory_variant($item['size'], $stock); $before = $variant['quantity']; $variant['quantity'] -= $item['qty'];
                    mahara_inventory_save($product, $variant);
                    $movementId = 'payment:' . $id . ':' . $item['id'] . ':' . $item['size'];
                    mahara_query('INSERT INTO inventory_movements (request_id,request_payload,product_id,product_title,size,type,quantity,before_quantity,after_quantity,note,actor,created_at) VALUES (?,?,?,?,?,\'writeoff\',?,?,?,?,?,?)',
                        [$movementId, json_encode(['orderId' => $id, 'paymentId' => (string)$reply['PaymentId']], JSON_THROW_ON_ERROR), $item['id'], $item['title'], $item['size'], $item['qty'], $before, $variant['quantity'], 'Оплата заказа ' . $row['number'], 'T-Bank', time()]);
                }
                mahara_query('UPDATE checkout_orders SET stock_applied=1 WHERE id=?', [$id]);
                mahara_query('DELETE FROM checkout_reservations WHERE order_id=?', [$id]);
                if ($status === 'CONFIRMED') $event = 'payment_paid';
            }
            $next = $status === 'CONFIRMED' ? ($row['status'] === 'refund_pending' ? 'refund_pending' : 'paid') : 'partial_refunded';
            if ($status === 'CONFIRMED' && $authoritative && $row['refund_outcome'] === 'rejected_unverified' && mahara_payments_definite_rejection($row['refund_error_code'])) {
                $next = 'paid';
                mahara_query("UPDATE checkout_orders SET refund_outcome='rejected' WHERE id=?", [$id]);
                mahara_query("UPDATE checkout_refund_attempts SET outcome='rejected' WHERE request_id=? AND order_id=?", [$row['refund_request_id'], $id]);
            }
        } elseif ($status === 'REFUNDING') {
            $next = 'refund_pending';
        } elseif ($status === 'REFUNDED') {
            $next = 'refunded'; mahara_query('DELETE FROM checkout_reservations WHERE order_id=?', [$id]);
            mahara_query("UPDATE checkout_orders SET refund_outcome='settled',refund_error_code='' WHERE id=?", [$id]);
            mahara_query("UPDATE checkout_refund_attempts SET outcome='settled' WHERE request_id=? AND order_id=?", [$row['refund_request_id'], $id]);
            $event = 'refund_completed';
        } elseif (in_array($status, ['CANCELED', 'DEADLINE_EXPIRED', 'REJECTED', 'AUTH_FAIL', 'REVERSED'], true) && !(bool)$row['stock_applied']) {
            $next = match ($status) { 'DEADLINE_EXPIRED' => 'expired', 'REJECTED', 'AUTH_FAIL' => 'failed', default => 'canceled' };
            mahara_query('DELETE FROM checkout_reservations WHERE order_id=?', [$id]);
        }
        mahara_query("UPDATE checkout_orders SET payment_id=?,status=?,bank_status=?,init_outcome='accepted',init_error_code='',updated_at=? WHERE id=?", [(string)$reply['PaymentId'], $next, $status, time(), $id]);
        return mahara_payments_row($id);
    });
    if ($event !== null) mahara_payments_schedule_notification($id, $event);
    return $result;
}

function mahara_payments_notification(mixed $input): void {
    if (!is_array($input) || array_is_list($input)) mahara_fail('Некорректное уведомление.');
    $secrets = mahara_payments_secrets(); $token = $input['Token'] ?? '';
    if ($secrets['password'] === '' || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/Di', $token) || !hash_equals(mahara_tbank_token($input, $secrets['password']), strtolower($token))
        || ($input['TerminalKey'] ?? null) !== $secrets['terminal_key']) throw new MaharaHttpError(403, 'Подпись платежа не подтверждена.');
    if (!is_string($input['OrderId'] ?? null)) mahara_fail('Некорректный номер заказа.');
    mahara_payments_schema(); $row = mahara_query('SELECT * FROM checkout_orders WHERE number=?', [$input['OrderId']])->fetch();
    if (!$row) throw new MaharaHttpError(404, 'Заказ не найден.');
    mahara_payments_bank_identity($row, $input);
    if (($input['Success'] ?? null) !== true || (string)($input['ErrorCode'] ?? '') !== '0') {
        // A signed failure still does not imply a settled payment. Verify the
        // current bank status before releasing a reservation.
        mahara_payments_reconcile($row['id'], true); return;
    }
    mahara_payments_apply($row['id'], $input);
}

function mahara_payments_reconcile(string $id, bool $force = false): array {
    $row = mahara_payments_row($id);
    if (mahara_payments_config()['enabled'] !== true || in_array($row['status'], ['refunded', 'canceled', 'expired', 'failed'], true)) return $row;
    // A DB compare-and-update bounds concurrent polling to one bank request
    // per order per 30 seconds, including failed network attempts.
    $now = time();
    $claim = mahara_query('UPDATE checkout_orders SET last_checked=? WHERE id=? AND last_checked<=?', [$now, $id, $force ? $now - 2 : $now - 30]);
    if (!$claim->rowCount()) return $row;
    try {
        if (!$row['payment_id']) {
            if ($row['init_outcome'] !== 'rejected' && (int)$row['created_at'] > $now - 30) return $row;
            $check = mahara_tbank_call('CheckOrder', ['OrderId' => $row['number']]);
            if (($check['OrderId'] ?? null) !== $row['number'] || !is_array($check['Payments'] ?? null) || !array_is_list($check['Payments'])) return mahara_payments_row($id);
            if ($check['Payments'] === [] && $row['init_outcome'] === 'rejected' && mahara_payments_definite_rejection($row['init_error_code'])) {
                // Re-read under the write lock: a simultaneous signed callback
                // may have already bound/paid this order while CheckOrder ran.
                return mahara_payments_transaction(static function () use ($id): array {
                    $current = mahara_payments_row($id);
                    if ($current['status'] === 'payment_pending' && !$current['payment_id'] && !(bool)$current['stock_applied']
                        && $current['init_outcome'] === 'rejected' && mahara_payments_definite_rejection($current['init_error_code'])
                        && $current['terminal_key'] === mahara_payments_secrets()['terminal_key']) {
                        mahara_query("UPDATE checkout_orders SET status='failed',updated_at=? WHERE id=?", [time(), $id]);
                        mahara_query('DELETE FROM checkout_reservations WHERE order_id=?', [$id]);
                    }
                    return mahara_payments_row($id);
                });
            }
            if (count($check['Payments']) !== 1 || !is_array($check['Payments'][0])) return mahara_payments_row($id);
            $payment = $check['Payments'][0] + ['OrderId' => $row['number'], 'TerminalKey' => $row['terminal_key']];
            mahara_payments_bind($id, $payment); $row = mahara_payments_apply($id, $payment, true);
        } else {
            $state = mahara_tbank_call('GetState', ['PaymentId' => $row['payment_id']]);
            $row = mahara_payments_apply($id, $state, true);
        }
        // Never automatically Cancel after GetState: the customer could pay
        // between these calls, turning a cancellation into an unwanted refund.
        // RedirectDueDate prevents new payment; release only on bank-confirmed
        // CANCELED/DEADLINE_EXPIRED or another final unsuccessful status.
        if ($row['status'] === 'payment_pending' && (int)$row['expires_at'] > $now && !$row['payment_url']) {
            mahara_payments_qr($id); $row = mahara_payments_row($id);
        }
    } catch (MaharaHttpError | MaharaTbankRejection) { /* Preserve pending reservations on uncertainty. */ }
    return mahara_payments_row($id);
}

function mahara_payments_reconcile_pending(int $limit = 20, bool $expiredOnly = false): void {
    mahara_payments_schema();
    if (mahara_payments_config()['enabled'] !== true) return;
    $sql = 'SELECT id FROM checkout_orders WHERE status IN (\'payment_pending\',\'refund_pending\') AND last_checked<=?' . ($expiredOnly ? ' AND expires_at<=?' : '') . ' ORDER BY last_checked LIMIT ' . max(1, min(100, $limit));
    $params = [time() - 30]; if ($expiredOnly) $params[] = time();
    foreach (mahara_query($sql, $params)->fetchAll() as $row) mahara_payments_reconcile($row['id']);
}

function mahara_payments_refund(string $id, mixed $input): array {
    mahara_object($input, ['requestId'], 'Возврат'); $requestId = mahara_payments_uuid($input['requestId']);
    $row = mahara_payments_transaction(static function () use ($id, $requestId): array {
        $row = mahara_payments_row($id);
        $attempt = mahara_query('SELECT order_id FROM checkout_refund_attempts WHERE request_id=?', [$requestId])->fetch();
        if ($attempt) {
            if ($attempt['order_id'] !== $id) throw new MaharaHttpError(409, 'Идентификатор возврата уже использован.');
            return $row;
        }
        if ($row['refund_request_id'] !== null) {
            if ($row['refund_request_id'] === $requestId) return $row;
            if ($row['status'] !== 'paid' || $row['refund_outcome'] !== 'rejected') throw new MaharaHttpError(409, 'Возврат уже запрошен. Проверьте его статус.');
        }
        if ($row['status'] !== 'paid' || !$row['payment_id']) throw new MaharaHttpError(409, 'Полный возврат доступен только для оплаченного заказа.');
        mahara_payments_require_bank_connection();
        if ($row['terminal_key'] !== mahara_payments_secrets()['terminal_key']) throw new MaharaHttpError(409, 'Терминал заказа отличается от текущего. Сверьте возврат в кабинете Т-Кассы.');
        mahara_query("INSERT INTO checkout_refund_attempts (request_id,order_id,outcome,requested_at) VALUES (?,?,'requested',?)", [$requestId, $id, time()]);
        mahara_query("UPDATE checkout_orders SET refund_request_id=?,refund_requested_at=?,status='refund_pending',refund_outcome='requested',refund_error_code='',updated_at=? WHERE id=?", [$requestId, time(), time(), $id]);
        return mahara_payments_row($id) + ['new_refund' => true];
    });
    if (!empty($row['new_refund'])) {
        try {
            // Full Cancel requires no Receipt: bank creates the refund receipt.
            // Do not restock automatically: returned goods require inspection.
            mahara_tbank_call('Cancel', ['PaymentId' => $row['payment_id']]);
            mahara_payments_apply($id, mahara_tbank_call('GetState', ['PaymentId' => $row['payment_id']]), true);
        } catch (MaharaTbankRejection $error) {
            // Only a rejection of Cancel itself proves that financial request
            // failed; a rejected GetState after successful Cancel proves nothing.
            if ($error->operation === 'Cancel') mahara_payments_transaction(static function () use ($id, $requestId, $error): void {
                $outcome = mahara_payments_definite_rejection($error->providerCode) ? 'rejected_unverified' : 'unknown';
                mahara_query("UPDATE checkout_orders SET refund_outcome=?,refund_error_code=?,updated_at=? WHERE id=? AND refund_request_id=? AND status='refund_pending'", [$outcome, $error->providerCode, time(), $id, $requestId]);
                mahara_query("UPDATE checkout_refund_attempts SET outcome=?,error_code=? WHERE request_id=? AND order_id=? AND outcome NOT IN ('settled','rejected')", [$outcome, $error->providerCode, $requestId, $id]);
            });
            mahara_payments_reconcile($id, true);
        } catch (MaharaHttpError) {
            // Unknown outcome must never issue a second Cancel on retry.
            mahara_payments_transaction(static function () use ($id, $requestId): void {
                mahara_query("UPDATE checkout_orders SET refund_outcome='unknown',updated_at=? WHERE id=? AND refund_request_id=? AND status='refund_pending'", [time(), $id, $requestId]);
                mahara_query("UPDATE checkout_refund_attempts SET outcome='unknown' WHERE request_id=? AND order_id=? AND outcome='requested' AND EXISTS (SELECT 1 FROM checkout_orders WHERE id=? AND refund_request_id=? AND status='refund_pending')", [$requestId, $id, $id, $requestId]);
            });
        }
    } else mahara_payments_reconcile($id);
    return mahara_payments_view(mahara_payments_row($id), true);
}

function mahara_payments_fulfillment(string $id, mixed $input): array {
    mahara_object($input, ['status', 'trackingNumber'], 'Доставка заказа');
    if (!is_string($input['status']) || !in_array($input['status'], ['processing', 'shipped', 'completed'], true)) mahara_fail('Неизвестный статус доставки.');
    $tracking = mahara_text($input['trackingNumber'], 'Трек-номер', 100);
    $result = mahara_payments_transaction(static function () use ($id, $input, $tracking): array {
        $row = mahara_payments_row($id);
        if ($row['status'] !== 'paid') throw new MaharaHttpError(409, 'Отправка доступна только для оплаченного заказа.');
        mahara_query('UPDATE checkout_orders SET fulfillment_status=?,tracking_number=?,updated_at=? WHERE id=?', [$input['status'], $tracking, time(), $id]);
        return mahara_payments_view(mahara_payments_row($id), true);
    });
    if (in_array($input['status'], ['shipped', 'completed'], true)) mahara_payments_schedule_notification($id, 'order_' . $input['status']);
    return $result;
}

function mahara_payments_admin_list(array $input): array {
    if (array_diff(array_keys($input), ['page', 'pageSize', 'q', 'status'])) mahara_fail('Неизвестные параметры списка заказов.');
    $page = mahara_integer(isset($input['page']) && is_string($input['page']) && preg_match('/^[1-9][0-9]{0,8}$/D', $input['page']) ? (int)$input['page'] : ($input['page'] ?? 1), 1, 999999999, 'Страница');
    $pageSize = mahara_integer(isset($input['pageSize']) && is_string($input['pageSize']) && preg_match('/^[0-9]{1,3}$/D', $input['pageSize']) ? (int)$input['pageSize'] : ($input['pageSize'] ?? 50), 1, 100, 'Заказов на странице');
    $query = mahara_text($input['q'] ?? '', 'Поиск', 200);
    $status = $input['status'] ?? 'all';
    $groups = ['all' => [], 'paid' => ['paid'], 'pending' => ['payment_pending'], 'refunded' => ['refunded', 'partial_refunded', 'refund_pending'], 'failed' => ['failed', 'expired', 'canceled']];
    if (!is_string($status) || !isset($groups[$status])) mahara_fail('Неизвестный фильтр оплаты.');
    mahara_payments_schema();
    $fold = static function (string $text): string {
        if (function_exists('mb_strtolower')) return mb_strtolower($text, 'UTF-8');
        static $russian;
        $russian ??= array_combine(preg_split('//u', 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', -1, PREG_SPLIT_NO_EMPTY), preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY));
        return strtr(strtolower($text), $russian);
    };
    // Do not require SQLite's optional JSON1 extension or search JSON field names.
    mahara_db()->sqliteCreateFunction('mahara_order_search', static function (string $number, string $customer) use ($fold): string {
        $value = json_decode($customer, true, 64, JSON_THROW_ON_ERROR);
        return $fold(implode(' ', [$number, $value['name'] ?? '', $value['email'] ?? '', $value['phone'] ?? '']));
    }, 2);
    $where = []; $params = [];
    if ($query !== '') { $where[] = 'instr(mahara_order_search(number,customer_json),?)>0'; $params[] = $fold($query); }
    if ($groups[$status]) { $where[] = 'status IN (' . implode(',', array_fill(0, count($groups[$status]), '?')) . ')'; array_push($params, ...$groups[$status]); }
    $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)mahara_query('SELECT COUNT(*) FROM checkout_orders' . $condition, $params)->fetchColumn();
    $pages = max(1, (int)ceil($total / $pageSize)); $page = min($page, $pages);
    $orders = [];
    foreach (mahara_query('SELECT * FROM checkout_orders' . $condition . ' ORDER BY created_at DESC, number DESC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize), $params)->fetchAll() as $row) $orders[] = mahara_payments_view($row, true)['order'];
    return ['orders' => $orders, 'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total, 'pages' => $pages], 'query' => $query, 'status' => $status];
}

function mahara_payments_api(string $path, string $method): bool {
    $route = null; $id = null;
    $routes = ['/api/checkout/config' => 'config', '/api/checkout/quote' => 'quote', '/api/checkout/orders' => 'create', '/api/payments/tbank/notify' => 'notify', '/api/admin/orders' => 'admin_list'];
    if (isset($routes[$path])) $route = $routes[$path];
    elseif (preg_match('~^/api/checkout/orders/([a-f0-9]{32})$~D', $path, $match)) { $route = 'get'; $id = $match[1]; }
    elseif (preg_match('~^/api/admin/orders/([a-f0-9]{32})(?:/(refund|fulfillment|reconcile))?$~D', $path, $match)) { $route = isset($match[2]) ? $match[2] : 'admin_get'; $id = $match[1]; }
    if ($route === null) return false;
    $expected = in_array($route, ['config', 'get', 'admin_list', 'admin_get'], true) ? 'GET' : 'POST';
    if ($method !== $expected) throw new MaharaHttpError(405, 'Метод не поддерживается.');
    if (in_array($route, ['admin_list', 'admin_get', 'refund', 'fulfillment', 'reconcile'], true)) {
        $session = mahara_require_session();
        if ($method === 'POST') mahara_require_csrf($session);
    }
    if (in_array($route, ['quote', 'create'], true)) mahara_require_origin();
    if ($route === 'config') mahara_json(mahara_payments_public_config());
    if ($route === 'quote') {
        $input = mahara_read_json(16384); mahara_object($input, ['items'], 'Корзина');
        mahara_payments_schema(); mahara_json(mahara_inventory_transaction(static fn(): array => mahara_payments_quote($input['items'])));
    }
    if ($route === 'create') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $retry = mahara_throttle(hash('sha256', 'checkout:' . $ip), 20);
        if ($retry) { header('Retry-After: ' . $retry); throw new MaharaHttpError(429, 'Слишком много заказов. Повторите попытку позже.'); }
        $input = mahara_read_json(32768); $orderNotCreated = false;
        try { $result = mahara_payments_create($input, $orderNotCreated); }
        catch (MaharaHttpError $error) {
            if ($orderNotCreated) mahara_json(['error' => $error->getMessage(), 'orderNotCreated' => true], $error->status);
            throw $error;
        }
        mahara_json($result, 201);
    }
    if ($route === 'get') {
        $row = mahara_payments_row($id); $token = $_SERVER['HTTP_X_ORDER_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals($row['access_token'], $token)) throw new MaharaHttpError(404, 'Заказ не найден.');
        mahara_json(mahara_payments_view(mahara_payments_reconcile($id)));
    }
    if ($route === 'notify') {
        mahara_payments_notification(mahara_read_json(65536));
        header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); echo 'OK'; exit;
    }
    if ($route === 'admin_list') {
        mahara_json(mahara_payments_admin_list($_GET));
    }
    if ($route === 'admin_get') mahara_json(mahara_payments_view(mahara_payments_reconcile($id), true));
    if ($route === 'reconcile') {
        if (mahara_read_json(128) !== []) mahara_fail('Ожидается пустой запрос проверки.');
        mahara_json(mahara_payments_view(mahara_payments_reconcile($id, true), true));
    }
    if ($route === 'refund') mahara_json(mahara_payments_refund($id, mahara_read_json(8192)));
    if ($route === 'fulfillment') mahara_json(mahara_payments_fulfillment($id, mahara_read_json(8192)));
    return true;
}
