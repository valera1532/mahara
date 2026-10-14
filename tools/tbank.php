<?php
declare(strict_types=1);

// CLI entry point; an authenticated controller may load the functions as a library.
// Neither command enables checkout or creates a payment. Never put credentials in argv/URLs/env.
if (PHP_SAPI !== 'cli' && !(defined('MAHARA_TBANK_SETUP_LIBRARY_ONLY') && MAHARA_TBANK_SETUP_LIBRARY_ONLY === true)) { http_response_code(403); exit; }

// Official EACQ method and signing protocol, checked 2026-10-14:
// https://developer.tbank.ru/eacq/api/check-order
// https://developer.tbank.ru/eacq/intro/developer/token
const MAHARA_TBANK_SETUP_URL = 'https://securepay.tinkoff.ru/v2/CheckOrder';

enum MaharaTbankSetupCode: string {
    case KeyEmpty = 'terminal_key_empty';
    case KeyInvalid = 'terminal_key_invalid';
    case PasswordEmpty = 'terminal_password_empty';
    case PasswordControls = 'terminal_password_control_chars';
    case PasswordInvalid = 'terminal_password_invalid';
    case ReadIncomplete = 'input_read_incomplete';
    case HiddenReadIncomplete = 'hidden_read_incomplete';
    case NotInteractive = 'input_not_interactive';
    case PipeRequired = 'private_pipe_required';
    case HiddenUnavailable = 'hidden_input_unavailable';
    case EchoFailed = 'hidden_echo_failed';
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
    case CurlUnavailable = 'diagnostic_curl_unavailable';
    case InitFailed = 'diagnostic_init_failed';
    case TLSFailed = 'diagnostic_tls_or_request_failed';
    case ResponseUnverified = 'diagnostic_response_unverified';
    case ResponseMalformed = 'diagnostic_response_malformed';
    case CAMissing = 'diagnostic_ca_missing';
    case Unexpected = 'unexpected_failure';
}

final class MaharaTbankSetupFailure extends RuntimeException {
    public function __construct(public readonly MaharaTbankSetupCode $reason) { parent::__construct('Terminal setup failed.'); }
}

function mahara_tbank_setup_fail(MaharaTbankSetupCode $reason): never { throw new MaharaTbankSetupFailure($reason); }

function mahara_tbank_setup_key(mixed $key): string {
    if ($key === '') mahara_tbank_setup_fail(MaharaTbankSetupCode::KeyEmpty);
    if (!is_string($key) || strlen($key) > 64 || preg_match('/[\x00-\x20\x7f]/', $key) || !preg_match('//u', $key)) mahara_tbank_setup_fail(MaharaTbankSetupCode::KeyInvalid);
    return $key;
}

function mahara_tbank_setup_credentials(mixed $key, mixed $password): array {
    $key = mahara_tbank_setup_key($key);
    if ($password === '') mahara_tbank_setup_fail(MaharaTbankSetupCode::PasswordEmpty);
    if (!is_string($password)) mahara_tbank_setup_fail(MaharaTbankSetupCode::PasswordInvalid);
    if (preg_match('/[\x00-\x1f\x7f]/', $password)) mahara_tbank_setup_fail(MaharaTbankSetupCode::PasswordControls);
    if (strlen($password) > 1024 || !preg_match('//u', $password)) mahara_tbank_setup_fail(MaharaTbankSetupCode::PasswordInvalid);
    return ['terminal_key' => $key, 'password' => $password];
}

function mahara_tbank_setup_private_directory(): string {
    $config = mahara_config();
    // Do not accidentally save/check a different terminal from the application's
    // environment override. This CLI deliberately manages only the standard file.
    if ((getenv('MAHARA_TBANK_SECRETS') ?: '') !== '') mahara_tbank_setup_fail(MaharaTbankSetupCode::PathOverride);
    $directory = $config['data_dir'] ?? null;
    if (!is_string($directory) || str_contains($directory, "\0") || (!str_starts_with($directory, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $directory))) mahara_tbank_setup_fail(MaharaTbankSetupCode::PathInvalid);
    $base = realpath($directory); $root = realpath($config['root'] ?? '');
    if (!$base || !$root || !is_dir($base)) mahara_tbank_setup_fail(MaharaTbankSetupCode::PathMissing);
    $normalized = rtrim(str_replace('\\', '/', $base), '/'); $public = rtrim(str_replace('\\', '/', $root), '/');
    if (PHP_OS_FAMILY === 'Windows') { $normalized = strtolower($normalized); $public = strtolower($public); }
    if ($normalized === $public || str_starts_with($normalized, $public . '/')) mahara_tbank_setup_fail(MaharaTbankSetupCode::PathPublic);
    return $base;
}

function mahara_tbank_setup_save(array $credentials, bool $replace = false): array {
    $credentials = mahara_tbank_setup_credentials($credentials['terminal_key'] ?? null, $credentials['password'] ?? null);
    $base = mahara_tbank_setup_private_directory(); $target = $base . '/tbank-secrets.php';
    if (is_link($target) || (file_exists($target) && (!is_file($target) || !$replace))) mahara_tbank_setup_fail(MaharaTbankSetupCode::StorageExists);
    $temporary = $base . '/.tbank-secrets-' . bin2hex(random_bytes(12)) . '.tmp';
    $lockPath = $base . '/.tbank-setup.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) mahara_tbank_setup_fail(MaharaTbankSetupCode::LockUnsafe);
    $file = null; $lock = null; $mode = umask(0077);
    try {
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false || !chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) mahara_tbank_setup_fail(MaharaTbankSetupCode::LockFailed);
        if (is_link($target) || (file_exists($target) && (!is_file($target) || !$replace))) mahara_tbank_setup_fail(MaharaTbankSetupCode::StorageExists);
        $file = @fopen($temporary, 'xb');
        if ($file === false || !chmod($temporary, 0600)) mahara_tbank_setup_fail(MaharaTbankSetupCode::CreateFailed);
        $contents = "<?php\nreturn " . var_export($credentials, true) . ";\n";
        if (fwrite($file, $contents) !== strlen($contents) || !fflush($file) || (function_exists('fsync') && !fsync($file))) mahara_tbank_setup_fail(MaharaTbankSetupCode::WriteFailed);
        fclose($file); $file = null;
        // A hard link publishes the complete file atomically without overwriting
        // a concurrently created destination. Replace is a separate explicit opt-in.
        // Windows restricted tokens may deny hard links. All CLI writers still
        // serialize through the private lock and recheck the target before rename.
        $published = $replace || PHP_OS_FAMILY === 'Windows' ? @rename($temporary, $target) : @link($temporary, $target);
        if (!$published) mahara_tbank_setup_fail(MaharaTbankSetupCode::PublishFailed);
        return ['saved' => true, 'checkoutChanged' => false];
    } finally {
        if (is_resource($file)) fclose($file);
        if (is_file($temporary)) @unlink($temporary);
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        umask($mode);
    }
}

function mahara_tbank_setup_saved(): array {
    // Explicit --saved is used only after the owner supplied a NEW secret through
    // save-terminal. Default checks always ask for fresh input instead.
    $path = mahara_tbank_setup_private_directory() . '/tbank-secrets.php';
    if (!is_file($path) || is_link($path) || filesize($path) > 8192) mahara_tbank_setup_fail(MaharaTbankSetupCode::SavedUnavailable);
    $level = ob_get_level(); ob_start();
    try { $value = require $path; }
    catch (Throwable) { mahara_tbank_setup_fail(MaharaTbankSetupCode::SavedInvalid); }
    finally { while (ob_get_level() > $level) ob_end_clean(); }
    return mahara_tbank_setup_credentials($value['terminal_key'] ?? null, $value['password'] ?? null);
}

function mahara_tbank_setup_hidden(): string {
    if (!stream_isatty(STDIN)) mahara_tbank_setup_fail(MaharaTbankSetupCode::NotInteractive);
    if (PHP_OS_FAMILY === 'Windows') {
        $script = '$v = Read-Host "New terminal secret" -AsSecureString; $p = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($v); try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($p)) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($p) }';
        $process = proc_open(['powershell.exe', '-NoProfile', '-Command', $script], [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) mahara_tbank_setup_fail(MaharaTbankSetupCode::HiddenUnavailable);
        $password = stream_get_contents($pipes[1], 1025); fclose($pipes[1]);
        if (proc_close($process) !== 0 || $password === false) mahara_tbank_setup_fail(MaharaTbankSetupCode::HiddenReadIncomplete);
        return $password;
    }
    if (!function_exists('shell_exec') || !function_exists('exec')) mahara_tbank_setup_fail(MaharaTbankSetupCode::HiddenUnavailable);
    $mode = shell_exec('stty -g < /dev/tty');
    if (!is_string($mode) || trim($mode) === '') mahara_tbank_setup_fail(MaharaTbankSetupCode::EchoFailed);
    exec('stty -echo < /dev/tty', $ignored, $status);
    if ($status !== 0) mahara_tbank_setup_fail(MaharaTbankSetupCode::EchoFailed);
    $restored = false;
    $restore = static function () use ($mode, &$restored): void {
        if (!$restored) { shell_exec('stty ' . escapeshellarg(trim($mode)) . ' < /dev/tty'); $restored = true; }
    };
    register_shutdown_function($restore);
    fwrite(STDERR, 'New terminal secret (hidden): ');
    // Keep the same PHP stream for both prompts: a second /dev/tty stream can
    // lose input already buffered by the key read on noncanonical terminals.
    try { return mahara_tbank_setup_line(STDIN, true); }
    finally { $restore(); fwrite(STDERR, "\r\033[2K" . PHP_EOL); }
}

function mahara_tbank_setup_line($input, bool $hidden = false): string {
    $line = fgets($input, 1026);
    if ($line === false || strlen($line) > 1025) mahara_tbank_setup_fail($hidden ? MaharaTbankSetupCode::HiddenReadIncomplete : MaharaTbankSetupCode::ReadIncomplete);
    return rtrim($line, "\r\n");
}

function mahara_tbank_setup_input(bool $stdin, $input): array {
    if ($stdin) {
        if (stream_isatty($input)) mahara_tbank_setup_fail(MaharaTbankSetupCode::PipeRequired);
        return mahara_tbank_setup_credentials(mahara_tbank_setup_line($input), mahara_tbank_setup_line($input));
    }
    if (!stream_isatty(STDIN)) mahara_tbank_setup_fail(MaharaTbankSetupCode::NotInteractive);
    fwrite(STDERR, 'TerminalKey: '); $key = mahara_tbank_setup_key(mahara_tbank_setup_line(STDIN));
    return mahara_tbank_setup_credentials($key, mahara_tbank_setup_hidden());
}

function mahara_tbank_setup_transport(string $url, array $payload, array $policy): array {
    if ($url !== MAHARA_TBANK_SETUP_URL || !function_exists('curl_init')) mahara_tbank_setup_fail(MaharaTbankSetupCode::CurlUnavailable);
    $body = ''; $handle = curl_init($url);
    if ($handle === false) mahara_tbank_setup_fail(MaharaTbankSetupCode::InitFailed);
    try {
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => $policy['connectTimeout'], CURLOPT_TIMEOUT => $policy['timeout'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_CAINFO => $policy['caFile'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $policy): int {
                if (strlen($body) + strlen($chunk) > $policy['maxBytes']) return 0;
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($ok === false || (int)curl_getinfo($handle, CURLINFO_SSL_VERIFYRESULT) !== 0) mahara_tbank_setup_fail(MaharaTbankSetupCode::TLSFailed);
        return ['httpStatus' => $status, 'body' => $body, 'tlsVerified' => true];
    } finally { curl_close($handle); }
}

function mahara_tbank_setup_check(array $credentials, ?callable $transport = null): array {
    $credentials = mahara_tbank_setup_credentials($credentials['terminal_key'] ?? null, $credentials['password'] ?? null);
    $probe = 'mahara-probe-' . bin2hex(random_bytes(16)); // 45 chars, no existing order data.
    $payload = ['TerminalKey' => $credentials['terminal_key'], 'OrderId' => $probe];
    $values = $payload + ['Password' => $credentials['password']]; ksort($values, SORT_STRING);
    $payload['Token'] = hash('sha256', implode('', $values));
    unset($values);
    $caFile = dirname(__DIR__) . '/server/certs/tbank-ca.pem';
    if (!is_file($caFile) || is_link($caFile)) mahara_tbank_setup_fail(MaharaTbankSetupCode::CAMissing);
    $caHandle = @fopen($caFile, 'rb');
    if ($caHandle === false) mahara_tbank_setup_fail(MaharaTbankSetupCode::CAMissing);
    fclose($caHandle);
    $policy = ['caFile' => $caFile, 'verifyPeer' => true, 'verifyHost' => 2, 'tlsMinimum' => '1.2', 'followRedirects' => false,
        'connectTimeout' => 5, 'timeout' => 10, 'maxBytes' => 65536];
    $response = ($transport ?? 'mahara_tbank_setup_transport')(MAHARA_TBANK_SETUP_URL, $payload, $policy);
    if (($response['tlsVerified'] ?? false) !== true || ($response['httpStatus'] ?? 0) !== 200 || !is_string($response['body'] ?? null) || strlen($response['body']) > $policy['maxBytes']) mahara_tbank_setup_fail(MaharaTbankSetupCode::ResponseUnverified);
    try { $reply = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { mahara_tbank_setup_fail(MaharaTbankSetupCode::ResponseMalformed); }
    if (!is_array($reply) || array_is_list($reply)) mahara_tbank_setup_fail(MaharaTbankSetupCode::ResponseMalformed);
    $code = $reply['ErrorCode'] ?? null;
    $numericCode = (is_int($code) || is_string($code)) && preg_match('/^[0-9]{1,10}$/D', (string)$code) ? (string)$code : null;
    $accepted = ($reply['Success'] ?? null) === true && $numericCode === '0'
        && is_string($reply['TerminalKey'] ?? null) && hash_equals($credentials['terminal_key'], $reply['TerminalKey'])
        && ($reply['OrderId'] ?? null) === $probe && ($reply['Payments'] ?? null) === [];
    // Not-found/error responses do not prove that authentication was checked.
    // Never echo bank Message/Details, request fields or payment/customer data.
    return ['tlsVerified' => true, 'credentialDiagnostics' => $accepted ? 'accepted' : 'unknown',
        'errorCode' => $numericCode, 'checkoutChanged' => false, 'createdPayments' => 0];
}

function mahara_tbank_setup_cli(array $arguments, $input, $output, $error, ?callable $transport = null): int {
    $usage = "Usage: php tools/tbank.php save-terminal [--stdin] [--replace]\n       php tools/tbank.php check-terminal [--stdin | --saved]\n--stdin: two lines from a private pipe, TerminalKey then NEW terminal secret. Never place them in command arguments.\n--saved: explicit diagnostic of the NEW credentials just saved by the owner. No checkout settings change.\n";
    if ($arguments === ['--help']) { fwrite($output, $usage); return 0; }
    try {
        $command = array_shift($arguments); $flags = array_fill_keys($arguments, true);
        if (!in_array($command, ['save-terminal', 'check-terminal'], true) || count($flags) !== count($arguments)
            || array_diff($arguments, $command === 'save-terminal' ? ['--stdin', '--replace'] : ['--stdin', '--saved'])
            || (isset($flags['--stdin']) && isset($flags['--saved']))) { fwrite($error, $usage); return 2; }
        $credentials = isset($flags['--saved']) ? mahara_tbank_setup_saved() : mahara_tbank_setup_input(isset($flags['--stdin']), $input);
        $result = $command === 'save-terminal' ? mahara_tbank_setup_save($credentials, isset($flags['--replace'])) : mahara_tbank_setup_check($credentials, $transport);
        unset($credentials);
        fwrite($output, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
        return $command === 'check-terminal' && $result['credentialDiagnostics'] !== 'accepted' ? 3 : 0;
    } catch (Throwable $failure) {
        $code = $failure instanceof MaharaTbankSetupFailure ? $failure->reason : MaharaTbankSetupCode::Unexpected;
        fwrite($error, json_encode(['error' => 'terminal_setup_failed', 'code' => $code->value, 'checkoutChanged' => false], JSON_THROW_ON_ERROR) . "\n");
        return 1;
    }
}

if (!defined('MAHARA_TBANK_SETUP_LIBRARY_ONLY')) {
    define('MAHARA_LIBRARY_ONLY', true);
    require_once dirname(__DIR__) . '/server/app.php';
    exit(mahara_tbank_setup_cli(array_slice($argv, 1), STDIN, STDOUT, STDERR));
}
