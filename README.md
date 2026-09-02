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
- `src/Aog/GachaPools.php` — original generated gacha-pool loader
- `src/Aog/MiscDispatcher.php` — sticker chat / profile / misc compatibility routes
- `src/Mahjong/Mahjong.php` — tile maths + shanten/waits
- `src/Mahjong/HandEvaluator.php` — yaku/fu/han evaluation
- `src/Mahjong/YakuBits.php` — client-facing yaku bit mapping
- `src/Mahjong/ResultXml.php` — client-facing win/result XML
- `src/Mahjong/SituationalYaku.php` — table-state yaku
- `src/Mahjong/RoundSettlement.php` — exhaustive-draw and continuation rules
- `src/Mahjong/Table.php` — DB-serializable taikyoku state machine
- `src/Storage/Database.php` — MySQL/SQLite persistent state
- `data/gacha_pools.json` — synchronized original generated item pools

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
- `vfglog.put_msg` client diagnostics, including `network_error`
- empty-success compatibility modules
- RC4 + LZ77 + request-mirrored kbin transport
- original card compatibility switches and corrupt-card recovery behavior

### AOG

- boot/info/login/logout/create-player
- menu data + game modes 1–23 + battle-item parser-safe nodes
- original root / `serv_st` response contract
- lossless client-state persistence
- `/entry_game`, `/gget`, `/gpost`, `/end_game`, `/kiken_game`, `/end_show`, `/reconnect`
- parser-compatible matching / `mwait` data
- original-style `mgresult` player/rank/score/uma output
- persistent table state across PHP-FPM requests
- spirit gym four-slot real-time stock persistence
- sticker chat and stamp polling persistence
- full original generated gacha item pools from `data/gacha_pools.json`
- unlock pickup items and pickup-character fallback guards
- reach-song pools for MusicHiyori / MusicSen / MusicYao / MusicTenshi / MusicMusashi
- `VFG_EVENT_TAKU=off|min|all` event-table flag selection with the original three-panel safety budget
- all Python `GAME_HANDLERS` names covered by AOG route smoke tests

### Mahjong engine

- MFG tile-id conversion
- yonma / sanma / nima wall construction
- reduced-table dora cycles
- standard / chiitoitsu / kokushi shanten and completion
- waits and ukeire
- KYOKUSTART / TSUMO / TSUMOCHOICES / SUTEHAI / SUTECHOICES / SCORERANK / KYOKUEND stream
- chi / pon / ankan / minkan / kakan state
- rob-kakan / chankan reaction arbitration
- multi-winner ron arbitration
- ron / tsumo scoring and riichi-stick settlement
- honba payments
- riichi / double-riichi / ippatsu
- permanent and temporary furiten basics
- haitei / houtei / rinshan / chankan scoring context, including situational-only wins
- tenho / chiho scoring context
- dora / ura-dora
- client-facing yaku bit-field and result XML generation
- exhaustive-draw tenpai detection and 3000-point noten settlement
- kyuushu-kyuuhai abortive draw
- dealer continuation, honba progression, all-last and bankruptcy termination
- DB-serializable CPU turn loop and shanten-aware CPU discard/call decisions

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

The suite covers protocol/kbin, cardmng/eacoin, e-Amusement bootstrap, vfglog,
AOG parser contracts, event flags, mgresult, Mahjong scoring/rules, CPU behavior,
gacha pools, every AOG route and full matches.

`tests/match_e2e_test.php` mirrors the Python `test_match_e2e.py` client loop:
`entry_game -> gget/gpost -> KYOKUEND -> end_game`. With deterministic walls it
completes all seven reference modes: NIMA, SANMA, TONPU, HANCHAN, FireReach,
AccelDora and Bomb.

Key tests include:

```bash
php tests/database_test.php
php tests/protocol_test.php
php tests/kbin_test.php
php tests/cardmng_eacoin_test.php
php tests/eamuse_bootstrap_test.php
php tests/vfglog_test.php
php tests/aog_match_contract_test.php
php tests/event_flags_test.php
php tests/mgresult_contract_test.php
php tests/hand_evaluator_test.php
php tests/result_xml_test.php
php tests/table_rules_integration_test.php
php tests/multi_ron_test.php
php tests/gacha_pools_test.php
php tests/match_e2e_test.php
php tests/aog_routes_test.php
```

## Remaining parity work

The Python server remains the reference implementation. The large functional
paths now have automated PHP parity coverage, but the remaining work is mainly
byte/edge-case verification rather than missing top-level routes:

- remaining abortive-draw variants and exact first-go-around corner cases where the Python reference supports them
- response-by-response comparison against captured real-client requests, especially binary kbin metadata/type details
- end-to-end RC4 + LZ77 + kbin tests through the public `App` HTTP-dispatch layer, not only codec/dispatcher unit tests
- long-running soak/fuzz coverage beyond the deterministic seven-mode match suite
- real arcade-client confirmation for any fields not exercised by the current automated fixtures

## Shared hosting

Point the web root at `public/` when possible. If the host requires the project
itself to live in the public directory, keep `src/`, `data/` and local config
protected from direct HTTP access and route requests to `public/index.php` with
the supplied `.htaccess`.
