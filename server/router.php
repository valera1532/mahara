<?php
declare(strict_types=1);

// Always dispatch, including existing files: the built-in server must never
// expose SQLite files, CLI tools, source code, local credentials or dotfiles.
require __DIR__ . '/app.php';
