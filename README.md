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
- `src/Aog/MiscDispatcher.php` / `StampStore.php` — sticker chat / misc compatibility routes
- `src/Debug/CaptureStore.php` / `CaptureComparator.php` — optional real-client capture and structural diff support
- `tools/compare_capture_runs.php` — compare Python-reference and PHP capture runs
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
- original GET `/`, `/health`, `/status` compatibility page plus JSON `/healthz`

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
- original-format sticker chat and stamp polling persistence
- full original generated gacha item pools from `data/gacha_pools.json`
- default 27-series safe gacha advertisement and optional full 141-series catalog
- pickup preview recursion guards and N/R/SR/UR pool safety checks
- reach-song pools for MusicHiyori / MusicSen / MusicYao / MusicTenshi / MusicMusashi
- `VFG_EVENT_TAKU=off|min|all` event-table flag selection with the original three-panel safety budget
- all Python `GAME_HANDLERS` names covered both directly and through the real HTTP front controller

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
- kyuushu-kyuuhai abortive draw (the abortive-draw variant implemented by the Python reference)
- dealer continuation, honba progression, all-last and bankruptcy termination
- DB-serializable CPU turn loop and shanten-aware CPU discard/call decisions

## Environment switches

| Variable | Default | Effect |
|---|---|---|
| `VFG_EVENT_TAKU` | `min` | `off`, safe three-banner `min`, or known-overbudget `all` event-table advertisement |
| `VFG_GACHA_ALL` | off | default 27 safe series; `1`, `true`, `yes`, or `on` advertises all 141 catalog series |
| `VFG_CARDMNG_MODE` | `compat` | `strict` restores malformed-card quarantine behavior |
| `VFG_CARDMNG_INQUIRE_MODE` | `auto` | `new` always returns the new-card response |
| `VFG_CAPTURE_DIR` | disabled | writable directory for decoded request/response XML plus transport metadata/binary captures; keep it outside the web root |

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
the same `Database` API and both dialects have CI coverage. CI also creates a
second MySQL connection to verify that client state, serialized matches, sticker
chat and gacha transactions survive independent PHP requests.

## Real-client capture comparison

Captures are disabled by default. Set `VFG_CAPTURE_DIR` to a writable directory
outside the public document root before starting PHP-FPM or the local PHP server.
The PHP front controller then stores decoded request/response XML plus transport
metadata/binaries for e-Amusement traffic and decoded AOG requests/responses.

After collecting equivalent sessions from the Python reference and PHP port,
compare them structurally with:

```bash
php tools/compare_capture_runs.php /path/to/python-run /path/to/php-run
```

The default comparison ignores dynamic XML values such as IDs/timestamps but
preserves node structure, attributes and KBin `__type` metadata. Add `--values`
when the two sessions are deterministic enough for exact value comparison:

```bash
php tools/compare_capture_runs.php --values /path/to/python-run /path/to/php-run
```

The comparator returns a non-zero exit status on differences, so captured
arcade-client sessions can also be used as CI/regression fixtures later.

## Tests

GitHub Actions runs the main regression suite on PHP 8.1, 8.2 and 8.3, a real
MySQL 8 persistence job, a real `php -S` HTTP transport job, and a PHP 8.2
all-game-mode / multi-seed soak job.

The suite covers protocol/kbin, cardmng/eacoin, e-Amusement bootstrap, vfglog,
AOG parser contracts, event flags, mgresult, Mahjong scoring/rules, CPU behavior,
gacha crash-safety, every AOG route and full matches.

`tests/match_e2e_test.php` mirrors the Python `test_match_e2e.py` client loop:
`entry_game -> gget/gpost -> KYOKUEND -> end_game` across the seven reference
modes. `tests/all_modes_soak_test.php` extends the same flow through all 23
supported game modes and also supports selectable modes/seeds. The reference
all-mode seed completes 184 kyoku over 2,830 request/response loops. Three
additional seed bases run representative 2P/3P/4P and event modes (18 more full
matches), completing another 130 kyoku and 2,403 loops. Together the checked
soak set is 41 complete matches, 314 kyoku and 5,233 client loops.

`tests/app_transport_e2e_test.php` exercises the in-process App stack, while
`tests/http_transport_client.php` sends the real binary RC4 + LZ77 + KBin wire
format through a live `php -S` socket and checks card quarantine, PASELI,
sticker/state persistence, and all 45 AOG routes through the public HTTP entry
point. `tests/http_facility_ip4_client.php` validates the facility IPv4 field
through the same binary path, and `tests/http_card_entry_client.php` replays the
captured physical-card load order from `vfgcard.inquire` through `player_record`.

Shared-hosting configuration is also regression-tested. When the project root
itself is exposed as the document root, `.htaccess` denies direct access to
`src/`, `data/`, `tests/`, `.github/`, local DB/config files and repository
metadata before the front-controller rewrite runs.

Key tests include:

```bash
php tests/database_test.php
php tests/shared_hosting_security_test.php
php tests/protocol_test.php
php tests/kbin_test.php
php tests/app_transport_e2e_test.php
php tests/http_transport_client.php       # requires the CI-style local PHP server
php tests/http_facility_ip4_client.php    # same server
php tests/http_card_entry_client.php      # same server
php tests/health_test.php
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
php tests/all_modes_soak_test.php
php tests/mysql_request_persistence_test.php
php tests/aog_routes_test.php
```

## Remaining parity work

The Python server remains the reference implementation. Missing top-level routes,
deterministic full matches, representative multi-seed matches, real HTTP routing,
database request persistence and the captured card-entry ordering now have
automated PHP coverage. The remaining work requires evidence from the actual
arcade client rather than more synthetic server-only tests:

- response-by-response comparison of Python/PHP real-client capture runs, especially binary KBin attribute/type metadata
- real arcade-client confirmation for fields, request ordering and timing behavior not exercised in the available captures

## Shared hosting

Point the web root at `public/` when possible. If the host requires the project
itself to live in the public directory, the supplied root `.htaccess` denies
runtime data, source, tests and local configuration before routing application
requests to `index.php`.
