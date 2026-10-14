<?php
declare(strict_types=1);

if (!defined('MAHARA_SHIPPING_SETUP_INTERNAL') && !defined('MAHARA_LIBRARY_ONLY')) { http_response_code(403); exit; }

// OAuth only; no shipping/cache module, order creation or enable flags.
// Official API: https://apidoc.cdek.ru/ (client_credentials authorization).
const MAHARA_CDEK_SETUP_URL = 'https://api.cdek.ru/v2/oauth/token';

enum MaharaCdekSetupCode: string {
    case AccountEmpty = 'account_empty';
    case AccountInvalid = 'account_invalid';
    case PasswordEmpty = 'password_empty';
    case PasswordInvalid = 'password_invalid';
    case PathOverride = 'private_path_override';
    case PathInvalid = 'private_path_invalid';
    case PathMissing = 'private_path_missing';
    case PathPublic = 'private_path_public';
    case StorageExists = 'storage_exists_or_unsafe';
    case LockUnsafe = 'storage_lock_unsafe';
    case LockFailed = 'storage_lock_failed';
    case CreateFailed = 'storage_create_failed';
    case WriteFailed = 'storage_write_failed';
    case PublishFailed = 'storage_publish_failed';
    case SavedUnavailable = 'saved_storage_unavailable';
    case SavedInvalid = 'saved_storage_invalid';
    case CurlUnavailable = 'oauth_curl_unavailable';
    case TLSFailed = 'oauth_tls_or_request_failed';
    case Rejected = 'oauth_rejected';
    case ResponseInvalid = 'oauth_response_invalid';
    case Unexpected = 'unexpected_failure';
}

final class MaharaCdekSetupFailure extends RuntimeException {
    public function __construct(public readonly MaharaCdekSetupCode $reason) { parent::__construct('CDEK setup failed.'); }
}
function mahara_cdek_setup_fail(MaharaCdekSetupCode $reason): never { throw new MaharaCdekSetupFailure($reason); }

function mahara_cdek_setup_credentials(mixed $account, mixed $password): array {
    if ($account === '') mahara_cdek_setup_fail(MaharaCdekSetupCode::AccountEmpty);
    if (!is_string($account) || strlen($account) > 200 || preg_match('/[\x00-\x20\x7f]/', $account) || !preg_match('//u', $account)) mahara_cdek_setup_fail(MaharaCdekSetupCode::AccountInvalid);
    if ($password === '') mahara_cdek_setup_fail(MaharaCdekSetupCode::PasswordEmpty);
    if (!is_string($password) || strlen($password) > 500 || preg_match('/[\x00-\x1f\x7f]/', $password) || !preg_match('//u', $password)) mahara_cdek_setup_fail(MaharaCdekSetupCode::PasswordInvalid);
    return ['account' => $account, 'password' => $password];
}

function mahara_cdek_setup_private_directory(): string {
    if ((getenv('MAHARA_CDEK_SECRETS') ?: '') !== '') mahara_cdek_setup_fail(MaharaCdekSetupCode::PathOverride);
    $config = mahara_config(); $directory = $config['data_dir'] ?? null;
    if (!is_string($directory) || str_contains($directory, "\0") || (!str_starts_with($directory, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $directory))) mahara_cdek_setup_fail(MaharaCdekSetupCode::PathInvalid);
    $base = realpath($directory); $root = realpath($config['root'] ?? '');
    if (!$base || !$root || !is_dir($base)) mahara_cdek_setup_fail(MaharaCdekSetupCode::PathMissing);
    $normalized = rtrim(str_replace('\\', '/', $base), '/'); $public = rtrim(str_replace('\\', '/', $root), '/');
    if (PHP_OS_FAMILY === 'Windows') { $normalized = strtolower($normalized); $public = strtolower($public); }
    if ($normalized === $public || str_starts_with($normalized, $public . '/')) mahara_cdek_setup_fail(MaharaCdekSetupCode::PathPublic);
    return $base;
}

function mahara_cdek_setup_save(array $credentials): array {
    $credentials = mahara_cdek_setup_credentials($credentials['account'] ?? null, $credentials['password'] ?? null);
    $base = mahara_cdek_setup_private_directory(); $target = $base . '/cdek-secrets.php';
    if (is_link($target) || file_exists($target)) mahara_cdek_setup_fail(MaharaCdekSetupCode::StorageExists);
    $temporary = $base . '/.cdek-secrets-' . bin2hex(random_bytes(12)) . '.tmp'; $lockPath = $base . '/.cdek-setup.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) mahara_cdek_setup_fail(MaharaCdekSetupCode::LockUnsafe);
    $file = null; $lock = null; $mode = umask(0077);
    try {
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false || !chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) mahara_cdek_setup_fail(MaharaCdekSetupCode::LockFailed);
        clearstatcache(true, $target);
        if (is_link($target) || file_exists($target)) mahara_cdek_setup_fail(MaharaCdekSetupCode::StorageExists);
        $file = @fopen($temporary, 'xb');
        if ($file === false || !chmod($temporary, 0600)) mahara_cdek_setup_fail(MaharaCdekSetupCode::CreateFailed);
        $contents = "<?php\nreturn " . var_export($credentials, true) . ";\n";
        if (fwrite($file, $contents) !== strlen($contents) || !fflush($file) || (function_exists('fsync') && !fsync($file))) mahara_cdek_setup_fail(MaharaCdekSetupCode::WriteFailed);
        fclose($file); $file = null;
        // Publish the complete private file atomically. Unix link never replaces
        // another destination; Windows CLI writers serialize through this lock.
        $published = PHP_OS_FAMILY === 'Windows' ? @rename($temporary, $target) : @link($temporary, $target);
        if (!$published) mahara_cdek_setup_fail(MaharaCdekSetupCode::PublishFailed);
        return ['saved' => true];
    } finally {
        if (is_resource($file)) fclose($file);
        if (is_file($temporary)) @unlink($temporary);
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        umask($mode);
    }
}

function mahara_cdek_setup_saved(): array {
    $path = mahara_cdek_setup_private_directory() . '/cdek-secrets.php';
    if (!is_file($path) || is_link($path) || filesize($path) > 8192) mahara_cdek_setup_fail(MaharaCdekSetupCode::SavedUnavailable);
    $level = ob_get_level(); ob_start();
    try { $value = require $path; }
    catch (Throwable) { mahara_cdek_setup_fail(MaharaCdekSetupCode::SavedInvalid); }
    finally { while (ob_get_level() > $level) ob_end_clean(); }
    return mahara_cdek_setup_credentials($value['account'] ?? null, $value['password'] ?? null);
}

function mahara_cdek_setup_transport(string $url, array $payload, array $policy): array {
    if ($url !== MAHARA_CDEK_SETUP_URL || !function_exists('curl_init')) mahara_cdek_setup_fail(MaharaCdekSetupCode::CurlUnavailable);
    $body = ''; $handle = curl_init($url);
    if ($handle === false) mahara_cdek_setup_fail(MaharaCdekSetupCode::TLSFailed);
    try {
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => $policy['connectTimeout'], CURLOPT_TIMEOUT => $policy['timeout'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $policy): int {
                if (strlen($body) + strlen($chunk) > $policy['maxBytes']) return 0;
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($ok === false || (int)curl_getinfo($handle, CURLINFO_SSL_VERIFYRESULT) !== 0) mahara_cdek_setup_fail(MaharaCdekSetupCode::TLSFailed);
        return ['httpStatus' => $status, 'body' => $body, 'tlsVerified' => true];
    } finally { curl_close($handle); }
}

function mahara_cdek_setup_check(array $credentials, ?callable $transport = null): array {
    $credentials = mahara_cdek_setup_credentials($credentials['account'] ?? null, $credentials['password'] ?? null);
    $payload = ['grant_type' => 'client_credentials', 'client_id' => $credentials['account'], 'client_secret' => $credentials['password']];
    $policy = ['verifyPeer' => true, 'verifyHost' => 2, 'tlsMinimum' => '1.2', 'followRedirects' => false, 'connectTimeout' => 5, 'timeout' => 10, 'maxBytes' => 65536];
    $response = ($transport ?? 'mahara_cdek_setup_transport')(MAHARA_CDEK_SETUP_URL, $payload, $policy);
    if (($response['tlsVerified'] ?? false) !== true) mahara_cdek_setup_fail(MaharaCdekSetupCode::TLSFailed);
    if (in_array($response['httpStatus'] ?? null, [400, 401, 403], true)) mahara_cdek_setup_fail(MaharaCdekSetupCode::Rejected);
    if (($response['httpStatus'] ?? 0) !== 200 || !is_string($response['body'] ?? null) || strlen($response['body']) > $policy['maxBytes']) mahara_cdek_setup_fail(MaharaCdekSetupCode::ResponseInvalid);
    try { $reply = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { mahara_cdek_setup_fail(MaharaCdekSetupCode::ResponseInvalid); }
    if (!is_array($reply) || array_is_list($reply) || array_key_exists('error', $reply) || array_key_exists('errors', $reply)) mahara_cdek_setup_fail(MaharaCdekSetupCode::ResponseInvalid);
    $token = $reply['access_token'] ?? null; $expires = $reply['expires_in'] ?? null; $type = $reply['token_type'] ?? null;
    if (!is_string($token) || $token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token)
        || !is_int($expires) || $expires <= 0 || !is_string($type) || strcasecmp($type, 'Bearer') !== 0) mahara_cdek_setup_fail(MaharaCdekSetupCode::ResponseInvalid);
    // Check-only OAuth: discard the bearer token, never return/cache/persist it.
    unset($reply, $response, $payload, $credentials, $token);
    return ['authenticated' => true];
}
