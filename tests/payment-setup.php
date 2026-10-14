<?php
declare(strict_types=1);

// Isolated auth/storage fixtures and mock bank only; no local config or secrets.
if (($argv[1] ?? '') !== '--worker') {
    $directory = sys_get_temp_dir() . '/mahara-payment-setup-' . bin2hex(random_bytes(10)); mkdir($directory, 0700);
    $status = 1;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $directory], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start setup fixtures.');
        fclose($pipes[0]); echo stream_get_contents($pipes[1]); fwrite(STDERR, stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
    } finally { setup_cleanup($directory, $directory); }
    exit($status);
}
$directory = $argv[2] ?? '';
if (!is_dir($directory) || dirname(str_replace('\\', '/', $directory)) !== str_replace('\\', '/', sys_get_temp_dir()) || !preg_match('/^mahara-payment-setup-[a-f0-9]{20}$/D', basename($directory))) throw new RuntimeException('Invalid setup fixture path.');
mkdir($directory . '/private', 0700);
define('MAHARA_LIBRARY_ONLY', true);
$GLOBALS['mahara_app_test_config'] = ['data_dir' => $directory . '/private', 'origin' => 'https://setup-fixture.example', 'production' => true, 'payments' => ['enabled' => false]];
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/payment-setup.php';
$checks = 0;
function setup_check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function setup_error(int $status, callable $operation, string $message): void { try { $operation(); } catch (MaharaHttpError $failure) { setup_check($failure->status === $status, $message); return; } throw new RuntimeException($message); }
function setup_cleanup(string $path, string $base): void {
    $normalized = str_replace('\\', '/', $path); $root = str_replace('\\', '/', $base);
    if ($normalized !== $root && !str_starts_with($normalized, $root . '/')) throw new RuntimeException('Unsafe setup cleanup.');
    if (is_dir($path) && !is_link($path)) { foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) setup_cleanup($entry->getPathname(), $base); rmdir($path); }
    elseif (is_file($path) || is_link($path)) unlink($path);
}
$_SERVER = ['HTTPS' => 'on', 'HTTP_ORIGIN' => 'https://setup-fixture.example', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => '0'];
$_COOKIE = []; $_GET = [];
setup_check(mahara_payment_setup_prepare('GET') === ['status' => 302, 'location' => '/admin/login'], 'Unauthenticated page redirects to login.');
setup_check(!defined('MAHARA_TBANK_SETUP_LIBRARY_ONLY'), 'Unauthenticated GET does not load credential code.');
setup_error(401, static fn() => mahara_payment_setup_prepare('POST', []), 'Unauthenticated POST is rejected.');
setup_check(!defined('MAHARA_TBANK_SETUP_LIBRARY_ONLY'), 'Unauthenticated POST does not load credential code.');
$token = mahara_random_token(); $csrf = mahara_random_token();
mahara_query('INSERT INTO admins(username,password_hash,created_at) VALUES(?,?,?)', ['fixture-owner', 'fixture-no-login', time()]);
mahara_query('INSERT INTO sessions(token_hash,admin_id,csrf_token,expires_at) VALUES(?,?,?,?)', [hash('sha256', $token), 1, $csrf, time() + 600]);
$_COOKIE[mahara_cookie_name()] = $token;
$page = mahara_payment_setup_prepare('GET');
setup_check($page['status'] === 200 && $page['csrfToken'] === $csrf && $page['terminalKey'] === '1787834059773' && !$page['secretPresent'], 'Authenticated page carries CSRF, public prefill and metadata only.');
$form = ['action' => 'save', 'csrf_token' => $csrf, 'terminal_key' => 'FixtureTerminal', 'terminal_password' => 'fixture-not-a-real-terminal-secret'];
setup_error(403, static fn() => mahara_payment_setup_prepare('POST', array_replace($form, ['csrf_token' => 'wrong'])), 'Wrong form CSRF is rejected.');
setup_error(403, static fn() => mahara_payment_setup_prepare('POST', array_diff_key($form, ['csrf_token' => true])), 'Missing form CSRF is rejected.');
$_SERVER['HTTP_ORIGIN'] = 'https://foreign.example'; setup_error(403, static fn() => mahara_payment_setup_prepare('POST', $form), 'Foreign-origin POST is rejected.');
unset($_SERVER['HTTP_ORIGIN']); setup_error(403, static fn() => mahara_payment_setup_prepare('POST', $form), 'Missing Origin POST is rejected.');
$_SERVER['HTTP_ORIGIN'] = 'https://setup-fixture.example'; unset($_SERVER['HTTPS']);
setup_error(403, static fn() => mahara_payment_setup_prepare('GET'), 'HTTP cannot show the password form.');
setup_error(403, static fn() => mahara_payment_setup_prepare('POST', $form), 'HTTP cannot submit secrets.');
$_SERVER['HTTPS'] = 'on';
setup_error(405, static fn() => mahara_payment_setup_prepare('PUT'), 'Unsupported methods are rejected.');
$_SERVER['CONTENT_LENGTH'] = '8193'; setup_error(413, static fn() => mahara_payment_setup_prepare('POST', $form), 'Oversized form is rejected.'); $_SERVER['CONTENT_LENGTH'] = '0';
$_SERVER['CONTENT_TYPE'] = 'application/json'; setup_error(415, static fn() => mahara_payment_setup_prepare('POST', $form), 'Unexpected body format is rejected.'); $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
setup_error(400, static fn() => mahara_payment_setup_prepare('POST', $form + ['replace' => '1']), 'First-time form cannot request overwrite.');
setup_error(400, static fn() => mahara_payment_setup_prepare('POST', array_replace($form, ['terminal_password' => ['unsafe']])), 'Array fields are rejected.');
$invalid = mahara_payment_setup_prepare('POST', array_replace($form, ['terminal_password' => "\033fixture"]));
setup_check($invalid === ['status' => 303, 'location' => '/admin/payment-setup?result=terminal_password_control_chars'], 'Input failures use PRG with a safe enum only.');
setup_check(!is_file($directory . '/private/tbank-secrets.php'), 'Rejected form never saves a file.');
$saved = mahara_payment_setup_prepare('POST', $form);
setup_check($saved === ['status' => 303, 'location' => '/admin/payment-setup?result=saved'], 'Successful first save uses fixed PRG.');
$path = $directory . '/private/tbank-secrets.php'; $savedHash = hash_file('sha256', $path);
setup_check(is_file($path) && !str_contains(json_encode($saved), $form['terminal_password']), 'Saved output never contains credentials.');
$repeat = mahara_payment_setup_prepare('POST', array_replace($form, ['terminal_password' => 'different-fixture-secret']));
setup_check($repeat['location'] === '/admin/payment-setup?result=storage_exists_or_unsafe' && hash_file('sha256', $path) === $savedHash, 'Repeated save cannot overwrite existing credentials.');
$contents = file_get_contents($path); file_put_contents($path, '<?php throw new RuntimeException("GET-must-not-read-fixture");');
$page = mahara_payment_setup_prepare('GET');
setup_check($page['secretPresent'] && !array_key_exists('terminal_password', $page), 'GET uses file metadata without reading or echoing stored secrets.');
file_put_contents($path, $contents); unset($contents);
$html = (static function (array $view): string {
    $csrfToken = $view['csrfToken']; $terminalKey = $view['terminalKey']; $secretPresent = $view['secretPresent']; $notice = $view['notice'];
    ob_start(); require dirname(__DIR__) . '/server/templates/admin-payment-setup.php'; return (string)ob_get_clean();
})($page);
setup_check(!str_contains($html, $form['terminal_password']) && preg_match('/<input[^>]*name="terminal_password"[^>]*>/', $html, $passwordInput) === 1 && !preg_match('/\\bvalue\\s*=/', $passwordInput[0]), 'Rendered form never echoes or prepopulates the password.');
setup_check(substr_count($html, 'name="csrf_token"') === 3 && str_contains($html, 'name="action" value="logout"'), 'Every native form, including logout, carries the current CSRF token.');
$calls = 0;
$transport = static function (string $url, array $payload, array $policy) use (&$calls): array {
    $calls++;
    setup_check($url === 'https://securepay.tinkoff.ru/v2/CheckOrder' && array_keys($payload) === ['TerminalKey', 'OrderId', 'Token'], 'Explicit check calls only read-only CheckOrder without a plaintext password.');
    setup_check($policy['verifyPeer'] && $policy['verifyHost'] === 2 && !$policy['followRedirects'], 'Explicit check keeps TLS verification.');
    return ['httpStatus' => 200, 'tlsVerified' => true, 'body' => json_encode(['Success' => true, 'ErrorCode' => '0', 'TerminalKey' => $payload['TerminalKey'], 'OrderId' => $payload['OrderId'], 'Payments' => []])];
};
setup_check($calls === 0, 'Save and GET never contact the bank.');
$checkForm = ['action' => 'check', 'csrf_token' => $csrf];
setup_error(403, static fn() => mahara_payment_setup_prepare('POST', array_replace($checkForm, ['csrf_token' => 'wrong']), $transport), 'CSRF protects the bank check.');
setup_check($calls === 0, 'Rejected check never contacts the bank.');
$checked = mahara_payment_setup_prepare('POST', $checkForm, $transport);
setup_check($calls === 1 && $checked['location'] === '/admin/payment-setup?result=checked_accepted', 'Only an explicit authorized POST checks saved credentials.');
$unknown = mahara_payment_setup_prepare('POST', $checkForm, static fn() => ['httpStatus' => 200, 'tlsVerified' => true, 'body' => json_encode(['Success' => false, 'ErrorCode' => '335', 'Details' => 'fixture-not-a-real-terminal-secret'])]);
setup_check($unknown['location'] === '/admin/payment-setup?result=checked_unknown', 'Unknown-order response never claims credential success.');
$failed = mahara_payment_setup_prepare('POST', $checkForm, static fn() => throw new RuntimeException('fixture-not-a-real-terminal-secret'));
setup_check($failed['location'] === '/admin/payment-setup?result=unexpected_failure', 'Raw provider exceptions are never logged or reflected.');
setup_check(hash_file('sha256', $path) === $savedHash && mahara_config()['payments']['enabled'] === false, 'Checks do not change secret contents or checkout enabled state.');
setup_check(!mahara_query("SELECT name FROM sqlite_master WHERE name IN ('checkout_orders','checkout_reservations','inventory_balances')")->fetch(), 'Setup never initializes or changes orders, reservations or stock.');
$_GET['result'] = '<script>fixture-secret</script>';
setup_check(mahara_payment_setup_prepare('GET')['notice'] === null, 'Unrecognized query notices are never reflected.');
if (PHP_OS_FAMILY !== 'Windows') setup_check((fileperms($path) & 0777) === 0600, 'Secret file retains private permissions.');
$logout = mahara_payment_setup_prepare('POST', ['action' => 'logout', 'csrf_token' => $csrf]);
setup_check($logout === ['status' => 303, 'location' => '/admin/login'] && mahara_session() === null, 'Native logout revokes the current session and redirects to login.');
echo 'PASS: ', $checks, " payment setup checks (isolated auth/storage, mock bank only)\n";
