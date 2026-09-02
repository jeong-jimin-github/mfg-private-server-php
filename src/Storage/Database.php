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
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA busy_timeout=5000');
        $this->migrate();
    }

    public function pdo(): PDO { return $this->pdo; }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS cards (
  card_id TEXT PRIMARY KEY,
  refid TEXT NOT NULL UNIQUE,
  issued INTEGER NOT NULL DEFAULT 0,
  bound INTEGER NOT NULL DEFAULT 0,
  pin TEXT NOT NULL DEFAULT '0000',
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
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
        $cols = array_column($this->pdo->query('PRAGMA table_info(cards)')->fetchAll(), 'name');
        foreach ([
            'issued' => 'INTEGER NOT NULL DEFAULT 0',
            'bound' => 'INTEGER NOT NULL DEFAULT 0',
            'pin' => "TEXT NOT NULL DEFAULT '0000'",
            'updated_at' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $name => $decl) {
            if (!in_array($name, $cols, true)) $this->pdo->exec("ALTER TABLE cards ADD COLUMN $name $decl");
        }
    }

    public function ensureProfile(string $refid, string $name = 'GUEST'): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profiles WHERE refid=?');
        $stmt->execute([$refid]);
        if ($row = $stmt->fetch()) {
            $row['payload'] = json_decode($row['payload'], true) ?: [];
            return $row;
        }
        $playerId = $this->playerIdFromRefid($refid);
        $now = time();
        $this->pdo->prepare('INSERT INTO profiles(refid,player_id,name,payload,created_at,updated_at) VALUES(?,?,?,?,?,?)')
            ->execute([$refid,$playerId,$name,'{}',$now,$now]);
        return ['refid'=>$refid,'player_id'=>$playerId,'name'=>$name,'payload'=>[],'created_at'=>$now,'updated_at'=>$now];
    }

    public function getProfile(string $refid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profiles WHERE refid=?');
        $stmt->execute([$refid]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['payload'] = json_decode($row['payload'], true) ?: [];
        return $row;
    }

    public function getProfileByPlayerId(int $mid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profiles WHERE player_id=?');
        $stmt->execute([$mid]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['payload'] = json_decode($row['payload'], true) ?: [];
        return $row;
    }

    public function saveProfilePayload(string $refid, array $payload): void
    {
        $this->ensureProfile($refid);
        $this->pdo->prepare('UPDATE profiles SET payload=?,updated_at=? WHERE refid=?')
            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),time(),$refid]);
    }

    public function getCard(string $cardId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cards WHERE card_id=?');
        $stmt->execute([$cardId]);
        return $stmt->fetch() ?: null;
    }

    public function issueCard(string $cardId, string $pin = '0000'): array
    {
        $now = time();
        $rec = $this->getCard($cardId);
        if (!$rec) {
            $refid = 'A' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 15));
            $this->ensureProfile($refid);
            $this->pdo->prepare('INSERT INTO cards(card_id,refid,issued,bound,pin,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$cardId,$refid,1,0,$pin,$now,$now]);
        } else {
            $this->pdo->prepare('UPDATE cards SET issued=1,pin=?,updated_at=? WHERE card_id=?')->execute([$pin,$now,$cardId]);
        }
        return $this->getCard($cardId) ?? throw new \RuntimeException('card issue failed');
    }

    public function bindCardByRefid(string $refid): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE cards SET issued=1,bound=1,updated_at=? WHERE refid=?');
        $stmt->execute([time(),$refid]);
        $stmt = $this->pdo->prepare('SELECT * FROM cards WHERE refid=?');
        $stmt->execute([$refid]);
        return $stmt->fetch() ?: null;
    }

    public function touchCabinet(string $pcbid, string $model = ''): void
    {
        $now = time();
        $this->pdo->prepare('INSERT INTO cabinets(pcbid,model,enabled,first_seen,last_seen) VALUES(?,?,1,?,?) ON CONFLICT(pcbid) DO UPDATE SET model=excluded.model,last_seen=excluded.last_seen')
            ->execute([$pcbid,$model,$now,$now]);
    }

    public function saveSession(string $sessionId, string $refid, array $payload = []): void
    {
        $this->pdo->prepare('INSERT INTO sessions(session_id,refid,payload,updated_at) VALUES(?,?,?,?) ON CONFLICT(session_id) DO UPDATE SET refid=excluded.refid,payload=excluded.payload,updated_at=excluded.updated_at')
            ->execute([$sessionId,$refid,json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),time()]);
    }

    public function getSession(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE session_id=?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['payload'] = json_decode($row['payload'], true) ?: [];
        return $row;
    }

    public function deleteSession(string $sessionId): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE session_id=?')->execute([$sessionId]);
    }

    public function saveMatch(string $matchId, array $payload): void
    {
        $this->pdo->prepare('INSERT INTO matches(match_id,payload,updated_at) VALUES(?,?,?) ON CONFLICT(match_id) DO UPDATE SET payload=excluded.payload,updated_at=excluded.updated_at')
            ->execute([$matchId,json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),time()]);
    }

    public function getMatch(string $matchId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM matches WHERE match_id=?');
        $stmt->execute([$matchId]);
        $v = $stmt->fetchColumn();
        if ($v === false) return null;
        $row = json_decode((string)$v, true);
        return is_array($row) ? $row : null;
    }

    public function deleteMatch(string $matchId): void
    {
        $this->pdo->prepare('DELETE FROM matches WHERE match_id=?')->execute([$matchId]);
    }

    public function getKv(string $scope, string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM kv WHERE scope=? AND key=?');
        $stmt->execute([$scope,$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : json_decode((string)$v, true);
    }

    public function setKv(string $scope, string $key, mixed $value): void
    {
        $this->pdo->prepare('INSERT INTO kv(scope,key,value,updated_at) VALUES(?,?,?,?) ON CONFLICT(scope,key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at')
            ->execute([$scope,$key,json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),time()]);
    }

    public function deleteKv(string $scope, string $key): void
    {
        $this->pdo->prepare('DELETE FROM kv WHERE scope=? AND key=?')->execute([$scope,$key]);
    }

    private function playerIdFromRefid(string $refid): int
    {
        $prefix = substr($refid,0,8);
        if (ctype_xdigit($prefix) && $prefix !== '') $value = (int)hexdec($prefix);
        else {
            $hash = hash('sha256',$refid !== '' ? $refid : 'GUEST',true);
            $u = unpack('N',substr($hash,0,4));
            $value = (int)$u[1];
        }
        $value &= 0x7fffffff;
        return $value ?: 1;
    }
}
