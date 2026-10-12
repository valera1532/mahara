<?php
declare(strict_types=1);

// Resolve existing ancestors without creating anything. In particular, both
// sides of a containment check must use the same physical-path namespace.
function mahara_backup_resolve_directory(string $directory): string {
    if ($directory === '' || str_contains($directory, "\0") || (!str_starts_with($directory, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $directory))) throw new RuntimeException('Backup directory must be absolute.');
    $pending = []; $ancestor = $directory;
    while (!file_exists($ancestor)) {
        $part = basename($ancestor);
        if ($part === '.' || $part === '..') throw new RuntimeException('Backup directory must be normalized.');
        array_unshift($pending, $part); $parent = dirname($ancestor);
        if ($parent === $ancestor) throw new RuntimeException('Invalid backup directory.');
        $ancestor = $parent;
    }
    $base = realpath($ancestor);
    if (!$base || !is_dir($base)) throw new RuntimeException('Invalid backup directory.');
    foreach ($pending as $part) $base .= DIRECTORY_SEPARATOR . $part;
    return $base;
}

function mahara_private_backup_base(string $directory): string {
    $base = mahara_backup_resolve_directory($directory);
    $normalized = rtrim(str_replace('\\', '/', $base), '/');
    $root = rtrim(str_replace('\\', '/', (string)realpath(mahara_config()['root'])), '/');
    $dataDirectory = mahara_config()['data_dir'];
    if (!str_starts_with($dataDirectory, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $dataDirectory)) {
        $workingDirectory = getcwd();
        if ($workingDirectory === false) throw new RuntimeException('Cannot resolve private data directory.');
        $dataDirectory = $workingDirectory . DIRECTORY_SEPARATOR . $dataDirectory;
    }
    $uploads = rtrim(str_replace('\\', '/', mahara_backup_resolve_directory($dataDirectory . '/uploads')), '/');
    if (PHP_OS_FAMILY === 'Windows') { $normalized = strtolower($normalized); $root = strtolower($root); $uploads = strtolower($uploads); }
    if ($normalized === $root || str_starts_with($normalized, $root . '/') || $normalized === $uploads || str_starts_with($normalized, $uploads . '/')) throw new RuntimeException('Backups must stay outside the public site and uploads.');
    if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) throw new RuntimeException('Cannot create backup directory.');
    return $base;
}

function mahara_backup_create(string $directory): array {
    $base = mahara_private_backup_base($directory);
    $destination = $base . DIRECTORY_SEPARATOR . 'mahara-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
    if (!mkdir($destination, 0700)) throw new RuntimeException('Cannot create backup snapshot.');
    $snapshot = $destination . '/mahara.sqlite';
    // SQLite writes a consistent snapshot including committed WAL pages. Never
    // copy the live database file and never fall back to a WAL-unsafe copy.
    mahara_db()->exec('VACUUM INTO ' . mahara_db()->quote($snapshot));
    @chmod($snapshot, 0600);
    $files = ['mahara.sqlite' => hash_file('sha256', $snapshot)];
    $uploads = mahara_config()['data_dir'] . '/uploads';
    if (is_link($uploads)) throw new RuntimeException('Upload storage must not be a symbolic link.');
    if (is_dir($uploads)) {
        mkdir($destination . '/uploads', 0700);
        foreach (new DirectoryIterator($uploads) as $entry) {
            if ($entry->isDot()) continue;
            $name = $entry->getFilename();
            if (!$entry->isFile() || $entry->isLink() || !preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $name)) throw new RuntimeException('Unexpected upload storage entry.');
            if (!copy($entry->getPathname(), $destination . '/uploads/' . $name)) throw new RuntimeException('Cannot copy upload.');
            @chmod($destination . '/uploads/' . $name, 0600);
            $files['uploads/' . $name] = hash_file('sha256', $destination . '/uploads/' . $name);
        }
    }
    $manifest = ['version' => 1, 'createdAt' => gmdate('c'), 'files' => $files, 'includesSecrets' => false];
    if (file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) throw new RuntimeException('Cannot save backup manifest.');
    @chmod($destination . '/manifest.json', 0600);
    $result = mahara_backup_verify($destination);
    return ['directory' => $destination, 'files' => $result['files'], 'verified' => true];
}

function mahara_backup_verify(string $directory): array {
    // Verification must not create a directory for a mistyped/missing snapshot.
    if (!is_dir($directory)) throw new RuntimeException('Backup directory missing.');
    $base = mahara_private_backup_base($directory);
    $manifestFile = mahara_safe_file($base, 'manifest.json');
    if (!$manifestFile) throw new RuntimeException('Backup manifest missing.');
    $manifest = json_decode((string)file_get_contents($manifestFile), true, 64, JSON_THROW_ON_ERROR);
    if (($manifest['version'] ?? null) !== 1 || !is_array($manifest['files'] ?? null) || !isset($manifest['files']['mahara.sqlite'])) throw new RuntimeException('Invalid backup manifest.');
    foreach ($manifest['files'] as $relative => $hash) {
        if (!is_string($relative) || !preg_match('~^(?:mahara\.sqlite|uploads/[a-f0-9]{32}\.(?:jpg|png|webp))$~D', $relative) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('Invalid backup file entry.');
        $file = mahara_safe_file($base, $relative);
        if (!$file || !hash_equals($hash, hash_file('sha256', $file))) throw new RuntimeException('Backup file checksum mismatch.');
    }
    $db = new PDO('sqlite:' . $base . '/mahara.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA query_only=ON');
    if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Backup database integrity check failed.');
    return ['files' => count($manifest['files']), 'verified' => true];
}
