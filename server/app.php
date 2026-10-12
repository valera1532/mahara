<?php
declare(strict_types=1);

// PHP 8.2+, PDO SQLite. All public routes are handled here or by router.php.
const MAHARA_SECTION_FIELDS = [
    'hero' => ['eyebrow', 'title', 'subtitle', 'description', 'button', 'image', 'secondaryImage'],
    'campaign' => ['eyebrow', 'title', 'accent', 'description', 'button', 'image'],
    'story' => ['eyebrow', 'title', 'accent', 'lead', 'description', 'image', 'secondaryImage'],
    'newsletter' => ['eyebrow', 'title', 'accent'],
];
const MAHARA_PRODUCT_FIELDS = ['id', 'title', 'subtitle', 'category', 'color', 'price', 'image', 'altImage', 'label', 'sizes', 'description', 'url', 'width', 'height'];
const MAHARA_PRODUCT_FACTS = ['composition', 'care', 'manufacturer', 'measurements'];
const MAHARA_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

final class MaharaHttpError extends RuntimeException {
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

function mahara_config(): array {
    static $config;
    if (isset($config)) return $config;
    $root = dirname(__DIR__);
    // Library fixtures may replace all private settings before the first read.
    // This branch never opens local credentials/configuration or the working DB.
    $testConfig = defined('MAHARA_LIBRARY_ONLY') ? ($GLOBALS['mahara_app_test_config'] ?? null) : null;
    $local = is_array($testConfig) ? $testConfig : (is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : []);
    if (!is_array($local)) throw new RuntimeException('Invalid server configuration.');
    $development = PHP_SAPI === 'cli-server' || getenv('APP_ENV') === 'development';
    $config = array_merge([
        'root' => $root,
        'data_dir' => getenv('DATA_DIR') ?: $root . '/data',
        'origin' => getenv('APP_ORIGIN') ?: '',
        'production' => !$development,
        'trust_proxy' => getenv('TRUST_PROXY') === '1',
        'session_ttl' => 8 * 60 * 60,
        'login_window' => 15 * 60,
        'login_pair_limit' => 8,
        'login_ip_limit' => 40,
    ], $local);
    if ($config['origin'] !== '' && !preg_match('~^https?://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$~D', $config['origin'])) throw new RuntimeException('APP_ORIGIN must be an origin without a trailing slash.');
    if ($config['production'] && $config['origin'] !== '' && !str_starts_with($config['origin'], 'https://')) throw new RuntimeException('Production APP_ORIGIN requires HTTPS.');
    if ($config['production']) {
        $dataDir = str_replace('\\', '/', $config['data_dir']);
        if (!str_starts_with($dataDir, '/') && !preg_match('~^[a-zA-Z]:/~', $dataDir)) throw new RuntimeException('Production data_dir must be an absolute path outside the public document root.');
        // Resolve all existing ancestors, so a symlink cannot turn a private path
        // into a directory within the web root. Nonexistent child paths are normalized.
        $pending = []; $ancestor = $config['data_dir'];
        while (!file_exists($ancestor)) {
            array_unshift($pending, basename($ancestor)); $parent = dirname($ancestor);
            if ($parent === $ancestor) throw new RuntimeException('Invalid private data directory.');
            $ancestor = $parent;
        }
        $resolved = str_replace('\\', '/', (string)realpath($ancestor));
        foreach ($pending as $part) {
            if ($part === '..') $resolved = dirname($resolved);
            elseif ($part !== '.') $resolved .= '/' . $part;
        }
        $rootPath = rtrim(str_replace('\\', '/', (string)realpath($root)), '/');
        if (PHP_OS_FAMILY === 'Windows') { $resolved = strtolower($resolved); $rootPath = strtolower($rootPath); }
        if ($resolved === $rootPath || str_starts_with($resolved, $rootPath . '/')) throw new RuntimeException('Production data_dir must be outside the public document root.');
    }
    return $config;
}

function mahara_db(): PDO {
    static $db;
    if (isset($db)) return $db;
    $dir = mahara_config()['data_dir'];
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create private data directory.');
    $db = new PDO('sqlite:' . $dir . '/mahara.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    @chmod($dir . '/mahara.sqlite', 0600);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    $db->exec('CREATE TABLE IF NOT EXISTS content (id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL, document TEXT NOT NULL, updated_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS sessions (token_hash TEXT PRIMARY KEY, admin_id INTEGER NOT NULL REFERENCES admins(id) ON DELETE CASCADE, csrf_token TEXT NOT NULL, expires_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS login_attempts (attempt_key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires_at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS session_expiry ON sessions(expires_at);
        CREATE INDEX IF NOT EXISTS attempt_expiry ON login_attempts(expires_at);');
    return $db;
}

function mahara_query(string $sql, array $params = []): PDOStatement {
    $statement = mahara_db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function mahara_fail(string $message): never { throw new MaharaHttpError(400, $message); }
function mahara_object(mixed $value, array $keys, string $label): void {
    if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), $keys) || array_diff($keys, array_keys($value))) mahara_fail($label . ': некорректные поля.');
}
function mahara_text(mixed $value, string $label, int $max = 200, bool $required = false): string {
    if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) mahara_fail($label . ': некорректный текст.');
    $value = trim($value);
    // Count Unicode characters without requiring the mbstring extension.
    if (preg_match_all('/./us', $value) > $max || ($required && $value === '')) mahara_fail($label . ': проверьте длину текста.');
    return $value;
}
function mahara_image_path(mixed $value, string $label): string {
    if (!is_string($value) || !preg_match('~^assets/(?:images|uploads)/[a-zA-Z0-9][a-zA-Z0-9._-]{0,199}\.(?:webp|png|jpe?g)$~D', $value) || str_contains($value, '..')) mahara_fail($label . ': используйте фотографию сайта или загрузите файл.');
    return $value;
}
function mahara_integer(mixed $value, int $min, int $max, string $label): int {
    if (!is_int($value) || $value < $min || $value > $max) mahara_fail($label . ': некорректное число.');
    return $value;
}
function mahara_validate_content(mixed $input, bool $revision = true): array {
    mahara_object($input, $revision ? ['version', 'sections', 'products', 'revision'] : ['version', 'sections', 'products'], 'Контент');
    if ($input['version'] !== 1) mahara_fail('Неподдерживаемая версия контента.');
    if ($revision) mahara_integer($input['revision'], 1, 9007199254740991, 'Версия публикации');
    mahara_object($input['sections'], array_keys(MAHARA_SECTION_FIELDS), 'Блоки страницы');
    $sections = [];
    foreach (MAHARA_SECTION_FIELDS as $name => $fields) {
        mahara_object($input['sections'][$name], $fields, $name);
        foreach ($fields as $key) {
            $sections[$name][$key] = str_contains(strtolower($key), 'image')
                ? mahara_image_path($input['sections'][$name][$key], "$name.$key")
                : mahara_text($input['sections'][$name][$key], "$name.$key", in_array($key, ['description', 'lead'], true) ? 5000 : 200);
        }
    }
    if (!is_array($input['products']) || !array_is_list($input['products']) || count($input['products']) > 500) mahara_fail('Каталог: максимум 500 товаров.');
    $products = []; $ids = [];
    foreach ($input['products'] as $index => $product) {
        $label = 'Товар ' . ($index + 1);
        if (!is_array($product) || array_is_list($product) || array_diff(array_keys($product), array_merge(MAHARA_PRODUCT_FIELDS, MAHARA_PRODUCT_FACTS)) || array_diff(MAHARA_PRODUCT_FIELDS, array_keys($product))) mahara_fail($label . ': некорректные поля.');
        if (!is_string($product['id']) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $product['id']) || isset($ids[$product['id']])) mahara_fail($label . ': укажите уникальный код из латинских букв, цифр и дефисов.');
        $ids[$product['id']] = true;
        if (!in_array($product['category'], ['hoodie', 'tee'], true)) mahara_fail($label . ': неизвестная категория.');
        if (!in_array($product['color'], ['black', 'green', 'burgundy', 'slate', 'white'], true)) mahara_fail($label . ': неизвестный цвет.');
        if ($product['url'] !== 'product-' . $product['id'] . '.html') mahara_fail($label . ': адрес должен соответствовать коду товара.');
        if (!is_array($product['sizes']) || !array_is_list($product['sizes']) || !count($product['sizes']) || count($product['sizes']) > 6) mahara_fail($label . ': выберите размеры.');
        $sizes = [];
        foreach ($product['sizes'] as $size) {
            if (!is_string($size) || !in_array($size, MAHARA_SIZES, true) || in_array($size, $sizes, true)) mahara_fail($label . ': некорректные размеры.');
            $sizes[] = $size;
        }
        $validated = [
            'id' => $product['id'], 'title' => mahara_text($product['title'], $label . ': название', 200, true),
            'subtitle' => mahara_text($product['subtitle'], $label . ': подзаголовок'), 'category' => $product['category'], 'color' => $product['color'],
            'price' => mahara_integer($product['price'], 0, 10000000, $label . ': цена'),
            'image' => mahara_image_path($product['image'], $label . ': фотография'), 'altImage' => mahara_image_path($product['altImage'], $label . ': вторая фотография'),
            'label' => mahara_text($product['label'], $label . ': метка'), 'sizes' => array_values(array_intersect(MAHARA_SIZES, $sizes)),
            'description' => mahara_text($product['description'], $label . ': описание', 5000), 'url' => $product['url'],
            'width' => mahara_integer($product['width'], 1, 16384, $label . ': ширина'), 'height' => mahara_integer($product['height'], 1, 16384, $label . ': высота'),
        ];
        foreach (MAHARA_PRODUCT_FACTS as $field) $validated[$field] = mahara_text($product[$field] ?? '', $label . ': ' . $field, 2000);
        $products[] = $validated;
    }
    return ['version' => 1, 'sections' => $sections, 'products' => $products];
}

function mahara_content(): array {
    $row = mahara_query('SELECT revision, document FROM content WHERE id=1')->fetch();
    if (!$row) {
        $defaults = json_decode(file_get_contents(mahara_config()['root'] . '/site-defaults.json'), true, 64, JSON_THROW_ON_ERROR);
        $value = mahara_validate_content($defaults, false);
        mahara_query('INSERT OR IGNORE INTO content (id, revision, document, updated_at) VALUES (1, 1, ?, ?)', [json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), time()]);
        $row = mahara_query('SELECT revision, document FROM content WHERE id=1')->fetch();
    }
    return array_merge(json_decode($row['document'], true, 64, JSON_THROW_ON_ERROR), ['revision' => (int)$row['revision']]);
}

function mahara_cookie_name(): string { return mahara_config()['production'] ? '__Host-mahara_session' : 'mahara_session'; }
function mahara_session(): ?array {
    $token = $_COOKIE[mahara_cookie_name()] ?? '';
    if (!is_string($token) || !preg_match('/^[a-zA-Z0-9_-]{43}$/D', $token)) return null;
    $session = mahara_query('SELECT sessions.token_hash, sessions.csrf_token, admins.username FROM sessions JOIN admins ON admins.id=sessions.admin_id WHERE token_hash=? AND expires_at>?', [hash('sha256', $token), time()])->fetch();
    return $session ?: null;
}
function mahara_require_session(): array {
    $session = mahara_session();
    if (!$session) throw new MaharaHttpError(401, 'Войдите в панель управления.');
    return $session;
}
function mahara_secure_request(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443') || (mahara_config()['trust_proxy'] && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function mahara_origin(): string {
    if (mahara_config()['origin'] !== '') return mahara_config()['origin'];
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/D', $host)) throw new MaharaHttpError(400, 'Некорректный адрес сайта.');
    return (mahara_secure_request() ? 'https://' : 'http://') . $host;
}
function mahara_require_origin(): void {
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== mahara_origin()) throw new MaharaHttpError(403, 'Запрос должен быть отправлен с этого сайта.');
    if (mahara_config()['production'] && !mahara_secure_request()) throw new MaharaHttpError(403, 'Для входа и управления сайтом требуется HTTPS.');
}
function mahara_require_csrf(array $session): void {
    mahara_require_origin();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($session['csrf_token'], $token)) throw new MaharaHttpError(403, 'Обновите страницу и повторите действие.');
}
function mahara_set_cookie(string $token, int $expires): void {
    setcookie(mahara_cookie_name(), $token, ['expires' => $expires, 'path' => '/', 'secure' => mahara_config()['production'], 'httponly' => true, 'samesite' => 'Strict']);
}
function mahara_random_token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function mahara_read_body(int $max): string {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) throw new MaharaHttpError(413, 'Файл или запрос слишком большой.');
    $stream = fopen('php://input', 'rb');
    $body = stream_get_contents($stream, $max + 1); fclose($stream);
    if ($body === false) throw new MaharaHttpError(400, 'Не удалось прочитать запрос.');
    if (strlen($body) > $max) throw new MaharaHttpError(413, 'Файл или запрос слишком большой.');
    return $body;
}
function mahara_read_json(int $max = 4194304): mixed {
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') throw new MaharaHttpError(415, 'Ожидается JSON.');
    try { return json_decode(mahara_read_body($max), true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new MaharaHttpError(400, 'Некорректный JSON.'); }
}
function mahara_json(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function mahara_throttle(string $key, int $limit): int {
    $now = time(); $end = $now + mahara_config()['login_window'];
    // Keep the increment and read in one transaction. Avoid RETURNING so
    // shared hosts with SQLite 3.24-3.34 work as well.
    mahara_db()->beginTransaction();
    try {
        mahara_query('INSERT INTO login_attempts (attempt_key, count, expires_at) VALUES (?, 1, ?) ON CONFLICT(attempt_key) DO UPDATE SET count=CASE WHEN expires_at<=? THEN 1 ELSE count+1 END, expires_at=CASE WHEN expires_at<=? THEN excluded.expires_at ELSE expires_at END', [$key, $end, $now, $now]);
        $row = mahara_query('SELECT count, expires_at FROM login_attempts WHERE attempt_key=?', [$key])->fetch();
        mahara_db()->commit();
    } catch (Throwable $error) { mahara_db()->rollBack(); throw $error; }
    return (int)$row['count'] > $limit ? (int)$row['expires_at'] - $now : 0;
}

function mahara_api(string $path, string $method): never {
    require_once __DIR__ . '/merchant.php';
    require_once __DIR__ . '/availability.php';
    if ($path === '/api/catalog/availability' && in_array($method, ['GET', 'HEAD'], true)) mahara_json(mahara_catalog_availability());
    require_once __DIR__ . '/inventory.php';
    require_once __DIR__ . '/shipping.php';
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/notifications.php';
    mahara_notifications_api($path, $method);
    mahara_shipping_api($path, $method);
    mahara_payments_api($path, $method);
    mahara_inventory_api($path, $method);
    if ($path === '/api/content' && in_array($method, ['GET', 'HEAD'], true)) mahara_json(mahara_content());
    if ($path === '/api/admin/session' && $method === 'GET') {
        $session = mahara_require_session(); mahara_json(['user' => ['username' => $session['username']], 'csrfToken' => $session['csrf_token']]);
    }
    if ($path === '/api/admin/login' && $method === 'POST') {
        mahara_require_origin();
        $body = mahara_read_json(8192);
        mahara_object($body, ['username', 'password'], 'Вход');
        if (!is_string($body['username']) || !is_string($body['password']) || strlen($body['username']) > 64 || strlen($body['password']) > 72) throw new MaharaHttpError(401, 'Неверный логин или пароль.');
        $username = strtolower($body['username']);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (mahara_config()['trust_proxy']) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            $candidate = trim(end($forwarded));
            if (filter_var($candidate, FILTER_VALIDATE_IP)) $ip = $candidate;
        }
        // Prune even when every attempt fails. Stop before allocating a new
        // username bucket once the source has exhausted its total allowance.
        mahara_query('DELETE FROM login_attempts WHERE expires_at<=?', [time()]);
        $retry = mahara_throttle(hash('sha256', 'ip:' . $ip), mahara_config()['login_ip_limit']);
        if (!$retry) $retry = mahara_throttle(hash('sha256', 'pair:' . $ip . ':' . $username), mahara_config()['login_pair_limit']);
        if ($retry) { header('Retry-After: ' . $retry); throw new MaharaHttpError(429, 'Слишком много попыток входа. Попробуйте позже.'); }
        $user = mahara_query('SELECT id, username, password_hash FROM admins WHERE username=?', [$username])->fetch();
        // A fixed valid bcrypt hash keeps unknown-user requests on the same expensive verification path.
        $encoded = $user['password_hash'] ?? '$2y$12$cOB2DBFaHDaqTXi08ypZceqqnIX5MvDzyIvwb9LVReYmLUm2w.WA2';
        $valid = password_verify($body['password'], $encoded);
        if (!$user || !$valid) throw new MaharaHttpError(401, 'Неверный логин или пароль.');
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) mahara_query('UPDATE admins SET password_hash=? WHERE id=?', [password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
        // Rotate any old session at each successful login.
        $previous = mahara_session();
        if ($previous) mahara_query('DELETE FROM sessions WHERE token_hash=?', [$previous['token_hash']]);
        mahara_query('DELETE FROM sessions WHERE expires_at<=?', [time()]);
        mahara_query('DELETE FROM login_attempts WHERE expires_at<=?', [time()]);
        $token = mahara_random_token(); $csrf = mahara_random_token(); $expires = time() + mahara_config()['session_ttl'];
        mahara_query('INSERT INTO sessions (token_hash, admin_id, csrf_token, expires_at) VALUES (?, ?, ?, ?)', [hash('sha256', $token), $user['id'], $csrf, $expires]);
        mahara_set_cookie($token, $expires);
        mahara_json(['user' => ['username' => $user['username']], 'csrfToken' => $csrf]);
    }
    if ($path === '/api/admin/logout' && $method === 'POST') {
        $session = mahara_require_session(); mahara_require_csrf($session);
        mahara_query('DELETE FROM sessions WHERE token_hash=?', [$session['token_hash']]); mahara_set_cookie('', time() - 3600);
        mahara_json(['ok' => true]);
    }
    if ($path === '/api/admin/content' && $method === 'PUT') {
        $session = mahara_require_session(); mahara_require_csrf($session);
        $input = mahara_read_json(); $value = mahara_validate_content($input); mahara_content();
        $result = mahara_query('UPDATE content SET revision=revision+1, document=?, updated_at=? WHERE id=1 AND revision=?', [json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), time(), $input['revision']]);
        if (!$result->rowCount()) throw new MaharaHttpError(409, 'Контент уже изменён в другой вкладке. Обновите страницу перед сохранением.');
        mahara_json(mahara_content());
    }
    if ($path === '/api/admin/uploads' && $method === 'POST') {
        $session = mahara_require_session(); mahara_require_csrf($session);
        $body = mahara_read_body(8 * 1024 * 1024);
        $info = @getimagesizefromstring($body);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($types[$info[2]]) || $info[0] < 1 || $info[1] < 1 || $info[0] > 16384 || $info[1] > 16384) throw new MaharaHttpError(400, 'Загрузите фотографию JPEG, PNG или WebP (не более 8 МБ).');
        $dir = mahara_config()['data_dir'] . '/uploads';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create upload storage.');
        if (is_link($dir)) throw new RuntimeException('Upload storage must not be a symbolic link.');
        $name = bin2hex(random_bytes(16)) . '.' . $types[$info[2]];
        $file = fopen($dir . '/' . $name, 'xb');
        if (!$file) throw new RuntimeException('Cannot save upload.');
        $written = fwrite($file, $body); fclose($file); @chmod($dir . '/' . $name, 0600);
        if ($written !== strlen($body)) { @unlink($dir . '/' . $name); throw new RuntimeException('Incomplete upload.'); }
        mahara_json(['url' => 'assets/uploads/' . $name], 201);
    }
    // Known API paths reject unsupported methods explicitly.
    if (in_array($path, ['/api/content', '/api/catalog/availability', '/api/admin/session', '/api/admin/login', '/api/admin/logout', '/api/admin/content', '/api/admin/uploads'], true)) throw new MaharaHttpError(405, 'Метод не поддерживается.');
    throw new MaharaHttpError(404, 'Страница не найдена.');
}

function mahara_safe_file(string $base, string $relative): ?string {
    $basePath = realpath($base); $file = realpath($base . '/' . $relative);
    if (!$basePath || !$file || !is_file($file) || !str_starts_with($file, $basePath . DIRECTORY_SEPARATOR)) return null;
    $cursor = $base;
    foreach (explode('/', $relative) as $part) { $cursor .= '/' . $part; if (is_link($cursor)) return null; }
    return $file;
}
function mahara_template(string $filename): ?string {
    $root = mahara_config()['root'];
    return mahara_safe_file($root, $filename) ?? mahara_safe_file($root . '/server/templates', $filename . '.php');
}
function mahara_send_file(string $file, string $type, bool $cache = false, bool $noStore = false): never {
    header('Content-Type: ' . $type); header('Content-Length: ' . filesize($file));
    header('Cache-Control: ' . ($noStore ? 'no-store' : ($cache ? 'public, max-age=3600' : 'no-cache')));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
    exit;
}
function mahara_dispatch(): never {
    try {
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: strict-origin-when-cross-origin'); header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    if (mahara_config()['production'] && mahara_secure_request()) header('Strict-Transport-Security: max-age=31536000');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $raw = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
        $path = rawurldecode($raw);
        if ($path === '/index.php') $path = '/';
        if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || str_contains($path, '//')) throw new MaharaHttpError(404, 'Страница не найдена.');
        if (str_starts_with($path, '/api/')) mahara_api($path, $method);
        if (!in_array($method, ['GET', 'HEAD'], true)) throw new MaharaHttpError(405, 'Метод не поддерживается.');
        $root = mahara_config()['root'];
        require_once __DIR__ . '/merchant.php';
        $legal = mahara_merchant_render($path);
        if ($legal !== null) {
            header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-cache');
            if ($method !== 'HEAD') echo $legal;
            exit;
        }
        if (in_array($path, ['/admin', '/admin/', '/admin.html', '/inventory.html', '/admin/inventory', '/orders.html', '/admin/orders'], true)) {
            header('Cache-Control: no-store');
            if (!mahara_session()) { header('Location: /admin/login', true, 302); exit; }
            if (in_array($path, ['/admin', '/admin/'], true)) { header('Location: /admin.html', true, 302); exit; }
            $page = in_array($path, ['/inventory.html', '/admin/inventory'], true) ? 'inventory.html' : (in_array($path, ['/orders.html', '/admin/orders'], true) ? 'orders.html' : 'admin.html');
            $file = mahara_template($page);
            if ($file) mahara_send_file($file, 'text/html; charset=utf-8', false, true);
        }
        if (in_array($path, ['/checkout', '/checkout.html'], true)) {
            $file = mahara_template('checkout.html');
            if ($file) mahara_send_file($file, 'text/html; charset=utf-8', false, true);
        }
        if (in_array($path, ['/admin/login', '/admin-login.html'], true)) {
            header('Cache-Control: no-store');
            if (mahara_session()) { header('Location: /admin.html', true, 302); exit; }
            $file = mahara_template('admin-login.html');
            if ($file) mahara_send_file($file, 'text/html; charset=utf-8', false, true);
        }
        if (preg_match('~^/assets/uploads/([a-f0-9]{32}\.(?:png|jpg|webp))$~D', $path, $match)) {
            $file = mahara_safe_file(mahara_config()['data_dir'] . '/uploads', $match[1]);
            if ($file) mahara_send_file($file, ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($file, PATHINFO_EXTENSION)], true);
        }
        if (in_array($path, ['/robots.txt', '/sitemap.xml'], true) && is_file(__DIR__ . '/render.php')) {
            require_once __DIR__ . '/render.php';
            $rendered = renderPage($path, mahara_content());
            if (is_string($rendered)) {
                header('Content-Type: ' . ($path === '/robots.txt' ? 'text/plain; charset=utf-8' : 'application/xml; charset=utf-8'));
                header('Cache-Control: no-cache'); if ($method !== 'HEAD') echo $rendered; exit;
            }
        }
        $publicFiles = ['styles.css', 'legal.css', 'script.js', 'catalog-data.js', 'cms-data.js', 'admin.css', 'admin.js', 'admin-login.js', 'inventory.css', 'inventory.js', 'management.css', 'checkout.css', 'checkout.js', 'orders.css', 'orders.js', 'robots.txt', 'sitemap.xml'];
        if (in_array(ltrim($path, '/'), $publicFiles, true)) {
            $file = mahara_safe_file($root, ltrim($path, '/'));
            $types = ['css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml; charset=utf-8'];
            if ($file) mahara_send_file($file, $types[pathinfo($file, PATHINFO_EXTENSION)], true);
        }
        if (preg_match('~^/assets/(?:images|icons)/[a-zA-Z0-9][a-zA-Z0-9._-]{0,199}\.(?:webp|png|jpe?g|svg|ico|woff2?)$~D', $path)) {
            $file = mahara_safe_file($root . '/assets', substr($path, strlen('/assets/')));
            $types = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
            if ($file) mahara_send_file($file, $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))], true);
        }
        if (in_array($path, ['/', '/index.html', '/catalog.html'], true) || preg_match('~^/product-[a-z0-9][a-z0-9-]{0,79}\.html$~D', $path)) {
            $content = mahara_content();
            if (str_starts_with($path, '/product-') && !in_array(substr($path, 1), array_column($content['products'], 'url'), true)) throw new MaharaHttpError(404, 'Товар не найден.');
            $render = __DIR__ . '/render.php';
            if (is_file($render)) {
                require_once $render;
                $html = renderPage($path, $content);
                if (is_string($html)) { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-cache'); if ($method !== 'HEAD') echo $html; exit; }
            }
            $file = mahara_template($path === '/' ? 'index.html' : substr($path, 1));
            if ($file) mahara_send_file($file, 'text/html; charset=utf-8');
        }
        throw new MaharaHttpError(404, 'Страница не найдена.');
    } catch (MaharaHttpError $error) { mahara_json(['error' => $error->getMessage()], $error->status); }
    catch (Throwable $error) { error_log('MAHARA backend error: ' . $error->getMessage()); mahara_json(['error' => 'Ошибка сервера. Повторите попытку позже.'], 500); }
}

if (!defined('MAHARA_LIBRARY_ONLY')) mahara_dispatch();
