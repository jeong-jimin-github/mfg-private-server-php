<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Mfg\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require $path;
});

use Mfg\App;
use Mfg\Storage\Database;

$root = dirname(__DIR__);
$local = [];
$localFile = $root . '/config.local.php';
if (is_file($localFile)) {
    $loaded = require $localFile;
    if (is_array($loaded)) $local = $loaded;
}

$env = static function (string $name, mixed $fallback = null) use ($local): mixed {
    $v = getenv($name);
    if ($v !== false && $v !== '') return $v;
    return $local[$name] ?? $fallback;
};

$dsn = (string)$env('DB_DSN', 'sqlite:' . $root . '/data/mfg.sqlite');
$user = $env('DB_USER');
$pass = $env('DB_PASS');

$app = new App(new Database(
    $dsn,
    is_string($user) && $user !== '' ? $user : null,
    is_string($pass) ? $pass : null
));
$app->handle();
