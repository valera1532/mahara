<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/backup.php';
try {
    if (!in_array($argv[1] ?? '', ['create', 'verify'], true) || !isset($argv[2]) || count($argv) !== 3) { fwrite(STDERR, "Usage: php tools/backup.php create|verify /absolute/private/backup-directory\n"); exit(2); }
    $result = $argv[1] === 'create' ? mahara_backup_create($argv[2]) : mahara_backup_verify($argv[2]);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) { fwrite(STDERR, "Backup failed. Check private paths, permissions, available space and SQLite support for VACUUM INTO. No live database file copy was used.\n"); exit(1); }
