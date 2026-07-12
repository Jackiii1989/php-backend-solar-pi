# php-backend-solar-pi

PHP backend for a Raspberry Pi IoT metering project, hosted on a Plesk server
(`optimasolar-freiamt.ch`). This README doubles as the **project memory file**:
what we built, why we built it that way, and everything learned along the way.

---

## 1. The idea

Inspired by EGA Elektra Aettenschwil's dynamic grid tariff
(<https://elektra-aettenschwil.ch/einheitstarif-mit-dynamischem-netznutzungstarif-2026/>),
which is served by the Swisspower **ESIT** API
(<https://esit.code-fabrik.ch/api/v1>, docs: <https://esit.code-fabrik.ch/doc_scalar>):
HTTPS GET, JSON, prices per 15-minute slot, min 1 Rp./kWh, max 20 Rp./kWh,
weighted average 12.30 Rp./kWh, curve calibrated to the grid's forecast load.

We replicate that *style of API* on our own server. Current phase:

> Receive 15-minute energy aggregates from a Raspberry Pi over POST,
> store them in MySQL, and serve them as JSON over an ESIT-style GET API.

A later phase can add the "price engine" (compute a dynamic price curve from
the measured load, EGA-style: load-driven curve, spread to min/max, calibrated
to a target average).

## 2. Architecture

```text
Raspberry Pi ──POST /ingest.php──────► meter_aggregates (MySQL)      [ingest: TODO]
                                              │
any client   ──GET /api-v1.php ◄──────────────┘                      [live, mock data]
(curl, browser,       returns JSON: 15-min slots,
 later the Pi)        ESIT-style: HTTPS GET, Bearer token, JSON
```

- **`public/`** is the document root — the ONLY folder reachable by URL.
- **`src/`** and **`.env`** live one level above: physically impossible to
  request from a browser. The document root is a security boundary.
- **`.env`** holds all secrets (DB credentials, API token). Never committed
  (`.gitignore`), created by hand on each machine, `chmod 600`.

## 3. Current status

| Piece | State |
|---|---|
| `db/schema.sql` — `meters` + `meter_aggregates`, FK, unique window key | ✅ done (loaded locally; server DB still TODO) |
| `src/config.php` — .env loader, fail-fast | ✅ done |
| `src/api-helper.php` — `respond()`, `require_bearer_token()` | ✅ done |
| `public/api-v1.php` — GET endpoint, **mock** single price slot | ✅ live on the server |
| Plesk deployment (Git pull → deploy, document root, TLS) | ✅ working |
| `src/db.php` — PDO connection helper | ⬜ empty |
| `public/ingest.php` — POST receiver for the Pi | ⬜ empty |
| Real data behind the GET endpoint (`source: "database"`) | ⬜ later |
| Price engine (EGA-style formula) | ⬜ later |

### ⚠️ Open punch list (cleanup from the deployment debugging session)

1. **`public/api-v1.php` has the token hardcoded** (`'test123'`) and the
   `config.php` require commented out. Restore:
   `require_bearer_token($_SERVER['HTTP_AUTHORIZATION'] ?? '', config('API_TOKEN'));`
   and re-enable `require_once __DIR__ . '/../src/config.php';`
2. **`test123` is burned** — it's hardcoded in a public GitHub repo and
   traveled the internet via `-k` connections. Generate a real token
   (`php -r "echo bin2hex(random_bytes(32));"`) into BOTH `.env` files
   (server + local PC). Git history never forgets: treat any secret that ever
   touched a commit as public.
3. **`public/public.htaccess` is misnamed** — Apache only reads `.htaccess`.
   The live server works because the file was created manually in File
   Manager; the repo copy deploys to the wrong name. Fix:
   `git mv public/public.htaccess public/.htaccess`
4. **Delete `public/authcheck.php`** (temporary diagnostic, already gutted).
5. `public/api-v1.php` still has a top-level `"unit": "kWh"` left over from
   the load-profile version — remove it; the unit now lives inside each slot
   (`CHF/kWh`).

## 4. The API contract (as deployed)

```
GET https://optimasolar-freiamt.ch/api-v1.php?date=YYYY-MM-DD
Authorization: Bearer <API_TOKEN>
```

| Param | Required | Meaning |
|---|---|---|
| `date` | no | Day to return (UTC). Default: today. Round-trip validated. |

Response `200`:

```json
{
  "meter_id": "mock-meter-001",
  "date": "2026-07-11",
  "resolution_minutes": 15,
  "source": "mock",
  "slots": [
    {
      "start_timestamp": "2026-07-11T00:00:00+00:00",
      "end_timestamp": "2026-07-11T00:15:00+00:00",
      "value": 0.15,
      "unit": "CHF/kWh"
    }
  ]
}
```

Status codes: `200` OK · `400` bad input (unparseable date) · `401` missing or
wrong token · `405` wrong HTTP verb (with `Allow:` header).

Contract design rules we follow:
- **Contract-first**: the JSON shape was designed with mock data before any
  DB existed; internals change, the contract doesn't.
- `slots` is always an **array**, even with one element — clients written
  against a list keep working when 96 slots arrive.
- **Metadata before data** (`resolution_minutes`, per-slot `unit`) — clients
  should never have to guess what the server can simply state.
- `"source": "mock"` is an honest flag; flips to `"database"` when real data
  feeds the endpoint.
- Timestamps are ISO 8601 **with offset** (`DateTimeInterface::ATOM`) —
  self-describing, no timezone guessing. Same style as ESIT.

## 5. Key design decisions (and why)

1. **Two-table schema** — `meters` (entities) + `meter_aggregates` (events),
   linked by FK. One place for meter facts; DB-enforced integrity; a silent
   meter is representable (`LEFT JOIN`); the meters table doubles as an
   allowlist. (`docs/backend_php_project_plan.md` has the full rationale.)
2. **Unknown meter on ingest → REJECT (422)**, not auto-register. A leaked
   token alone can't invent meters; a typo'd ID fails loudly instead of
   silently becoming a "real" meter.
3. **Same Bearer token for GET and POST.** Load data reveals when someone is
   home — it is private, unlike ESIT's public prices.
4. **Schema wins over plan doc**: the Pi sends 4 fields
   (`meter_id`, `window_start_utc`, `window_end_utc`, `energy_delta_kwh`).
   Derivable values (avg power = kWh × 4) are not stored.
5. **Idempotency via `UNIQUE KEY uq_meter_window`** — a retried POST hits a
   duplicate-key error (1062) and is answered `200 "already recorded"`, not
   treated as a failure. The window itself is the idempotency key.
6. **Everything in the DB is UTC** (`*_utc` columns, `DATETIME`). Enforced at
   the boundary: incoming timestamps are converted to UTC on arrival;
   `UTC_TIMESTAMP()` in SQL, never `NOW()` (server-timezone-dependent).
7. **Plain PHP, no framework, no Composer** — a deliberate learning choice.

## 6. Learnings

### PHP

- `declare(strict_types=1);` — first statement of every file; type mismatches
  explode at the source instead of after a silent conversion.
- PHP-only files start with `<?php` and **omit the closing `?>`** — stray
  bytes after `?>` would break `header()` ("headers already sent").
- `static $x = null;` inside a function = compute once per request, remember
  across calls (used for the parsed `.env` and later the PDO connection).
- Return types tell stories: `never` = always exits (checked by PHP!),
  `void` = returns, but hands back nothing.
- `$_SERVER['HTTP_AUTHORIZATION'] ?? ''` — request headers appear uppercased
  with `HTTP_` prefix; a missing header means the key doesn't exist at all,
  so null-coalesce to `''`.
- `php://input` = the raw request body (`$_POST` only understands HTML forms,
  not JSON).
- `DateTimeImmutable` — `->add()` returns a NEW object (no mutation bugs).
  `'PT15M'` = ISO 8601 duration. `DateTimeInterface::ATOM` = ISO 8601 with
  offset. `'!Y-m-d'` — the `!` zeroes all fields the format doesn't mention.
- **Round-trip date validation**: parse, format back, require equality.
  `createFromFormat` alone accepts `2026-02-31` and rolls it to March 3rd.
- `Y-m-d H:i:s` strings sort alphabetically = chronologically; that's why
  string comparison of such timestamps is correct.
- PDO setup: `ERRMODE_EXCEPTION` (errors can't be ignored),
  `FETCH_ASSOC` (no duplicate numeric keys),
  `EMULATE_PREPARES => false` (real server-side prepares, real types).
- `PDOException->errorInfo` = `[SQLSTATE, driver code, message]`;
  MySQL 1062 = duplicate key, 1452 = FK violation. Catch where you can
  *respond*, not where the error happens; never echo raw DB exceptions
  (they contain host/user names).

### HTTP / API design

- An endpoint is a series of **gates**: method → auth → parse → validate →
  act. Cheapest checks first; auth before doing work for strangers.
- Status semantics: `400` = couldn't even parse; `401` = who are you;
  `405` = wrong verb (+ `Allow:` header); `422` = well-formed but
  semantically unacceptable (unknown meter); a **duplicate is `200`**, not an
  error — that's idempotency working.
- One `respond()` helper = every exit path has the same shape, and
  `Content-Type` can't be forgotten on a rare error path.
- `hash_equals()` not `===` for tokens — constant-time comparison defeats
  byte-by-byte timing attacks. Compare the full `"Bearer <token>"` string:
  one check covers absent, malformed, and wrong.
- The 401 message deliberately doesn't distinguish "missing" from "invalid".

### Functions / code structure

- **Dependency injection**: a function's signature should tell the whole
  truth. `require_bearer_token($authHeader, $expectedToken)` — globals are
  read at the call site (the boundary), not hidden inside helpers.
- Shared helpers live in `src/` and are `require_once`d — copy-paste means
  fixing every bug twice, forever.
- `__DIR__` anchors requires to the file's own folder — works regardless of
  the web server's working directory.

### MySQL

- `utf8mb4` is real UTF-8 (plain `utf8` is a broken 3-byte subset). Charset
  in the PDO DSN = PHP and MySQL agreeing how bytes travel.
- `DECIMAL` not `FLOAT` for counters (exact, no drift); `DATETIME` not
  strings (real comparisons, `MAX()`).
- A `UNIQUE KEY` is a second B-tree maintained on every insert — here the
  cost is irrelevant and it buys correctness (idempotency) plus lookup speed.
- `ON UPDATE CASCADE` (renames carry history), `ON DELETE RESTRICT`
  (history can't be orphaned).
- On shared hosting the DB user can't `CREATE DATABASE` — Plesk creates the
  DB; import only the `CREATE TABLE` statements.

### Windows / PowerShell / curl

- **In PowerShell, `curl` is an alias for `Invoke-WebRequest`** — different
  tool, different flags. Use `curl.exe` for real curl. (`Get-Alias curl`
  exposes the trick.)
- `Invoke-WebRequest` throws on non-2xx — useless for testing error paths;
  curl treats every status as a result.
- Variables: bash `TOKEN=x` / `$TOKEN` · PowerShell `$TOKEN = "x"` / `$TOKEN`
  · cmd `set TOKEN=x` / `%TOKEN%`. Variables die with their window — an unset
  `$TOKEN` silently expands to nothing and you send `Bearer ` (guaranteed 401).
- Pretty-print JSON in PowerShell:
  `... | ConvertFrom-Json | ConvertTo-Json -Depth 5` (default depth 2
  mangles nested objects). After `ConvertFrom-Json` you can query fields
  directly: `$r.slots[0].value`.
- `curl -i` shows the status line — never debug without it; `-s` alone can
  hide everything (empty bodies, redirects).

### Plesk deployment

- **Plesk Git = two steps**: pull into a *bare repo* (`~/git/x.git`, the
  database, no files) + **deploy** (checkout copied to the deployment path).
  Files appear only after "Pull und Bereitstellen".
- **Document root** (`Hosting-Einstellungen`) = the `-t public` of
  production: set to `httpdocs/php-backend-solar-pi/public`. URL → file is
  string concatenation under the document root; `..` can't escape it.
- `.env` is created by hand on the server (File Manager), `chmod 600` —
  deploys never touch it because it's not in git. That's by design.
- **Apache strips the `Authorization` header** before FastCGI/FPM — the
  famous trap: everything 401s in production while working locally. Fix in
  `public/.htaccess`:
  `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1`
  (File must be named exactly `.htaccess`; dotfiles are hidden in File
  Manager by default. nginx-served PHP ignores `.htaccess` entirely.)
- **TLS**: without a cert, Plesk serves its default one → curl fails with
  `SEC_E_WRONG_PRINCIPAL` (name mismatch — exactly what a MITM looks like).
  Fix: free Let's Encrypt cert (SSL It!), then force HTTP→HTTPS redirect.
  Precondition: DNS must point at the server (ours: `80.74.150.210` ✓).
- **`curl -k` disables the protection TLS exists for.** Acceptable only for
  requests carrying no secrets (e.g. inspecting which cert is served:
  `curl -kv ... | Select-String "subject|issuer"`). Any token sent over `-k`
  is burned — rotate it.
- SSH keys: **deploy key** (repo Settings → Deploy keys, read-only, one
  repo) for servers that only pull; **account key** (user Settings → SSH
  keys, acts as you, all repos) for your PC. Match key scope to what the
  machine legitimately needs. Public repos need no key for pulling (HTTPS).
- Windows Git push over SSH needs a key (`ssh-keygen -t ed25519`, public
  half to GitHub) — or switch the remote to HTTPS and let Git Credential
  Manager do a browser login.

### Debugging method (the meta-learning)

- Read the response like evidence: `Server:` header, status code, body —
  each narrows who answered (nginx? Apache? PHP? *our* code?). A JSON error
  from our own `respond()` proves the request traversed the whole chain.
- **Isolate one variable at a time**: hardcode the expected token → config
  loading is out of the game; a diagnostic endpoint (`authcheck.php`) that
  reports *presence + length, never the value* → shows what PHP actually
  receives. Delete diagnostics when done, and never commit secrets "just
  temporarily" — public git history is forever.
- Keep a decoder table (status → meaning → fix) instead of re-deriving under
  stress: 401-with-correct-token = header stripped; 404 = document
  root/deploy; 403 = permissions/handler; 500 = check `.env` + error log;
  PHP source in browser = handler off.

## 7. Running locally

```powershell
# terminal 1 — dev server, document root = public/
cd C:\php_projects\php-backend-solar-pi
php -S 0.0.0.0:8000 -t public

# terminal 2 — tests (PowerShell)
$TOKEN = "<value of API_TOKEN in local .env>"
curl.exe -i "http://localhost:8000/api-v1.php?date=2026-07-11" -H "Authorization: Bearer $TOKEN"  # 200
curl.exe -i "http://localhost:8000/api-v1.php"                                                    # 401
curl.exe -i -X POST "http://localhost:8000/api-v1.php" -H "Authorization: Bearer $TOKEN"          # 405
curl.exe -i "http://localhost:8000/api-v1.php?date=2026-02-31" -H "Authorization: Bearer $TOKEN"  # 400
```

`.env` (repo root, never committed): `API_TOKEN=...` — later also
`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

## 8. Deploying

```text
PC: edit → git add/commit → git push (main)
Plesk: Git → "Pull und Bereitstellen"   (deploy path: httpdocs/php-backend-solar-pi)
Test: curl.exe -i "https://optimasolar-freiamt.ch/api-v1.php" -H "Authorization: Bearer $TOKEN"
```

Server-only artifacts (not in git, survive deploys): `.env`.
Everything else — including `.htaccess` — is config-as-code in the repo.

## 9. Roadmap

1. Punch list in §3 (token, requires, `.htaccess` rename, delete authcheck).
2. `src/db.php` — PDO helper (`db()` with `static`, DSN, the three attrs).
3. Server DB: create via Plesk → phpMyAdmin import (tables only) → register
   first meter → DB creds into server `.env`.
4. `public/ingest.php` — the POST gates (405/401/400 → insert →
   1062⇒200, 1452⇒422, fresh⇒201).
5. Wire `api-v1.php` to real data (`source: "database"`); decide whether it
   serves energy (load profile) or stays a price endpoint fed by a future
   `tariff_prices` table.
6. Pi's `aggregator.py` POSTs real data.
7. Price engine, EGA-style (forecast load → price curve → spread to
   min/max → calibrate weighted average). Dashboard chart.
