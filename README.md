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
- PDO SQLite by default
- `mbstring` recommended for CP932/EUC-JP kbin payloads

## Layout

- `public/index.php` — single HTTP entry point
- `src/Protocol/EamuseProtocol.php` — RC4 + AVS LZ77
- `src/Protocol/KBinXml.php` — native Konami binary XML codec
- `src/Eamuse/Dispatcher.php` — XRPC handlers
- `src/Aog/Dispatcher.php` — core AOG + match API
- `src/Aog/FeatureDispatcher.php` — gacha / spirit gym
- `src/Mahjong/Mahjong.php` — tile maths + shanten/waits
- `src/Mahjong/Table.php` — DB-serializable taikyoku state machine
- `src/Storage/Database.php` — persistent SQLite state
- `data/` — runtime DB/data (not committed)

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
- menu data + game modes 1–23 + battle item parser-safe nodes
- lossless client-state persistence
- `/entry_game`, `/gget`, `/gpost`, `/end_game`, `/kiken_game`, `/end_show`
- persistent table state across PHP-FPM requests
- spirit gym four-slot real-time stock persistence
- curated gacha banners with non-empty pools
- unlock pickup items and pickup-character guards
- reach-song pools for MusicHiyori / MusicSen / MusicYao / MusicTenshi / MusicMusashi

### Mahjong engine

- MFG tile-id conversion
- yonma / sanma / nima wall construction
- reduced-table dora cycles
- standard shanten
- chiitoitsu shanten
- kokushi shanten
- waits and ukeire
- initial taikyoku command stream: KYOKUSTART / TSUMO / TSUMOCHOICES / SUTEHAI / SCORERANK
- DB-serializable CPU turn loop

## Tests

Run from the repository root:

```bash
php tests/protocol_test.php
php tests/kbin_test.php
php tests/mahjong_test.php
php tests/match_test.php
php tests/features_test.php
```

The tests cover RC4/LZ77 round-trips, compressed/uncompressed kbin round-trips,
standard/chiitoi/kokushi completion, reduced wall sizes, match command-stream
continuity, non-empty gacha pools and spirit-gym persistence.

## Still being ported

The original Python server has a deeper taikyoku implementation than the current PHP table core. Remaining work includes:

- full chi / pon / ankan / minkan / kakan call arbitration
- ron / tsumo settlement and complete yaku/fu/han scoring
- riichi ippatsu / furiten / haitei / houtei / rinshan / chankan / ura-dora
- exhaustive multi-kyoku continuation, honba and tenpai payments
- sticker chat CPU replies
- full original generated gacha item index/pools rather than the current parser-safe curated subset
- response-by-response parity verification against the Python integration suite

## Shared hosting

Point the web root at `public/` when possible. If the host requires the project itself to live in the public directory, keep `src/` and `data/` protected from direct HTTP access and route requests to `public/index.php` with the supplied `.htaccess`.

Runtime state is stored in `data/mfg.sqlite`; this path must be writable by PHP.
