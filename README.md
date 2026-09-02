# Mahjong Fight Girl private server — PHP port

PHP 8.x port of `jeong-jimin-github/mfg-private-server` for shared-hosting deployment.

## Goals

- e-Amusement/XRPC compatibility
- `X-Eamuse-Info` RC4 transport
- AVS LZ77 transport
- native PHP kbin binary XML compatibility
- AOG game API
- card/profile/PASELI/gacha/dojo/match persistence
- DB-backed state suitable for PHP-FPM/shared hosting
- no Python runtime dependency

## Requirements

- PHP 8.1+
- DOM + SimpleXML
- PDO
- PDO MySQL/MariaDB for production shared hosting, or PDO SQLite
- `mbstring` recommended for CP932/EUC-JP kbin payloads

## Layout

- `public/index.php` — single HTTP entry point
- `src/Protocol/EamuseProtocol.php` — RC4 + AVS LZ77
- `src/Protocol/KBinXml.php` — native Konami binary XML codec
- `src/Eamuse/Dispatcher.php` — XRPC handlers
- `src/Aog/Dispatcher.php` — core AOG + match API
- `src/Aog/FeatureDispatcher.php` — gacha / spirit gym
- `src/Aog/MiscDispatcher.php` — sticker chat / profile / misc compatibility routes
- `src/Mahjong/Mahjong.php` — tile maths + shanten/waits
- `src/Mahjong/HandEvaluator.php` — yaku/fu/han evaluation
- `src/Mahjong/SituationalYaku.php` — table-state yaku
- `src/Mahjong/RoundSettlement.php` — exhaustive-draw and continuation rules
- `src/Mahjong/Table.php` — DB-serializable taikyoku state machine
- `src/Storage/Database.php` — MySQL/SQLite persistent state
- `data/` — local SQLite runtime data (not committed)

## Implemented

### e-Amusement / XRPC

- `services.get`
- `pcbtracker`
- `message`
- `facility`
- `package`
- `pcbevent`
- `eventlog`
- `cardmng` / `vfgcard`: `inquire`, `getrefid`, `authpass`, `bindmodel`, `bindcard`, `getdatalist`
- `eacoin`: `checkin`, `opcheckin`, `consume`, `getbalance`, `checkout`
- `vfgac.service_list`
- `vfglog`
- empty-success compatibility modules
- RC4 + LZ77 + request-mirrored kbin transport

### AOG

- boot/info/login/logout/create-player
- menu data + game modes 1–23 + battle-item parser-safe nodes
- lossless client-state persistence
- `/entry_game`, `/gget`, `/gpost`, `/end_game`, `/kiken_game`, `/end_show`, `/reconnect`
- persistent table state across PHP-FPM requests
- spirit gym four-slot real-time stock persistence
- sticker chat and stamp polling persistence
- curated gacha banners with non-empty pools
- unlock pickup items and pickup-character fallback guards
- reach-song pools for MusicHiyori / MusicSen / MusicYao / MusicTenshi / MusicMusashi
- all Python `GAME_HANDLERS` names covered by AOG route smoke tests

### Mahjong engine

- MFG tile-id conversion
- yonma / sanma / nima wall construction
- reduced-table dora cycles
- standard / chiitoitsu / kokushi shanten and completion
- waits and ukeire
- KYOKUSTART / TSUMO / TSUMOCHOICES / SUTEHAI / SUTECHOICES / SCORERANK / KYOKUEND stream
- chi / pon / ankan / minkan / kakan state
- ron / tsumo scoring and riichi-stick settlement
- honba payments
- riichi / double-riichi / ippatsu
- permanent and temporary furiten basics
- haitei / houtei / rinshan / chankan scoring context
- tenho / chiho scoring context
- dora / ura-dora
- exhaustive-draw tenpai detection and 3000-point noten settlement
- dealer continuation, honba progression, all-last and bankruptcy termination
- DB-serializable CPU turn loop

## Database configuration

SQLite remains the zero-configuration fallback:

```text
DB_DSN=sqlite:/absolute/path/to/data/mfg.sqlite
```

For MySQL/MariaDB shared hosting:

```text
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=mfg;charset=utf8mb4
DB_USER=mfg_user
DB_PASS=secret
```

If the host cannot expose environment variables, copy `config.example.php` to
`config.local.php` and fill it locally. `config.local.php` is ignored by git and
must not be committed.

The schema is created automatically on first connection. SQLite and MySQL use
the same `Database` API and both dialects have CI coverage.

## Tests

GitHub Actions runs the regression suite on PHP 8.1, 8.2 and 8.3. It also starts
a real MySQL 8 service and runs the persistence API against it.

Key tests include:

```bash
php tests/database_test.php
php tests/protocol_test.php
php tests/kbin_test.php
php tests/mahjong_test.php
php tests/score_math_test.php
php tests/hand_evaluator_test.php
php tests/situational_yaku_test.php
php tests/round_settlement_test.php
php tests/table_rules_integration_test.php
php tests/match_test.php
php tests/calls_test.php
php tests/features_test.php
php tests/misc_test.php
php tests/aog_routes_test.php
```

## Remaining parity work

The port is functional but the Python server is still the reference implementation.
Remaining parity work includes:

- complete rob-kakan reaction arbitration instead of scoring-context support only
- full multi-winner ron arbitration
- remaining abortive-draw rules and exact first-go-around semantics
- exact yaku bit-field mapping expected by the original client result presentation
- full generated gacha item index/pools rather than the curated parser-safe subset
- response-by-response parity against the Python integration and long-running match soak suites

## Shared hosting

Point the web root at `public/` when possible. If the host requires the project
itself to live in the public directory, keep `src/`, `data/` and local config
protected from direct HTTP access and route requests to `public/index.php` with
the supplied `.htaccess`.
