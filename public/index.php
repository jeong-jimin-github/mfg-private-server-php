<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Mfg\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use Mfg\App;
use Mfg\Storage\Database;

$dbPath = dirname(__DIR__) . '/data/mfg.sqlite';
$app = new App(new Database($dbPath));
$app->handle();
