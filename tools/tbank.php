<?php
declare(strict_types=1);

// CLI only. Neither command enables checkout, opens its database, or creates a
// payment. Never accept credentials in argv, a URL, or an environment variable.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Official EACQ method and signing protocol, checked 2026-10-14:
// https://developer.tbank.ru/eacq/api/check-order
// https://developer.tbank.ru/eacq/intro/developer/token
const MAHARA_TBANK_SETUP_URL = 'https://securepay.tinkoff.ru/v2/CheckOrder';

function mahara_tbank_setup_credentials(mixed $key, mixed $password): array {
    if (!is_string($key) || $key === '' || strlen($key) > 64 || preg_match('/[\x00-\x20\x7f]/', $key) || !preg_match('//u', $key)) throw new RuntimeException('Invalid terminal key.');
    if (!is_string($password) || $password === '' || strlen($password) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $password) || !preg_match('//u', $password)) throw new RuntimeException('Invalid terminal password.');
    return ['terminal_key' => $key, 'password' => $password];
}

function mahara_tbank_setup_private_directory(): string {
    $config = mahara_config();
    // Do not accidentally save/check a different terminal from the application's
    // environment override. This CLI deliberately manages only the standard file.
    if ((getenv('MAHARA_TBANK_SECRETS') ?: '') !== '') throw new RuntimeException('Nonstandard terminal storage configured.');
    $directory = $config['data_dir'] ?? null;
    if (!is_string($directory) || str_contains($directory, "\0") || (!str_starts_with($directory, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $directory))) throw new RuntimeException('Private data_dir must be absolute.');
    $base = realpath($directory); $root = realpath($config['root'] ?? '');
    if (!$base || !$root || !is_dir($base)) throw new RuntimeException('Private directory missing.');
    $normalized = rtrim(str_replace('\\', '/', $base), '/'); $public = rtrim(str_replace('\\', '/', $root), '/');
    if (PHP_OS_FAMILY === 'Windows') { $normalized = strtolower($normalized); $public = strtolower($public); }
    if ($normalized === $public || str_starts_with($normalized, $public . '/')) throw new RuntimeException('Terminal storage must stay outside the public site.');
    return $base;
}

function mahara_tbank_setup_save(array $credentials, bool $replace = false): array {
    $credentials = mahara_tbank_setup_credentials($credentials['terminal_key'] ?? null, $credentials['password'] ?? null);
    $base = mahara_tbank_setup_private_directory(); $target = $base . '/tbank-secrets.php';
    if (is_link($target) || (file_exists($target) && (!is_file($target) || !$replace))) throw new RuntimeException('Terminal storage exists or is unsafe.');
    $temporary = $base . '/.tbank-secrets-' . bin2hex(random_bytes(12)) . '.tmp';
    $lockPath = $base . '/.tbank-setup.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) throw new RuntimeException('Unsafe terminal setup lock.');
    $file = null; $lock = null; $mode = umask(0077);
    try {
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false || !chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock private terminal storage.');
        if (is_link($target) || (file_exists($target) && (!is_file($target) || !$replace))) throw new RuntimeException('Terminal storage exists or is unsafe.');
        $file = @fopen($temporary, 'xb');
        if ($file === false || !chmod($temporary, 0600)) throw new RuntimeException('Cannot create private terminal storage.');
        $contents = "<?php\nreturn " . var_export($credentials, true) . ";\n";
        if (fwrite($file, $contents) !== strlen($contents) || !fflush($file) || (function_exists('fsync') && !fsync($file))) throw new RuntimeException('Cannot save terminal storage.');
        fclose($file); $file = null;
        // A hard link publishes the complete file atomically without overwriting
        // a concurrently created destination. Replace is a separate explicit opt-in.
        // Windows restricted tokens may deny hard links. All CLI writers still
        // serialize through the private lock and recheck the target before rename.
        $published = $replace || PHP_OS_FAMILY === 'Windows' ? @rename($temporary, $target) : @link($temporary, $target);
        if (!$published) throw new RuntimeException('Cannot publish private terminal storage.');
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
    if (!is_file($path) || is_link($path) || filesize($path) > 8192) throw new RuntimeException('Private terminal storage missing or unsafe.');
    $level = ob_get_level(); ob_start();
    try { $value = require $path; }
    catch (Throwable) { throw new RuntimeException('Invalid private terminal storage.'); }
    finally { while (ob_get_level() > $level) ob_end_clean(); }
    return mahara_tbank_setup_credentials($value['terminal_key'] ?? null, $value['password'] ?? null);
}

function mahara_tbank_setup_hidden(): string {
    if (!stream_isatty(STDIN)) throw new RuntimeException('Use --stdin for a private pipe.');
    if (PHP_OS_FAMILY === 'Windows') {
        $script = '$v = Read-Host "New terminal secret" -AsSecureString; $p = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($v); try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($p)) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($p) }';
        $process = proc_open(['powershell.exe', '-NoProfile', '-Command', $script], [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot read hidden input.');
        $password = stream_get_contents($pipes[1], 1025); fclose($pipes[1]);
        if (proc_close($process) !== 0 || $password === false) throw new RuntimeException('Cannot read hidden input.');
        return $password;
    }
    $terminal = @fopen('/dev/tty', 'rb');
    if ($terminal === false || !function_exists('shell_exec') || !function_exists('exec')) throw new RuntimeException('Cannot read hidden input.');
    $mode = shell_exec('stty -g < /dev/tty');
    if (!is_string($mode) || trim($mode) === '') { fclose($terminal); throw new RuntimeException('Cannot disable terminal echo.'); }
    exec('stty -echo < /dev/tty', $ignored, $status);
    if ($status !== 0) { fclose($terminal); throw new RuntimeException('Cannot disable terminal echo.'); }
    $restored = false;
    $restore = static function () use ($mode, &$restored): void {
        if (!$restored) { shell_exec('stty ' . escapeshellarg(trim($mode)) . ' < /dev/tty'); $restored = true; }
    };
    register_shutdown_function($restore);
    fwrite(STDERR, 'New terminal secret (hidden): ');
    try { return mahara_tbank_setup_line($terminal); }
    finally { $restore(); fclose($terminal); fwrite(STDERR, "\r\033[2K" . PHP_EOL); }
}

function mahara_tbank_setup_line($input): string {
    $line = fgets($input, 1026);
    if ($line === false || strlen($line) > 1025) throw new RuntimeException('Incomplete terminal input.');
    return rtrim($line, "\r\n");
}

function mahara_tbank_setup_input(bool $stdin, $input): array {
    if ($stdin) {
        if (stream_isatty($input)) throw new RuntimeException('--stdin requires a private pipe, not an echoed console.');
        return mahara_tbank_setup_credentials(mahara_tbank_setup_line($input), mahara_tbank_setup_line($input));
    }
    if (!stream_isatty(STDIN)) throw new RuntimeException('Use --stdin for a private pipe.');
    fwrite(STDERR, 'TerminalKey: '); $key = mahara_tbank_setup_line(STDIN);
    return mahara_tbank_setup_credentials($key, mahara_tbank_setup_hidden());
}

function mahara_tbank_setup_transport(string $url, array $payload, array $policy): array {
    if ($url !== MAHARA_TBANK_SETUP_URL || !function_exists('curl_init')) throw new RuntimeException('HTTPS cURL required for terminal diagnostics.');
    $body = ''; $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Cannot initialize terminal diagnostics.');
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
        if ($ok === false || (int)curl_getinfo($handle, CURLINFO_SSL_VERIFYRESULT) !== 0) throw new RuntimeException('TLS or bank request failed.');
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
    if (!is_file($caFile) || is_link($caFile)) throw new RuntimeException('Bank CA bundle missing.');
    $caHandle = @fopen($caFile, 'rb');
    if ($caHandle === false) throw new RuntimeException('Bank CA bundle missing.');
    fclose($caHandle);
    $policy = ['caFile' => $caFile, 'verifyPeer' => true, 'verifyHost' => 2, 'tlsMinimum' => '1.2', 'followRedirects' => false,
        'connectTimeout' => 5, 'timeout' => 10, 'maxBytes' => 65536];
    $response = ($transport ?? 'mahara_tbank_setup_transport')(MAHARA_TBANK_SETUP_URL, $payload, $policy);
    if (($response['tlsVerified'] ?? false) !== true || ($response['httpStatus'] ?? 0) !== 200 || !is_string($response['body'] ?? null) || strlen($response['body']) > $policy['maxBytes']) throw new RuntimeException('Unverified diagnostic response.');
    $reply = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($reply) || array_is_list($reply)) throw new RuntimeException('Malformed diagnostic response.');
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
    } catch (Throwable) {
        fwrite($error, "Terminal setup failed. Check input, private storage permissions, existing file and HTTPS/cURL/CA support. No bank response or credentials were printed; checkout settings were not changed.\n");
        return 1;
    }
}

if (!defined('MAHARA_TBANK_SETUP_LIBRARY_ONLY')) {
    define('MAHARA_LIBRARY_ONLY', true);
    require_once dirname(__DIR__) . '/server/app.php';
    exit(mahara_tbank_setup_cli(array_slice($argv, 1), STDIN, STDOUT, STDERR));
}
