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
$requestStarted = microtime(true);
$requestLogFile = $root . '/data/requests.log';
register_shutdown_function(static function () use ($requestStarted, $requestLogFile): void {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $function = trim((string)($_GET['f'] ?? ''));
    if ($function === '' && str_starts_with($path, '/aog')) {
        $function = trim(substr($path, strlen('/aog')), '/');
        if ($function === '') $function = 'aog';
    }
    if ($function === '' && $path === '/') $function = 'e-amuse';
    $status = http_response_code();
    if ($status < 100) $status = 200;
    $record = [
        'time' => date('Y-m-d H:i:s T'),
        'status' => $status,
        'method' => $method,
        'path' => $path,
        'function' => $function !== '' ? $function : '-',
        'model' => trim((string)($_GET['model'] ?? '')) ?: '-',
        'duration_ms' => round((microtime(true) - $requestStarted) * 1000, 1),
    ];
    $dir = dirname($requestLogFile);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    clearstatcache(true, $requestLogFile);
    if (is_file($requestLogFile) && (int)@filesize($requestLogFile) >= 2 * 1024 * 1024) {
        @unlink($requestLogFile . '.1');
        @rename($requestLogFile, $requestLogFile . '.1');
    }
    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($json)) @file_put_contents($requestLogFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
});

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
$app = new App(new Database($dsn, is_string($user) && $user !== '' ? $user : null, is_string($pass) ? $pass : null));
$app->handle();
