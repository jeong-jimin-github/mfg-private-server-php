<?php

declare(strict_types=1);

namespace Mfg\Storage;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA busy_timeout=5000');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS cards (
  card_id TEXT PRIMARY KEY,
  refid TEXT NOT NULL UNIQUE,
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS profiles (
  refid TEXT PRIMARY KEY,
  player_id INTEGER NOT NULL UNIQUE,
  name TEXT NOT NULL,
  payload TEXT NOT NULL DEFAULT '{}',
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS cabinets (
  pcbid TEXT PRIMARY KEY,
  model TEXT NOT NULL DEFAULT '',
  enabled INTEGER NOT NULL DEFAULT 1,
  first_seen INTEGER NOT NULL,
  last_seen INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS sessions (
  session_id TEXT PRIMARY KEY,
  refid TEXT NOT NULL,
  payload TEXT NOT NULL DEFAULT '{}',
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS matches (
  match_id TEXT PRIMARY KEY,
  payload TEXT NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS kv (
  scope TEXT NOT NULL,
  key TEXT NOT NULL,
  value TEXT NOT NULL,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(scope, key)
);
SQL);
    }

    public function ensureProfile(string $refid, string $name = 'GUEST'): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profiles WHERE refid = ?');
        $stmt->execute([$refid]);
        $row = $stmt->fetch();
        if ($row) {
            $row['payload'] = json_decode($row['payload'], true) ?: [];
            return $row;
        }
        $playerId = $this->playerIdFromRefid($refid);
        $now = time();
        $this->pdo->prepare('INSERT INTO profiles(refid,player_id,name,payload,created_at,updated_at) VALUES(?,?,?,?,?,?)')
            ->execute([$refid, $playerId, $name, '{}', $now, $now]);
        return ['refid'=>$refid,'player_id'=>$playerId,'name'=>$name,'payload'=>[],'created_at'=>$now,'updated_at'=>$now];
    }

    public function cardProfile(string $cardId): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT refid FROM cards WHERE card_id = ?');
            $stmt->execute([$cardId]);
            $refid = $stmt->fetchColumn();
            if (!$refid) {
                $refid = strtoupper(bin2hex(random_bytes(8)));
                $this->ensureProfile($refid);
                $this->pdo->prepare('INSERT INTO cards(card_id,refid,created_at) VALUES(?,?,?)')
                    ->execute([$cardId, $refid, time()]);
            }
            $this->pdo->commit();
            return $this->ensureProfile((string)$refid);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function saveProfilePayload(string $refid, array $payload): void
    {
        $this->ensureProfile($refid);
        $this->pdo->prepare('UPDATE profiles SET payload=?, updated_at=? WHERE refid=?')
            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), time(), $refid]);
    }

    public function getKv(string $scope, string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM kv WHERE scope=? AND key=?');
        $stmt->execute([$scope, $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : json_decode((string)$v, true);
    }

    public function setKv(string $scope, string $key, mixed $value): void
    {
        $this->pdo->prepare('INSERT INTO kv(scope,key,value,updated_at) VALUES(?,?,?,?) ON CONFLICT(scope,key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at')
            ->execute([$scope, $key, json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), time()]);
    }

    private function playerIdFromRefid(string $refid): int
    {
        $prefix = substr($refid, 0, 8);
        if (ctype_xdigit($prefix) && $prefix !== '') {
            $value = (int)hexdec($prefix);
        } else {
            $hash = hash('sha256', $refid !== '' ? $refid : 'GUEST', true);
            $u = unpack('N', substr($hash, 0, 4));
            $value = (int)$u[1];
        }
        $value &= 0x7fffffff;
        return $value ?: 1;
    }
}
