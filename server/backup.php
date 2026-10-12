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

function mahara_backup_database_online(string $snapshot): void {
    if (!class_exists('SQLite3') || !method_exists('SQLite3', 'backup')) throw new RuntimeException('SQLite below 3.27 requires the SQLite3 extension with native online backup support. No live database file copy was used.');
    $base = mahara_private_backup_base(dirname($snapshot));
    if (basename($snapshot) !== 'mahara.sqlite') throw new RuntimeException('Invalid backup database filename.');
    $snapshot = $base . DIRECTORY_SEPARATOR . 'mahara.sqlite';
    $sourceFile = null;
    foreach (mahara_db()->query('PRAGMA database_list') as $database) {
        if ($database['name'] === 'main') $sourceFile = realpath($database['file']);
    }
    $configuredFile = realpath(mahara_config()['data_dir'] . '/mahara.sqlite');
    if (!$sourceFile || !$configuredFile || (PHP_OS_FAMILY === 'Windows' ? strcasecmp($sourceFile, $configuredFile) !== 0 : $sourceFile !== $configuredFile)) throw new RuntimeException('Cannot identify the live private database for online backup.');
    // Reserve a new file exclusively: the native API must never overwrite an
    // existing snapshot, follow a destination symlink or open the live DB.
    $file = @fopen($snapshot, 'x+b');
    if ($file === false) throw new RuntimeException('Online backup requires a new private destination file.');
    fclose($file); @chmod($snapshot, 0600);
    $source = null; $destination = null; $complete = false;
    try {
        $source = new SQLite3($sourceFile, SQLITE3_OPEN_READONLY);
        $source->enableExceptions(true);
        $destination = new SQLite3($snapshot, SQLITE3_OPEN_READWRITE);
        $destination->enableExceptions(true);
        if (!$source->busyTimeout(5000) || !$destination->busyTimeout(5000)) throw new RuntimeException('Cannot configure the online backup timeout.');
        if (!$source->backup($destination, 'main', 'main')) throw new RuntimeException('SQLite3 online backup did not complete.');
        $complete = true;
    } finally {
        if ($destination !== null) $destination->close();
        if ($source !== null) $source->close();
        if (!$complete) @unlink($snapshot);
    }
}

function mahara_backup_create(string $directory): array {
    $base = mahara_private_backup_base($directory);
    $destination = $base . DIRECTORY_SEPARATOR . 'mahara-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
    if (!mkdir($destination, 0700)) throw new RuntimeException('Cannot create backup snapshot.');
    $snapshot = $destination . '/mahara.sqlite';
    // SQLite writes a consistent snapshot including committed WAL pages. Never
    // copy the live database file and never fall back to a WAL-unsafe copy.
    $version = (string)mahara_db()->query('SELECT sqlite_version()')->fetchColumn();
    if (version_compare($version, '3.27.0', '>=')) mahara_db()->exec('VACUUM INTO ' . mahara_db()->quote($snapshot));
    else mahara_backup_database_online($snapshot);
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
