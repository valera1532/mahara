<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MAHARA_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/server/app.php';

function admin_hidden_password(string $prompt): string {
    if (PHP_OS_FAMILY === 'Windows') {
        // Password is read by the console securely, never passed as an argument.
        $script = '$v = Read-Host "Password (12-72 bytes)" -AsSecureString; $p = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($v); try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($p)) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($p) }';
        $process = proc_open(['powershell.exe', '-NoProfile', '-Command', $script], [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Use MAHARA_ADMIN_PASSWORD to provide a password.');
        $password = stream_get_contents($pipes[1]); fclose($pipes[1]);
        if (proc_close($process) !== 0 || $password === false) throw new RuntimeException('Cannot read password. Use MAHARA_ADMIN_PASSWORD.');
        return $password;
    }
    fwrite(STDERR, $prompt);
    $mode = shell_exec('stty -g < /dev/tty');
    if (!$mode) throw new RuntimeException('Use MAHARA_ADMIN_PASSWORD when no interactive terminal is available.');
    shell_exec('stty -echo < /dev/tty');
    try { $password = fgets(STDIN); }
    finally { shell_exec('stty ' . escapeshellarg(trim($mode)) . ' < /dev/tty'); fwrite(STDERR, PHP_EOL); }
    if ($password === false) throw new RuntimeException('Cannot read password.');
    return rtrim($password, "\r\n");
}

try {
    $args = array_slice($argv, 1);
    if (array_shift($args) !== 'create') throw new RuntimeException('Usage: php tools/admin.php create [--replace] [--generate --output /private/path/access.txt]');
    $replace = false; $generate = false; $output = null;
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--replace') $replace = true;
        elseif ($args[$i] === '--generate') $generate = true;
        elseif ($args[$i] === '--output' && isset($args[$i + 1])) $output = $args[++$i];
        else throw new RuntimeException('Unknown argument: ' . $args[$i]);
    }
    if ($generate && !$output) throw new RuntimeException('--generate requires --output. The password is written only to that private file.');
    if (!$generate && $output) throw new RuntimeException('--output requires --generate.');
    $username = getenv('MAHARA_ADMIN_USERNAME') ?: '';
    if ($generate && $username === '') $username = 'owner-' . bin2hex(random_bytes(4));
    if ($username === '') { fwrite(STDERR, 'Admin username: '); $username = trim((string)fgets(STDIN)); }
    $username = strtolower($username);
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/D', $username)) throw new RuntimeException('Username: 3-64 Latin letters, digits, dots, hyphens or underscores.');
    $password = $generate ? mahara_random_token() : (getenv('MAHARA_ADMIN_PASSWORD') ?: admin_hidden_password('Password (12-72 bytes): '));
    if (strlen($password) < 12 || strlen($password) > 72) throw new RuntimeException('Password must contain 12-72 bytes.');
    $existing = mahara_query('SELECT id FROM admins WHERE username=?', [$username])->fetch();
    if ($existing && !$replace) throw new RuntimeException('Administrator exists. Use --replace to change the password and revoke active sessions.');
    // Prepare the credential file first, so --generate cannot create an account
    // whose password is lost after a failed file write. Never overwrite a file.
    $file = null;
    if ($generate) {
        $directory = dirname($output);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create credential directory.');
        $resolvedDirectory = realpath($directory); $root = realpath(mahara_config()['root']);
        if (mahara_config()['production'] && ($resolvedDirectory === $root || str_starts_with($resolvedDirectory, $root . DIRECTORY_SEPARATOR))) throw new RuntimeException('Production credentials must be written outside the public document root.');
        $file = fopen($output, 'xb');
        if (!$file) throw new RuntimeException('Credential file exists or cannot be created.');
        @chmod($output, 0600);
        if (fwrite($file, "Login: $username\nPassword: $password\nAdmin: /admin/login\n") === false) { fclose($file); @unlink($output); throw new RuntimeException('Cannot save credential file.'); }
        fclose($file);
    }
    mahara_db()->beginTransaction();
    try {
        if ($existing) mahara_query('DELETE FROM sessions WHERE admin_id=?', [$existing['id']]);
        mahara_query('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?) ON CONFLICT(username) DO UPDATE SET password_hash=excluded.password_hash', [$username, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), time()]);
        mahara_db()->commit();
    } catch (Throwable $error) { mahara_db()->rollBack(); if ($generate) @unlink($output); throw $error; }
    fwrite(STDOUT, 'Administrator ' . $username . ' is ready.' . ($generate ? ' Credentials saved to ' . $output . '.' : '') . PHP_EOL);
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
