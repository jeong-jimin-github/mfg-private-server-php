<?php

declare(strict_types=1);

// Copy to config.local.php for shared hosting that cannot set environment
// variables. config.local.php is ignored by git and must never be committed.
return [
    // SQLite fallback:
    // 'DB_DSN' => 'sqlite:' . __DIR__ . '/data/mfg.sqlite',

    // MySQL / MariaDB example:
    'DB_DSN' => 'mysql:host=127.0.0.1;port=3306;dbname=mfg;charset=utf8mb4',
    'DB_USER' => 'mfg_user',
    'DB_PASS' => 'change-me',
];
