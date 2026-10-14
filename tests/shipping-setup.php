<?php
declare(strict_types=1);

// Isolated admin/storage fixtures; all API credentials and OAuth replies are fake.
if (($argv[1] ?? '') !== '--worker') {
    $directory = sys_get_temp_dir() . '/mahara-shipping-setup-' . bin2hex(random_bytes(10)); mkdir($directory, 0700); $status = 1;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $directory], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start shipping setup fixtures.');
        fclose($pipes[0]); echo stream_get_contents($pipes[1]); fwrite(STDERR, stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
    } finally { cdek_cleanup($directory, $directory); }
    exit($status);
}
$directory = $argv[2] ?? '';
if (!is_dir($directory) || dirname(str_replace('\\', '/', $directory)) !== str_replace('\\', '/', sys_get_temp_dir()) || !preg_match('/^mahara-shipping-setup-[a-f0-9]{20}$/D', basename($directory))) throw new RuntimeException('Invalid setup fixture path.');
mkdir($directory . '/private', 0700);
define('MAHARA_LIBRARY_ONLY', true);
$GLOBALS['mahara_app_test_config'] = ['data_dir' => $directory . '/private', 'origin' => 'https://cdek-fixture.example', 'production' => true, 'payments' => ['enabled' => false], 'shipping' => ['enabled' => false]];
require_once dirname(__DIR__) . '/server/app.php'; require_once dirname(__DIR__) . '/server/shipping-setup.php';
$checks = 0;
function cdek_check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function cdek_http_error(int $status, callable $operation, string $message): void { try { $operation(); } catch (MaharaHttpError $failure) { cdek_check($failure->status === $status, $message); return; } throw new RuntimeException($message); }
function cdek_reason(MaharaCdekSetupCode $reason, callable $operation, string $message): void { try { $operation(); } catch (MaharaCdekSetupFailure $failure) { cdek_check($failure->reason === $reason, $message); return; } throw new RuntimeException($message); }
function cdek_cleanup(string $path, string $base): void {
    $normalized = str_replace('\\', '/', $path); $root = str_replace('\\', '/', $base);
    if ($normalized !== $root && !str_starts_with($normalized, $root . '/')) throw new RuntimeException('Unsafe fixture cleanup.');
    if (is_dir($path) && !is_link($path)) { foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) cdek_cleanup($entry->getPathname(), $base); rmdir($path); }
    elseif (is_file($path) || is_link($path)) unlink($path);
}
$_SERVER = ['HTTPS' => 'on', 'HTTP_ORIGIN' => 'https://cdek-fixture.example', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => '0']; $_COOKIE = []; $_GET = [];
cdek_check(mahara_shipping_setup_prepare('GET') === ['status' => 302, 'location' => '/admin/login'], 'Anonymous GET redirects to login.');
cdek_check(!function_exists('mahara_cdek_setup_saved'), 'Anonymous request never loads the credential helper.');
cdek_http_error(401, static fn() => mahara_shipping_setup_prepare('POST', []), 'Anonymous POST is rejected.');
$token = mahara_random_token(); $csrf = mahara_random_token();
mahara_query('INSERT INTO admins(username,password_hash,created_at) VALUES(?,?,?)', ['fixture-owner', 'fixture-no-login', time()]);
mahara_query('INSERT INTO sessions(token_hash,admin_id,csrf_token,expires_at) VALUES(?,?,?,?)', [hash('sha256', $token), 1, $csrf, time() + 600]); $_COOKIE[mahara_cookie_name()] = $token;
$page = mahara_shipping_setup_prepare('GET');
cdek_check($page['status'] === 200 && $page['csrfToken'] === $csrf && !$page['secretPresent'] && !isset($page['account'], $page['password']), 'Authenticated model contains only CSRF, metadata and safe notice.');
$form = ['action' => 'save', 'csrf_token' => $csrf, 'account' => 'fixture-cdek-account', 'password' => 'fixture-not-a-real-cdek-secret'];
cdek_http_error(403, static fn() => mahara_shipping_setup_prepare('POST', array_replace($form, ['csrf_token' => 'wrong'])), 'Wrong CSRF is rejected.');
cdek_http_error(403, static fn() => mahara_shipping_setup_prepare('POST', array_diff_key($form, ['csrf_token' => true])), 'Missing CSRF is rejected.');
$_SERVER['HTTP_ORIGIN'] = 'https://foreign.example'; cdek_http_error(403, static fn() => mahara_shipping_setup_prepare('POST', $form), 'Foreign origin is rejected.');
unset($_SERVER['HTTP_ORIGIN']); cdek_http_error(403, static fn() => mahara_shipping_setup_prepare('POST', $form), 'Missing origin is rejected.');
$_SERVER['HTTP_ORIGIN'] = 'https://cdek-fixture.example'; unset($_SERVER['HTTPS']);
cdek_http_error(403, static fn() => mahara_shipping_setup_prepare('GET'), 'HTTP cannot display secret entry.');
cdek_http_error(403, static fn() => mahara_shipping_setup_prepare('POST', $form), 'HTTP cannot save credentials.'); $_SERVER['HTTPS'] = 'on';
cdek_http_error(405, static fn() => mahara_shipping_setup_prepare('PUT'), 'Unsupported methods are rejected.');
$_SERVER['CONTENT_LENGTH'] = '8193'; cdek_http_error(413, static fn() => mahara_shipping_setup_prepare('POST', $form), 'Oversized form is rejected.'); $_SERVER['CONTENT_LENGTH'] = '0';
$_SERVER['CONTENT_TYPE'] = 'application/json'; cdek_http_error(415, static fn() => mahara_shipping_setup_prepare('POST', $form), 'Wrong content type is rejected.'); $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
cdek_http_error(400, static fn() => mahara_shipping_setup_prepare('POST', $form + ['replace' => '1']), 'Overwrite action is unavailable.');
cdek_http_error(400, static fn() => mahara_shipping_setup_prepare('POST', array_replace($form, ['password' => ['unsafe']])), 'Non-scalar form fields are rejected.');
foreach ([['', 'fixture', MaharaCdekSetupCode::AccountEmpty], ['bad account', 'fixture', MaharaCdekSetupCode::AccountInvalid], [str_repeat('a', 201), 'fixture', MaharaCdekSetupCode::AccountInvalid], ['account', '', MaharaCdekSetupCode::PasswordEmpty], ['account', "bad\npassword", MaharaCdekSetupCode::PasswordInvalid], ['account', str_repeat('p', 501), MaharaCdekSetupCode::PasswordInvalid]] as [$account, $password, $reason]) cdek_reason($reason, static fn() => mahara_cdek_setup_credentials($account, $password), 'Malformed credentials fail locally with a safe enum.');
cdek_check(mahara_cdek_setup_credentials('account', ' secret ')['password'] === ' secret ', 'Password bytes are preserved without trimming.');
$saved = mahara_shipping_setup_prepare('POST', $form);
cdek_check($saved === ['status' => 303, 'location' => '/admin/shipping-setup?result=saved'], 'First save uses fixed PRG without credential parameters.');
$path = $directory . '/private/cdek-secrets.php'; $hash = hash_file('sha256', $path);
cdek_check(mahara_cdek_setup_saved() === ['account' => $form['account'], 'password' => $form['password']], 'Private saved file matches runtime credential shape.');
$repeat = mahara_shipping_setup_prepare('POST', array_replace($form, ['password' => 'different-fixture-secret']));
cdek_check($repeat['location'] === '/admin/shipping-setup?result=storage_exists_or_unsafe' && hash_file('sha256', $path) === $hash, 'Repeat save cannot replace credentials.');
$contents = file_get_contents($path); file_put_contents($path, '<?php throw new RuntimeException("GET-must-not-read-fixture");'); $page = mahara_shipping_setup_prepare('GET');
cdek_check($page['secretPresent'] && !isset($page['account'], $page['password']), 'GET uses metadata without reading secret file.'); file_put_contents($path, $contents); unset($contents);
$html = (static function ($view): string { $csrfToken = $view['csrfToken']; $secretPresent = $view['secretPresent']; $notice = $view['notice']; ob_start(); require dirname(__DIR__) . '/server/templates/admin-shipping-setup.php'; return (string)ob_get_clean(); })($page);
foreach (['account', 'password'] as $field) cdek_check(preg_match('/<input[^>]*name="' . $field . '"[^>]*>/', $html, $input) === 1 && !preg_match('/\bvalue\s*=/', $input[0]) && str_contains($input[0], 'autocomplete="' . ($field === 'password' ? 'new-password' : 'off') . '"'), 'Credential inputs stay empty; the separate API secret uses new-password instead of saved site credentials.');
cdek_check(!str_contains($html, $form['account']) && !str_contains($html, $form['password']) && substr_count($html, 'name="csrf_token"') === 3, 'HTML contains no credentials and every native form carries CSRF.');
$calls = 0; $oauthToken = 'fixture-oauth-token-never-output';
$transport = static function ($url, $payload, $policy) use (&$calls, $form, $oauthToken): array {
    $calls++;
    cdek_check($url === 'https://api.cdek.ru/v2/oauth/token' && $payload === ['grant_type' => 'client_credentials', 'client_id' => $form['account'], 'client_secret' => $form['password']], 'Explicit check calls only documented fixed OAuth with form credentials.');
    cdek_check($policy['verifyPeer'] && $policy['verifyHost'] === 2 && $policy['tlsMinimum'] === '1.2' && !$policy['followRedirects'] && $policy['timeout'] === 10 && $policy['maxBytes'] === 65536, 'OAuth check keeps verified TLS and strict request bounds.');
    return ['httpStatus' => 200, 'tlsVerified' => true, 'body' => json_encode(['access_token' => $oauthToken, 'expires_in' => 3599, 'token_type' => 'bearer'])];
};
cdek_check($calls === 0, 'Save and GET do not contact CDEK.');
$checkForm = ['action' => 'check', 'csrf_token' => $csrf];
cdek_http_error(403, static fn() => mahara_shipping_setup_prepare('POST', array_replace($checkForm, ['csrf_token' => 'wrong']), $transport), 'OAuth check requires CSRF.');
cdek_check($calls === 0, 'Rejected check does not contact CDEK.');
$checked = mahara_shipping_setup_prepare('POST', $checkForm, $transport);
cdek_check($calls === 1 && $checked['location'] === '/admin/shipping-setup?result=checked_authenticated', 'Only explicit authorized POST performs OAuth check.');
$result = mahara_cdek_setup_check(['account' => $form['account'], 'password' => $form['password']], $transport);
cdek_check($result === ['authenticated' => true] && !str_contains(json_encode($result), $oauthToken), 'Check returns only authentication success and discards OAuth token.');
foreach ([['access_token' => '', 'expires_in' => 3599, 'token_type' => 'Bearer'], ['access_token' => 'bad token', 'expires_in' => 3599, 'token_type' => 'Bearer'], ['access_token' => 'fixture', 'expires_in' => 0, 'token_type' => 'Bearer'], ['access_token' => 'fixture', 'expires_in' => '3599', 'token_type' => 'Bearer'], ['access_token' => 'fixture', 'expires_in' => 3599, 'token_type' => 'Basic'], ['access_token' => 'fixture', 'expires_in' => 3599], ['access_token' => 'fixture', 'expires_in' => 3599, 'token_type' => 'Bearer', 'error' => $form['password']]] as $reply) cdek_reason(MaharaCdekSetupCode::ResponseInvalid, static fn() => mahara_cdek_setup_check(['account' => $form['account'], 'password' => $form['password']], static fn() => ['httpStatus' => 200, 'tlsVerified' => true, 'body' => json_encode($reply)]), 'Invalid OAuth token fields are rejected without output.');
foreach ([['httpStatus' => 302, 'tlsVerified' => true, 'body' => '{}'], ['httpStatus' => 200, 'tlsVerified' => true, 'body' => 'invalid-json'], ['httpStatus' => 200, 'tlsVerified' => true, 'body' => str_repeat('x', 65537)]] as $response) cdek_reason(MaharaCdekSetupCode::ResponseInvalid, static fn() => mahara_cdek_setup_check(['account' => $form['account'], 'password' => $form['password']], static fn() => $response), 'Redirected, malformed or oversized replies fail closed.');
cdek_reason(MaharaCdekSetupCode::TLSFailed, static fn() => mahara_cdek_setup_check(['account' => $form['account'], 'password' => $form['password']], static fn() => ['httpStatus' => 200, 'tlsVerified' => false, 'body' => '{}']), 'Unverified TLS cannot authenticate.');
$rejected = mahara_shipping_setup_prepare('POST', $checkForm, static fn() => ['httpStatus' => 401, 'tlsVerified' => true, 'body' => json_encode(['error_description' => $form['password']])]);
cdek_check($rejected['location'] === '/admin/shipping-setup?result=oauth_rejected' && !str_contains(json_encode($rejected), $form['password']), 'Provider rejection is sanitized to an enum.');
$failed = mahara_shipping_setup_prepare('POST', $checkForm, static fn() => throw new RuntimeException($oauthToken . $form['password']));
cdek_check($failed['location'] === '/admin/shipping-setup?result=unexpected_failure', 'Raw provider exception never reaches response or logs.');
$_GET['result'] = '<script>fixture-secret</script>'; cdek_check(mahara_shipping_setup_prepare('GET')['notice'] === null, 'Unknown notice query is never reflected.');
putenv('MAHARA_CDEK_SECRETS=' . $directory . '/other.php'); cdek_reason(MaharaCdekSetupCode::PathOverride, static fn() => mahara_cdek_setup_private_directory(), 'Nonstandard secret env path is rejected.'); putenv('MAHARA_CDEK_SECRETS');
if (PHP_OS_FAMILY !== 'Windows') cdek_check((fileperms($path) & 0777) === 0600, 'Credential file permissions stay private.');
cdek_check(hash_file('sha256', $path) === $hash && mahara_config()['payments']['enabled'] === false && mahara_config()['shipping']['enabled'] === false, 'OAuth checks do not change stored credentials or enable flags.');
cdek_check(!mahara_query("SELECT name FROM sqlite_master WHERE name IN ('checkout_orders','checkout_reservations','inventory_balances','shipping_cache','shipping_quotes')")->fetch(), 'Setup never opens shipping cache or creates orders, shipments or stock.');
cdek_check(glob($directory . '/private/.cdek-secrets-*.tmp') === [], 'No temporary credentials remain.');
$logout = mahara_shipping_setup_prepare('POST', ['action' => 'logout', 'csrf_token' => $csrf]);
cdek_check($logout === ['status' => 303, 'location' => '/admin/login'] && mahara_session() === null, 'Native logout revokes the current session.');
echo 'PASS: ', $checks, " shipping setup checks (isolated auth/storage, mock OAuth only)\n";
