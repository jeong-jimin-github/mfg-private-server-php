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
$requestBody = file_get_contents('php://input');
if (!is_string($requestBody)) $requestBody = '';

$requestAuthorization = trim((string)(
    $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? $_SERVER['Authorization']
    ?? ''
));
if ($requestAuthorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $requestAuthorization = trim((string)$value);
                break;
            }
        }
    }
}

$basicAuthUser = isset($_SERVER['PHP_AUTH_USER']) ? (string)$_SERVER['PHP_AUTH_USER'] : '';
$basicAuthPassword = isset($_SERVER['PHP_AUTH_PW']) ? (string)$_SERVER['PHP_AUTH_PW'] : '';
if (($basicAuthUser === '' && $basicAuthPassword === '')
    && preg_match('/^Basic\s+(.+)$/i', $requestAuthorization, $match) === 1) {
    $decoded = base64_decode(trim((string)$match[1]), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        [$basicAuthUser, $basicAuthPassword] = explode(':', $decoded, 2);
    }
}

$extractPasswords = static function (array $values, string $prefix = '') use (&$extractPasswords): array {
    $found = [];
    foreach ($values as $key => $value) {
        $name = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
        if (is_array($value)) {
            $found += $extractPasswords($value, $name);
            continue;
        }
        if (preg_match('/(?:^|[_-])(pass(?:word|wd)?|pin)(?:$|[_-])/i', (string)$key) === 1
            || preg_match('/^(pass(?:word|wd)?|pin)$/i', (string)$key) === 1) {
            $found[$name] = is_scalar($value) || $value === null
                ? (string)$value
                : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    return $found;
};

register_shutdown_function(static function () use ($requestStarted, $requestLogFile, $requestBody, $extractPasswords, $requestAuthorization, $basicAuthUser, $basicAuthPassword): void {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $function = trim((string)($_GET['f'] ?? ''));
    if ($function === '' && str_starts_with($path, '/aog')) {
        $function = trim(substr($path, strlen('/aog')), '/');
        if ($function === '') $function = 'aog';
    }
    if ($function === '' && $path === '/') $function = 'e-amuse';

    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $bodyEncoding = preg_match('//u', $requestBody) === 1 ? 'utf-8' : 'base64';
    $storedBody = $bodyEncoding === 'utf-8' ? $requestBody : base64_encode($requestBody);

    $passwords = $extractPasswords($_GET);
    $passwords += $extractPasswords($_POST, 'post');

    $parsedBody = [];
    if (str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
        parse_str($requestBody, $parsedBody);
        if (is_array($parsedBody)) $passwords += $extractPasswords($parsedBody, 'body');
    }

    if ($requestBody !== '' && preg_match_all('/(?:pass(?:word|wd)?|pin)\s*=\s*["\']([^"\']*)["\']/i', $requestBody, $matches) > 0) {
        foreach ($matches[1] as $index => $value) {
            $passwords['xml_attr_' . ($index + 1)] = (string)$value;
        }
    }
    if ($requestBody !== '' && preg_match_all('/<(pass(?:word|wd)?|pin)(?:\s[^>]*)?>([^<]*)<\/\1>/i', $requestBody, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $index => $match) {
            $passwords['xml_' . strtolower((string)$match[1]) . '_' . ($index + 1)] = (string)$match[2];
        }
    }
    if ($basicAuthPassword !== '') {
        $passwords['http_basic'] = $basicAuthPassword;
    }

    $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $clientIp = $forwardedFor !== ''
        ? trim(explode(',', $forwardedFor)[0])
        : ($realIp !== '' ? $realIp : $remoteAddr);

    $status = http_response_code();
    if ($status < 100) $status = 200;

    $record = [
        'time' => date('Y-m-d H:i:s T'),
        'status' => $status,
        'method' => $method,
        'path' => $path,
        'query' => (string)($_SERVER['QUERY_STRING'] ?? ''),
        'function' => $function !== '' ? $function : '-',
        'model' => trim((string)($_GET['model'] ?? '')) ?: '-',
        'ip' => $clientIp !== '' ? $clientIp : '-',
        'remote_addr' => $remoteAddr !== '' ? $remoteAddr : '-',
        'x_forwarded_for' => $forwardedFor !== '' ? $forwardedFor : '-',
        'x_real_ip' => $realIp !== '' ? $realIp : '-',
        'content_type' => $contentType !== '' ? $contentType : '-',
        'body_encoding' => $bodyEncoding,
        'body' => $storedBody,
        'passwords' => $passwords,
        'basic_auth_user' => $basicAuthUser !== '' ? $basicAuthUser : '-',
        'basic_auth_password' => $basicAuthPassword !== '' ? $basicAuthPassword : '-',
        'authorization' => $requestAuthorization !== '' ? $requestAuthorization : '-',
        'duration_ms' => round((microtime(true) - $requestStarted) * 1000, 1),
    ];

    $dir = dirname($requestLogFile);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    clearstatcache(true, $requestLogFile);
    if (is_file($requestLogFile) && (int)@filesize($requestLogFile) >= 20 * 1024 * 1024) {
        @unlink($requestLogFile . '.1');
        @rename($requestLogFile, $requestLogFile . '.1');
    }

    $json = json_encode(
        $record,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (is_string($json)) {
        @file_put_contents($requestLogFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
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
