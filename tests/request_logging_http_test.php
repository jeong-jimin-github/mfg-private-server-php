<?php

declare(strict_types=1);

function rl_ok(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

function rl_request(string $url, string $body, array $headers): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    rl_ok(is_string($response), 'HTTP request failed: ' . $url);
    return $response;
}

function rl_find_record(string $logFile, string $queryNeedle): ?array
{
    if (!is_readable($logFile)) return null;
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return null;
    foreach (array_reverse($lines) as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && str_contains((string)($row['query'] ?? ''), $queryNeedle)) return $row;
    }
    return null;
}

function rl_wait_record(string $logFile, string $queryNeedle): array
{
    for ($i = 0; $i < 40; $i++) {
        $row = rl_find_record($logFile, $queryNeedle);
        if (is_array($row)) return $row;
        usleep(50_000);
    }
    throw new RuntimeException('request log record not found: ' . $queryNeedle);
}

$root = dirname(__DIR__);
$logFile = $root . '/data/requests.log';
$serverLog = sys_get_temp_dir() . '/mfg-request-logging-server.log';
$dbFile = sys_get_temp_dir() . '/mfg-request-logging.sqlite';
@unlink($logFile);
@unlink($serverLog);
@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');

$oldDsn = getenv('DB_DSN');
putenv('DB_DSN=sqlite:' . $dbFile);

$address = '127.0.0.1:18081';
$baseUrl = 'http://' . $address;
$command = escapeshellarg(PHP_BINARY)
    . ' -S ' . escapeshellarg($address)
    . ' -t ' . escapeshellarg($root . '/public')
    . ' ' . escapeshellarg($root . '/public/index.php');
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['file', $serverLog, 'a'],
    2 => ['file', $serverLog, 'a'],
], $pipes, $root);
rl_ok(is_resource($process), 'failed to start PHP test server');
if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);

try {
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $health = @file_get_contents($baseUrl . '/healthz');
        if (is_string($health) && str_contains($health, '"ok":true')) {
            $ready = true;
            break;
        }
        usleep(100_000);
    }
    rl_ok($ready, 'PHP test server did not become ready');

    $basic = base64_encode('deployprobe:authpass456');
    rl_request(
        $baseUrl . '/?probe=requestlog',
        'test=hello&password=testpass123&pin=1234',
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: */*',
            'X-Forwarded-For: 198.51.100.23',
            'X-Real-IP: 198.51.100.24',
            'Authorization: Basic ' . $basic,
        ]
    );

    $row = rl_wait_record($logFile, 'probe=requestlog');
    rl_ok(($row['status'] ?? null) === 200, 'request status was not logged as 200');
    rl_ok(($row['body'] ?? null) === 'test=hello&password=testpass123&pin=1234', 'full request body missing');
    rl_ok(($row['query'] ?? null) === 'probe=requestlog', 'query string missing');
    rl_ok(($row['ip'] ?? null) === '198.51.100.23', 'client IP selection missing X-Forwarded-For');
    rl_ok(($row['x_forwarded_for'] ?? null) === '198.51.100.23', 'X-Forwarded-For missing');
    rl_ok(($row['x_real_ip'] ?? null) === '198.51.100.24', 'X-Real-IP missing');
    rl_ok(($row['passwords']['post.password'] ?? null) === 'testpass123', 'POST password missing');
    rl_ok(($row['passwords']['post.pin'] ?? null) === '1234', 'POST pin missing');
    rl_ok(($row['passwords']['http_basic'] ?? null) === 'authpass456', 'Basic password missing');
    rl_ok(($row['basic_auth_user'] ?? null) === 'deployprobe', 'Basic username missing');
    rl_ok(($row['basic_auth_password'] ?? null) === 'authpass456', 'Basic password field missing');
    rl_ok(($row['authorization'] ?? null) === 'Basic ' . $basic, 'Authorization header missing');

    rl_request(
        $baseUrl . '/?probe=malformedauth',
        'test=malformed',
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: */*',
            'Authorization: Basic !!!not-base64!!!',
        ]
    );
    $bad = rl_wait_record($logFile, 'probe=malformedauth');
    rl_ok(($bad['status'] ?? null) === 200, 'malformed Basic auth caused an HTTP error');
    rl_ok(($bad['authorization'] ?? null) === 'Basic !!!not-base64!!!', 'malformed Authorization header was not preserved');
    rl_ok(($bad['basic_auth_user'] ?? null) === '-', 'malformed Basic auth unexpectedly produced a username');
    rl_ok(($bad['basic_auth_password'] ?? null) === '-', 'malformed Basic auth unexpectedly produced a password');

    echo "request logging HTTP/auth error handling OK\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    if ($oldDsn === false) putenv('DB_DSN');
    else putenv('DB_DSN=' . $oldDsn);
    @unlink($logFile);
    @unlink($dbFile);
    @unlink($dbFile . '-wal');
    @unlink($dbFile . '-shm');
}
