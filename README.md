# Mahjong Fight Girl private server — PHP port

PHP 8.x port of `jeong-jimin-github/mfg-private-server` for shared-hosting deployment.

## Goals

- e-Amusement/XRPC compatibility
- `X-Eamuse-Info` RC4 transport
- AVS LZ77 transport
- kbin binary XML compatibility
- AOG game API
- card/profile/PASELI/gacha/dojo/match persistence
- DB-backed state suitable for PHP-FPM/shared hosting

## Requirements

- PHP 8.1+
- PDO
- PDO SQLite by default (MySQL-compatible storage can be added for hosting)

## Layout

- `public/index.php` — single HTTP entry point
- `src/Protocol/EamuseProtocol.php` — e-Amusement transport helpers
- `src/Storage/Database.php` — persistent state
- `src/App.php` — request dispatcher
- `data/` — runtime DB/data (not committed)

## Status

Port is in progress. RC4/LZ77 transport and persistent request routing are being brought up first, followed by kbin codec compatibility and then the AOG/match engine handlers.
