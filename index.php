<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
if (!($method === 'GET' && $path === '/' && $_GET === [] && str_contains($accept, 'text/html'))) {
    require __DIR__ . '/public/index.php';
    return;
}

$root = __DIR__;
$local = [];
if (is_file($root . '/config.local.php')) {
    $loaded = require $root . '/config.local.php';
    if (is_array($loaded)) $local = $loaded;
}
$env = static function (string $name, mixed $fallback = null) use ($local): mixed {
    $v = getenv($name);
    return ($v !== false && $v !== '') ? $v : ($local[$name] ?? $fallback);
};

$dbOnline = false;
$dbDriver = 'unknown';
$dbVersion = '-';
$dbError = '';
try {
    $dsn = (string)$env('DB_DSN', 'sqlite:' . $root . '/data/mfg.sqlite');
    $user = $env('DB_USER');
    $pass = $env('DB_PASS');
    $pdo = new PDO(
        $dsn,
        is_string($user) && $user !== '' ? $user : null,
        is_string($pass) ? $pass : null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->query('SELECT 1')->fetchColumn();
    $dbDriver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        $dbVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    } catch (Throwable) {}
    $dbOnline = true;
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $p = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    if (in_array($p, ['http', 'https'], true)) $scheme = $p;
}
$base = $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

$logs = [];
$logFile = $root . '/data/requests.log';
if (is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach (array_reverse(array_slice($lines, -120)) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) $logs[] = $row;
        }
    }
}

$errors = 0;
foreach ($logs as $row) {
    if ((int)($row['status'] ?? 0) >= 400) $errors++;
}
$success = count($logs) ? round((count($logs) - $errors) * 100 / count($logs), 1) : 100.0;
$esc = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formatPasswords = static function (array $row): string {
    $values = is_array($row['passwords'] ?? null) ? $row['passwords'] : [];
    if (($row['basic_auth_password'] ?? '-') !== '-' && !isset($values['http_basic'])) {
        $values['http_basic'] = (string)$row['basic_auth_password'];
    }
    $parts = [];
    foreach ($values as $key => $value) {
        $parts[] = (string)$key . '=' . (string)$value;
    }
    if (($row['authorization'] ?? '-') !== '-') {
        $parts[] = 'Authorization=' . (string)$row['authorization'];
    }
    return $parts === [] ? '-' : implode("\n", $parts);
};

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="15">
<title>MFG Private Server</title>
<style>
:root{color-scheme:dark;--b:#090d14;--p:#111824;--l:#253249;--t:#e8eef8;--m:#8fa0b8;--g:#5ee6a8;--r:#ff7c8a}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 15% 0,#16233a,transparent 34rem),var(--b);color:var(--t);font:14px/1.5 system-ui,"Segoe UI",sans-serif}
main{width:min(1280px,calc(100% - 32px));margin:36px auto 60px}.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:20px}h1{margin:0;font-size:28px}h2{font-size:16px;margin:0 0 12px}.muted{color:var(--m)}
.badge{border:1px solid #285942;background:#102b23;color:var(--g);padding:8px 12px;border-radius:99px;font-weight:700}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:12px}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.card{background:var(--p);border:1px solid var(--l);border-radius:15px;padding:16px}.label{font-size:12px;color:var(--m);text-transform:uppercase;letter-spacing:.08em}.value{font-size:22px;font-weight:750;margin-top:5px}.ok{color:var(--g)}.bad{color:var(--r)}
dl{display:grid;grid-template-columns:135px 1fr;gap:8px 12px}dt{color:var(--m)}dd{margin:0;word-break:break-all}code{color:#cfe1ff}.ep{margin-top:8px;padding:8px 10px;border:1px solid var(--l);border-radius:9px;overflow:auto}
.logs{padding:0;overflow:hidden}.logs h2{padding:16px 16px 0}.wrap{overflow:auto;max-height:650px}table{width:100%;border-collapse:collapse;min-width:1550px}th,td{padding:9px 12px;border-top:1px solid var(--l);text-align:left;white-space:nowrap;vertical-align:top}th{position:sticky;top:0;background:#151e2c;color:var(--m);z-index:1}.empty{padding:24px 16px;color:var(--m)}
.long{max-width:420px;white-space:pre-wrap;word-break:break-all;font-family:ui-monospace,SFMono-Regular,Consolas,monospace}.pw{max-width:300px;white-space:pre-wrap;word-break:break-all}.ip{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
@media(max-width:850px){.grid{grid-template-columns:1fr 1fr}.two{grid-template-columns:1fr}.top{flex-direction:column}}@media(max-width:520px){.grid{grid-template-columns:1fr}main{width:calc(100% - 20px)}}
</style>
</head>
<body>
<main>
<div class="top">
  <div><h1>Mahjong Fight Girl Private Server</h1><div class="muted">PHP server dashboard · 15초 자동 새로고침</div></div>
  <div class="badge">● SERVER ONLINE</div>
</div>

<section class="grid">
  <div class="card"><div class="label">Database</div><div class="value <?= $dbOnline ? 'ok' : 'bad' ?>"><?= $dbOnline ? 'ONLINE' : 'OFFLINE' ?></div></div>
  <div class="card"><div class="label">Recent requests</div><div class="value"><?= count($logs) ?></div></div>
  <div class="card"><div class="label">Success rate</div><div class="value"><?= $esc(number_format($success, 1)) ?>%</div></div>
  <div class="card"><div class="label">PHP</div><div class="value"><?= $esc(PHP_VERSION) ?></div></div>
</section>

<section class="two">
  <div class="card">
    <h2>Server information</h2>
    <dl>
      <dt>Service</dt><dd>mfg-private-server-php</dd>
      <dt>Base URL</dt><dd><code><?= $esc($base) ?></code></dd>
      <dt>Server time</dt><dd><?= $esc(date('Y-m-d H:i:s T')) ?></dd>
      <dt>Last request</dt><dd><?= $esc($logs[0]['time'] ?? '-') ?></dd>
    </dl>
    <div class="ep"><code><?= $esc($base) ?></code> · e-Amusement / XRPC</div>
    <div class="ep"><code><?= $esc($base . '/aog') ?></code> · AOG API</div>
    <div class="ep"><code><?= $esc($base . '/healthz') ?></code> · Health JSON</div>
  </div>
  <div class="card">
    <h2>Database</h2>
    <dl>
      <dt>Status</dt><dd class="<?= $dbOnline ? 'ok' : 'bad' ?>"><?= $dbOnline ? 'Connected' : 'Connection failed' ?></dd>
      <dt>Driver</dt><dd><?= $esc($dbDriver) ?></dd>
      <dt>Version</dt><dd><?= $esc($dbVersion) ?></dd>
      <dt>Credentials</dt><dd>Hidden</dd>
    </dl>
    <?php if (!$dbOnline): ?><div class="ep bad"><?= $esc($dbError) ?></div><?php endif; ?>
  </div>
</section>

<section class="card logs">
  <h2>Recent request log <span class="muted">(최대 120건 · 본문/비밀번호/IP 포함)</span></h2>
  <?php if (!$logs): ?>
    <div class="empty">아직 기록된 요청이 없습니다.</div>
  <?php else: ?>
    <div class="wrap"><table>
      <thead><tr><th>Time</th><th>Status</th><th>Method</th><th>IP</th><th>Path / Query</th><th>Function</th><th>Model</th><th>Password / Auth</th><th>Body</th><th>Duration</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $r): ?>
        <tr>
          <td><?= $esc($r['time'] ?? '-') ?></td>
          <td class="<?= (int)($r['status'] ?? 0) >= 400 ? 'bad' : 'ok' ?>"><?= $esc($r['status'] ?? '-') ?></td>
          <td><?= $esc($r['method'] ?? '-') ?></td>
          <td class="ip" title="remote_addr=<?= $esc($r['remote_addr'] ?? '-') ?>&#10;x_forwarded_for=<?= $esc($r['x_forwarded_for'] ?? '-') ?>&#10;x_real_ip=<?= $esc($r['x_real_ip'] ?? '-') ?>"><?= $esc($r['ip'] ?? '-') ?></td>
          <td><code><?= $esc($r['path'] ?? '-') ?><?= ($r['query'] ?? '') !== '' ? '?' . $esc($r['query']) : '' ?></code></td>
          <td><code><?= $esc($r['function'] ?? '-') ?></code></td>
          <td><?= $esc($r['model'] ?? '-') ?></td>
          <td class="pw"><?= nl2br($esc($formatPasswords($r))) ?></td>
          <td class="long"><?= $esc(($r['body_encoding'] ?? 'utf-8') === 'base64' ? '[base64] ' . ($r['body'] ?? '') : ($r['body'] ?? '')) ?></td>
          <td><?= $esc($r['duration_ms'] ?? '-') ?> ms</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
</main>
</body>
</html>
