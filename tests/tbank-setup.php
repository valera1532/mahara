<?php
declare(strict_types=1);

// All credentials here are fictitious. No local config, private credentials,
// storefront database or real provider request is loaded or executed.
define('MAHARA_TBANK_SETUP_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/tools/tbank.php';
function mahara_config(): array { return $GLOBALS['tbank_setup_config']; }
$base = sys_get_temp_dir() . '/mahara-tbank-setup-' . bin2hex(random_bytes(10));
mkdir($base, 0700); mkdir($base . '/public', 0700); mkdir($base . '/private', 0700);
$GLOBALS['tbank_setup_config'] = ['root' => $base . '/public', 'data_dir' => $base . '/private'];
$checks = 0;
function tbank_check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function tbank_reject(callable $operation, string $message): void { try { $operation(); } catch (Throwable) { tbank_check(true, $message); return; } throw new RuntimeException($message); }
function tbank_reason(callable $operation, MaharaTbankSetupCode $expected, string $message): void {
    try { $operation(); }
    catch (MaharaTbankSetupFailure $failure) { tbank_check($failure->reason === $expected, $message); return; }
    throw new RuntimeException($message);
}
function tbank_fixture_response(array $payload, array $changes = []): array {
    return ['httpStatus' => 200, 'tlsVerified' => true, 'body' => json_encode(array_merge(['TerminalKey' => $payload['TerminalKey'], 'OrderId' => $payload['OrderId'], 'Success' => true, 'ErrorCode' => '0', 'Payments' => []], $changes), JSON_THROW_ON_ERROR)];
}
function tbank_cli(array $args, string $input, ?callable $transport = null): array {
    $in = fopen('php://temp', 'w+b'); $out = fopen('php://temp', 'w+b'); $err = fopen('php://temp', 'w+b');
    fwrite($in, $input); rewind($in);
    $status = mahara_tbank_setup_cli($args, $in, $out, $err, $transport);
    rewind($out); rewind($err); $result = [$status, stream_get_contents($out), stream_get_contents($err)];
    fclose($in); fclose($out); fclose($err); return $result;
}
function tbank_cleanup(string $path, string $base): void {
    $resolvedPath = str_replace('\\', '/', $path); $resolvedBase = str_replace('\\', '/', $base);
    if ($resolvedPath !== $resolvedBase && !str_starts_with($resolvedPath, $resolvedBase . '/')) throw new RuntimeException('Unsafe fixture cleanup.');
    if (is_dir($path) && !is_link($path)) { foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) tbank_cleanup($entry->getPathname(), $base); rmdir($path); }
    else if (is_file($path) || is_link($path)) unlink($path);
}
$credentials = ['terminal_key' => 'FixtureTerminal', 'password' => 'fixture-not-a-real-bank-secret'];
try {
    foreach ([['', 'fixture', MaharaTbankSetupCode::KeyEmpty], ["bad key", 'fixture', MaharaTbankSetupCode::KeyInvalid],
        ['key', '', MaharaTbankSetupCode::PasswordEmpty], ['key', "\033[200~fixture\033[201~", MaharaTbankSetupCode::PasswordControls],
        ['key', "fixture\rsecret", MaharaTbankSetupCode::PasswordControls], ['key', "\xff", MaharaTbankSetupCode::PasswordInvalid],
        ['key', str_repeat('x', 1025), MaharaTbankSetupCode::PasswordInvalid]] as [$key, $password, $reason]) {
        tbank_reason(static fn() => mahara_tbank_setup_credentials($key, $password), $reason, 'Input failures expose only the matching safe enum.');
    }
    foreach ([['', MaharaTbankSetupCode::KeyEmpty], ['bad key', MaharaTbankSetupCode::KeyInvalid]] as [$key, $reason]) {
        tbank_reason(static fn() => mahara_tbank_setup_key($key), $reason, 'Interactive key validation can fail before requesting a secret.');
    }
    $emptyInput = fopen('php://temp', 'w+b');
    tbank_reason(static fn() => mahara_tbank_setup_line($emptyInput, true), MaharaTbankSetupCode::HiddenReadIncomplete, 'Hidden EOF is distinct from an empty completed password.');
    tbank_reason(static fn() => mahara_tbank_setup_line($emptyInput), MaharaTbankSetupCode::ReadIncomplete, 'Ordinary input EOF has its own safe enum.');
    fclose($emptyInput);
    tbank_check(mahara_tbank_setup_credentials('key', ' fixture ')['password'] === ' fixture ', 'Password bytes are preserved without trimming or paste rewriting.');
    [$status, $output, $error] = tbank_cli(['save-terminal', '--stdin'], "key\n\n");
    tbank_check($status === 1 && $output === '' && json_decode($error, true) === ['error' => 'terminal_setup_failed', 'code' => 'terminal_password_empty', 'checkoutChanged' => false], 'CLI emits only static error fields for an empty completed password.');
    [$status, $output, $error] = tbank_cli(['save-terminal', '--stdin'], "key\n\033[200~fixture\033[201~\n");
    tbank_check($status === 1 && json_decode($error, true)['code'] === 'terminal_password_control_chars' && !str_contains($error, 'fixture'), 'Bracketed paste controls are diagnosed without echoing or changing input.');
    tbank_check(!is_file($base . '/private/.tbank-setup.lock'), 'Invalid human input does not reach the storage lock.');
    $captured = [];
    $transport = static function (string $url, array $payload, array $policy) use ($credentials, &$captured): array {
        $captured[] = $payload;
        tbank_check($url === 'https://securepay.tinkoff.ru/v2/CheckOrder', 'Only the documented read-only endpoint is called.');
        tbank_check(array_keys($payload) === ['TerminalKey', 'OrderId', 'Token'] && !isset($payload['Password']), 'The password and financial fields are never transmitted.');
        tbank_check(preg_match('/^mahara-probe-[a-f0-9]{32}$/D', $payload['OrderId']) === 1 && strlen($payload['OrderId']) <= 50, 'The probe is a fresh non-storefront order id within protocol limits.');
        tbank_check(hash_equals(hash('sha256', $payload['OrderId'] . $credentials['password'] . $credentials['terminal_key']), $payload['Token']), 'The probe uses the documented sorted scalar SHA-256 token.');
        tbank_check($policy['verifyPeer'] === true && $policy['verifyHost'] === 2 && $policy['tlsMinimum'] === '1.2' && !$policy['followRedirects'] && $policy['timeout'] === 10 && $policy['maxBytes'] === 65536, 'TLS verification, fixed redirects and response bounds are required.');
        return tbank_fixture_response($payload);
    };
    $accepted = mahara_tbank_setup_check($credentials, $transport);
    tbank_check($accepted === ['tlsVerified' => true, 'credentialDiagnostics' => 'accepted', 'errorCode' => '0', 'checkoutChanged' => false, 'createdPayments' => 0], 'Exact authenticated empty-order response only proves credential diagnostics.');
    mahara_tbank_setup_check($credentials, $transport);
    tbank_check($captured[0]['OrderId'] !== $captured[1]['OrderId'], 'Every check uses a new random read-only probe.');
    foreach ([['Success' => false, 'ErrorCode' => '335'], ['Success' => false, 'ErrorCode' => '204'], ['TerminalKey' => 'Different'], ['OrderId' => 'Different'], ['Success' => 'true'], ['Payments' => [['PaymentId' => 'unexpected']]], ['ErrorCode' => 0, 'OrderId' => null]] as $changes) {
        $result = mahara_tbank_setup_check($credentials, static fn($url, $payload, $policy) => tbank_fixture_response($payload, $changes));
        tbank_check($result['credentialDiagnostics'] === 'unknown', 'Errors, mismatches and unexpected order data never imply accepted credentials.');
    }
    $controlledRequests = []; $controlledPolicies = [];
    $controlTransport = static function ($url, $payload, $policy) use (&$controlledRequests, &$controlledPolicies): array {
        $controlledRequests[] = $payload; $controlledPolicies[] = $policy;
        tbank_check($url === MAHARA_TBANK_SETUP_URL, 'Signature comparison never changes the read-only endpoint.');
        return tbank_fixture_response($payload, ['Success' => false, 'ErrorCode' => count($controlledRequests) === 1 ? '335' : '204']);
    };
    $controlled = mahara_tbank_setup_check($credentials, $controlTransport, true);
    tbank_check(count($controlledRequests) === 2 && $controlledRequests[0]['OrderId'] === $controlledRequests[1]['OrderId'] && $controlledRequests[0]['TerminalKey'] === $controlledRequests[1]['TerminalKey'], 'Explicit negative control makes exactly one additional request with the same key and probe.');
    tbank_check(preg_match('/^[a-f0-9]{64}$/D', $controlledRequests[1]['Token']) === 1 && $controlledRequests[0]['Token'] !== $controlledRequests[1]['Token'] && substr($controlledRequests[0]['Token'], 1) === substr($controlledRequests[1]['Token'], 1), 'The invalid control token is guaranteed to differ by one hex nibble.');
    tbank_check($controlledPolicies[0] === $controlledPolicies[1] && $controlledPolicies[1]['verifyPeer'] && $controlledPolicies[1]['verifyHost'] === 2, 'Both comparison requests use identical verified TLS policy.');
    tbank_check($controlled['credentialDiagnostics'] === 'accepted_by_signature_control' && $controlled['signatureControl'] === ['attempted' => true, 'normalErrorCode' => '335', 'invalidTokenErrorCode' => '204'], 'Only the documented contrasting errors yield signature-control evidence.');
    foreach ([$credentials['terminal_key'], $credentials['password'], $controlledRequests[0]['OrderId'], $controlledRequests[0]['Token'], $controlledRequests[1]['Token']] as $privateValue) tbank_check(!str_contains(json_encode($controlled), $privateValue), 'Comparison evidence contains no raw credential, token or probe.');
    $defaultCalls = 0;
    $default335 = mahara_tbank_setup_check($credentials, static function ($url, $payload) use (&$defaultCalls): array { $defaultCalls++; return tbank_fixture_response($payload, ['Success' => false, 'ErrorCode' => '335']); });
    tbank_check($defaultCalls === 1 && $default335['credentialDiagnostics'] === 'unknown' && !isset($default335['signatureControl']), 'Default 335 remains inconclusive and never sends a bad token.');
    $successCalls = 0;
    $normalSuccess = mahara_tbank_setup_check($credentials, static function ($url, $payload) use (&$successCalls): array { $successCalls++; return tbank_fixture_response($payload); }, true);
    tbank_check($successCalls === 1 && $normalSuccess['credentialDiagnostics'] === 'accepted' && !$normalSuccess['signatureControl']['attempted'], 'A strict normal success needs no negative request.');
    foreach ([['Success' => false, 'ErrorCode' => '204'], ['Success' => 'false', 'ErrorCode' => '335'], ['Success' => false, 'ErrorCode' => '335', 'TerminalKey' => 'Different'], ['Success' => false, 'ErrorCode' => '335', 'OrderId' => 'Different'], ['Success' => false, 'ErrorCode' => '335', 'Payments' => [['unexpected' => true]]]] as $changes) {
        $attempts = 0;
        $uncontrolled = mahara_tbank_setup_check($credentials, static function ($url, $payload) use (&$attempts, $changes): array { $attempts++; return tbank_fixture_response($payload, $changes); }, true);
        tbank_check($attempts === 1 && $uncontrolled['credentialDiagnostics'] === 'unknown' && !$uncontrolled['signatureControl']['attempted'], 'Only strict matching absent-order errors permit a control request.');
    }
    foreach ([['Success' => false, 'ErrorCode' => '335'], ['Success' => true, 'ErrorCode' => '204'], ['Success' => 'false', 'ErrorCode' => '204'], ['Success' => false, 'ErrorCode' => '204', 'TerminalKey' => 'Different'], ['Success' => false, 'ErrorCode' => '204', 'OrderId' => 'Different'], ['Success' => false, 'ErrorCode' => '204', 'Payments' => [['unexpected' => true]]]] as $changes) {
        $attempts = 0;
        $inconclusive = mahara_tbank_setup_check($credentials, static function ($url, $payload) use (&$attempts, $changes): array { $attempts++; return tbank_fixture_response($payload, $attempts === 1 ? ['Success' => false, 'ErrorCode' => '335'] : $changes); }, true);
        tbank_check($attempts === 2 && $inconclusive['credentialDiagnostics'] === 'unknown', 'Same errors, bad echoes or malformed control status never prove credentials.');
    }
    foreach ([['httpStatus' => 302, 'tlsVerified' => true, 'body' => '{}'], ['httpStatus' => 200, 'tlsVerified' => false, 'body' => '{}'], ['httpStatus' => 200, 'tlsVerified' => true, 'body' => 'not-json'], ['httpStatus' => 200, 'tlsVerified' => true, 'body' => str_repeat('x', 65537)]] as $response) {
        $attempts = 0;
        $failedControl = mahara_tbank_setup_check($credentials, static function ($url, $payload) use (&$attempts, $response): array { $attempts++; return $attempts === 1 ? tbank_fixture_response($payload, ['Success' => false, 'ErrorCode' => '335']) : $response; }, true);
        tbank_check($attempts === 2 && $failedControl['credentialDiagnostics'] === 'unknown' && !$failedControl['tlsVerified'] && isset($failedControl['signatureControl']['failureCode']), 'Unverified, redirected or malformed controls fail closed without a third request.');
    }
    $attempts = 0;
    $failedControl = mahara_tbank_setup_check($credentials, static function ($url, $payload) use (&$attempts, $credentials): array { $attempts++; if ($attempts === 2) throw new RuntimeException($credentials['password']); return tbank_fixture_response($payload, ['Success' => false, 'ErrorCode' => '335']); }, true);
    tbank_check($attempts === 2 && $failedControl['credentialDiagnostics'] === 'unknown' && $failedControl['signatureControl']['failureCode'] === 'unexpected_failure' && !str_contains(json_encode($failedControl), $credentials['password']), 'Unexpected control errors stay unknown and never expose raw messages.');
    $attempts = 0;
    $minimalErrors = mahara_tbank_setup_check($credentials, static function () use (&$attempts): array { $attempts++; return ['httpStatus' => 200, 'tlsVerified' => true, 'body' => json_encode(['Success' => false, 'ErrorCode' => $attempts === 1 ? 335 : 204])]; }, true);
    tbank_check($attempts === 2 && $minimalErrors['credentialDiagnostics'] === 'accepted_by_signature_control', 'Error responses may omit echoes; present echoes are checked strictly.');
    foreach ([['httpStatus' => 302, 'tlsVerified' => true, 'body' => '{}'], ['httpStatus' => 200, 'tlsVerified' => false, 'body' => '{}'], ['httpStatus' => 200, 'tlsVerified' => true, 'body' => str_repeat('x', 65537)], ['httpStatus' => 200, 'tlsVerified' => true, 'body' => 'not-json']] as $response) {
        tbank_reject(static fn() => mahara_tbank_setup_check($credentials, static fn() => $response), 'Unverified, redirected, oversized or malformed responses fail closed.');
    }
    $input = $credentials['terminal_key'] . "\n" . $credentials['password'] . "\n";
    [$status, $output, $error] = tbank_cli(['save-terminal', '--stdin'], $input);
    tbank_check($status === 0 && json_decode($output, true)['saved'] === true && $error === '', 'Explicit private stdin saves a new terminal.');
    $path = $base . '/private/tbank-secrets.php';
    tbank_check(is_file($path) && mahara_tbank_setup_saved() === $credentials, 'Saved credentials are available only through the explicit saved path.');
    $savedHash = hash_file('sha256', $path);
    [$status, $output, $error] = tbank_cli(['save-terminal', '--stdin'], "other-terminal\nother-fixture-secret\n");
    tbank_check($status === 1 && hash_file('sha256', $path) === $savedHash, 'Default save never overwrites an existing secret.');
    tbank_check(json_decode($error, true)['code'] === 'storage_exists_or_unsafe', 'Existing-file errors have a static storage enum.');
    tbank_check(!str_contains($output . $error, 'other-fixture-secret') && !str_contains($output . $error, 'other-terminal'), 'Save errors contain neither secret nor terminal key.');
    [$status, $output] = tbank_cli(['check-terminal', '--saved'], '', $transport);
    tbank_check($status === 0 && json_decode($output, true)['credentialDiagnostics'] === 'accepted', 'Explicit --saved diagnoses newly saved credentials without an extra password prompt.');
    $controlledRequests = []; $controlledPolicies = [];
    [$status, $output, $error] = tbank_cli(['check-terminal', '--saved', '--negative-control'], '', $controlTransport);
    tbank_check($status === 0 && $error === '' && count($controlledRequests) === 2 && json_decode($output, true)['credentialDiagnostics'] === 'accepted_by_signature_control' && hash_file('sha256', $path) === $savedHash, 'Explicit CLI flag runs one signature comparison without changing saved credentials.');
    // Default stdin check cannot execute/read the saved PHP credential file.
    file_put_contents($path, '<?php throw new RuntimeException("saved-file-read-forbidden");');
    [$status, $output] = tbank_cli(['check-terminal', '--stdin'], $input, $transport);
    tbank_check($status === 0, 'Fresh input check never loads the existing credential file.');
    [$status, $output, $error] = tbank_cli(['check-terminal', '--saved'], '', $transport);
    tbank_check($status === 1 && !str_contains($error . $output, 'saved-file-read-forbidden'), 'Saved-file failures are sanitized.');
    tbank_check(json_decode($error, true)['code'] === 'saved_storage_invalid', 'Saved-file errors never expose their raw exception.');
    [$status, $output, $error] = tbank_cli(['check-terminal', '--stdin'], $input, static fn() => throw new RuntimeException('fixture-not-a-real-bank-secret'));
    tbank_check($status === 1 && !str_contains($error . $output, $credentials['password']), 'Transport exceptions cannot leak credentials.');
    tbank_check(json_decode($error, true)['code'] === 'unexpected_failure', 'Unknown exceptions map only to the generic enum.');
    [$status, $output] = tbank_cli(['check-terminal', '--stdin'], $input, static fn($url, $payload) => tbank_fixture_response($payload, ['Success' => false, 'ErrorCode' => '335', 'Message' => $credentials['password'], 'Details' => $credentials['terminal_key']]));
    tbank_check($status === 3 && json_decode($output, true)['credentialDiagnostics'] === 'unknown' && !str_contains($output, $credentials['password']) && !str_contains($output, $credentials['terminal_key']), 'Unknown-order errors remain inconclusive with sanitized numeric code only.');
    foreach ([['check-terminal', '--stdin', '--saved'], ['save-terminal', '--saved'], ['save-terminal', '--negative-control'], ['check-terminal', '--password', $credentials['password']], ['check-terminal', '--stdin', '--stdin'], ['check-terminal', '--negative-control', '--negative-control']] as $args) {
        [$status, $output, $error] = tbank_cli($args, $input, $transport);
        tbank_check($status === 2 && !str_contains($output . $error, $credentials['password']), 'Conflicting, repeated or secret-bearing arguments are rejected without echo.');
    }
    foreach ([['', 'fixture'], [str_repeat('x', 65), 'fixture'], ['key', "bad\nsecret"], ['key', '']] as [$key, $password]) tbank_reject(static fn() => mahara_tbank_setup_credentials($key, $password), 'Malformed credential input is rejected locally.');
    $GLOBALS['tbank_setup_config']['data_dir'] = $base . '/public';
    tbank_reject(static fn() => mahara_tbank_setup_save($credentials), 'Terminal secrets cannot be published under the web root.');
    $GLOBALS['tbank_setup_config']['data_dir'] = $base . '/private';
    if (PHP_OS_FAMILY !== 'Windows') tbank_check((fileperms($path) & 0777) === 0600, 'Credential file permissions remain private.');
    tbank_check(glob($base . '/private/.tbank-secrets-*.tmp') === [], 'No temporary credentials remain after success or failure.');
    tbank_check(!is_file($base . '/private/mahara.sqlite'), 'Terminal setup never opens a storefront database.');
    echo 'PASS: ', $checks, " terminal setup checks (mock bank transport only)\n";
} finally { tbank_cleanup($base, $base); }
