# Valet Linux+ — Phase 6 Local DX Plan

**Version target:** 3.1.0 → 3.5.0  
**Date:** 2026-09-04  
**Status:** In progress — **3.1.0 shipped** (F-601 clone, F-609 tinker, F-612 sqlite, F-613 cache); next 3.2.0  
**Depends on:** [roadmap-local-dev.md](roadmap-local-dev.md) Phases 0–5 (especially F-101 ProjectContext, F-201 init, F-202 profiles, F-203 env, F-301 doctor, F-404 addons, F-501 Node)

This document is the full implementation plan for the **next wave of day-to-day DX features** beyond onboarding, health, and the machine API. Every feature here aims at **faster feedback loops**, **less manual wiring**, or **lower idle cost** on a native Linux stack.

---

## 1. Goals

- Shrink “clone → working site” and “cd → correct PHP/tooling” to near zero steps.
- Fix the highest-friction modern Laravel/Vite workflows (HTTPS + HMR).
- Make background workers (queue, schedule) first-class without Docker or hand-written units.
- Keep the light-RAM promise via idle sleep and opt-in addons.
- Stay native, backward-compatible, and scriptable (`--json` where useful).

## 2. Principles

| Principle | Constraint |
| --- | --- |
| **Backward compatible** | Existing `share`, `proxy`, `isolate`, `db:*`, `php`, `composer` unchanged |
| **Opt-in heaviness** | Workers, tunnels, webhook catcher, sleep never run unless asked (or profile-enabled) |
| **Safe by default** | Destructive / network-exposing actions need confirm or explicit flags |
| **Reuse foundation** | Build on `ProjectContext`, profiles, `ServiceRegistry`, addons, JSON schemas |
| **No Docker requirement** | Native packages, user-space binaries, or thin Nginx proxies only |
| **Tested** | Unit tests per new class; CLI registration tests; smoke where feasible |

## 3. Dependency Graph

```
Phase 5 complete (profiles, init, Node, API v1)
  │
  ├─→ Phase 6A — Friction killers
  │     F-601 clone, F-602 auto-php, F-603 vite, F-606 tune
  │
  ├─→ Phase 6B — Process & performance
  │     F-604 sleep/wake, F-605 queue/schedule, F-607 bench
  │
  ├─→ Phase 6C — Debug & share
  │     F-608 webhooks, F-609 tinker, F-610 share providers
  │
  └─→ Phase 6D — Multi-project & caches
        F-611 workspace, F-612 sqlite, F-613 cache
```

Phases 6A–6D can partially overlap after 6A lands `ProjectContext` + profile hooks.  
**Hard deps:** F-603 (Vite) benefits from F-302 certs; F-605 workers benefit from F-604 sleep; F-611 workspace benefits from F-601/F-202.

---

## 4. Phase 6A — Friction Killers

### F-601 — `valet clone`

**Problem:** New contributors still chain `git clone`, `cd`, `valet link`, `valet init`, DB setup, and `valet open` by hand.

**Command surface:**

```bash
valet clone <repository> [directory]
  [--branch=]
  [--link]
  [--init]
  [--db]
  [--migrate]
  [--secure]
  [--isolate=8.3]
  [--open]
  [--ssh|--https]
  [--force]
```

**Defaults (opinionated but overridable):**

| Flag | Default when omitted |
| --- | --- |
| `--link` | on if directory is under a parked path or user confirms |
| `--init` | off unless `--db` / `--migrate` / profile present |
| `--open` | off |

**Flow:**

1. Resolve clone URL (HTTPS or SSH via `--ssh` / `--https` / URL scheme).
2. `git clone` into `[directory]` (default: repo basename).
3. `chdir` into project; resolve `ProjectContext`.
4. If `.valet/profile.json` exists → `profile use --apply`.
5. Else if `--init` / `--db` / `--migrate` / `--isolate` / `--secure` → call `Init` (F-201).
6. Else if `--link` → `SiteLink::link()`.
7. Optional `--open` → browser.

**Implementation:**

| Component | Responsibility |
| --- | --- |
| `cli/Valet/CloneProject.php` | Orchestrate git + Init + Link |
| Reuse `Init`, `Profile`, `ProjectContext` | No duplicated onboarding logic |
| `CommandLine` | `git` invoke with escaped args |

**Files:** `CloneProject.php`, `Facades/CloneProject.php`, `tests/Unit/CloneProjectTest.php`, `cli/app.php`

**Acceptance criteria:**

- Idempotent directory: refuse non-empty target unless `--force`
- Works with SSH and HTTPS remotes
- Exit non-zero if `git` missing; message points to install
- Documented example: `valet clone git@github.com:org/app.git --init --db --secure --open`

**Effort:** M (4–5 days)  
**Depends on:** F-201, F-202 (soft — works without profile)

---

### F-602 — Direnv-style auto PHP (shell hook)

**Problem:** Global `php` / `composer` often mismatch the site’s isolated version after `cd`.

**Command surface:**

```bash
valet shell-hook [--shell=zsh|bash|fish]
valet env --php-bin          # print absolute php binary for CWD (also useful alone)
```

**Install UX:**

```bash
# printed by valet shell-hook
eval "$(valet shell-hook)"
# or document in README:
# echo 'eval "$(valet shell-hook)"' >> ~/.zshrc
```

**Behavior:**

1. Hook runs on `chpwd` (zsh) / `PROMPT_COMMAND` (bash) / fish events.
2. Calls `valet which-php` (fast path) or a tiny cached resolver.
3. If CWD is under a linked/parked/isolated site → export:
   - `VALET_SITE`, `VALET_PHP`, prepend PHP bin dir to `PATH`
   - optionally `COMPOSER_BINARY` if detectable
4. If leaving a Valet site → restore previous `PATH` / unset vars.
5. **Performance:** cache map of path → PHP version in `~/.config/valet/shell-hook.cache.json`; invalidate on `isolate` / `unisolate` / `use`.

**Implementation:**

| Component | Responsibility |
| --- | --- |
| `cli/Valet/ShellHook.php` | Emit shell-specific script |
| Fast path in `ProjectContext` | `phpBinaryForPath(string $path): ?string` |
| Invalidate cache from `SiteIsolate` / `PhpFpm::use` | Write version stamp |

**Safety:**

- Never silently installs packages
- Hook is no-op outside Valet sites
- Document that direnv users can use `valet env --export=shell` instead

**Files:** `ShellHook.php`, stubs under `cli/stubs/shell/`, tests, `docs/commands.md` + README snippet

**Acceptance criteria:**

- Entering an isolated Laravel site makes `php -v` match isolate
- Leaving restores previous PHP
- Hook generation covered by unit tests (string fixtures per shell)
- Cold `cd` overhead target: &lt; 50ms with warm cache

**Effort:** M (5 days)  
**Depends on:** F-101, existing `which-php` / isolate

---

### F-603 — Vite / HMR proxy helpers

**Problem:** Laravel + Vite over `https://*.test` routinely breaks HMR (WebSocket, host checks, mixed content). Developers hand-edit `vite.config.js` and Nginx.

**Command surface:**

```bash
valet vite [--site=]
valet vite apply [--site=] [--port=5173] [--force]
valet vite status [--site=]
valet vite remove [--site=]
```

**What `apply` does:**

1. Detect Vite (`vite.config.{js,ts,mjs}`) via `ProjectContext` / filesystem.
2. Ensure site is secured (or warn + offer `secure`).
3. Write/merge Nginx snippet for the site that proxies:
   - `/@vite`, `/resources`, `/node_modules/.vite` (as needed)
   - WebSocket upgrade to `http://127.0.0.1:{port}`
4. Print recommended `vite.config` fragment (or patch if `--force` and file is Valet-managed):

```js
server: {
  host: '0.0.0.0',
  port: 5173,
  strictPort: true,
  hmr: { host: 'my-app.test', protocol: 'wss', clientPort: 443 },
  origin: 'https://my-app.test',
}
```

5. Optionally set Laravel `VITE_DEV_SERVER_URL` via `valet env` merge helper.
6. Restart Nginx.

**Stubs:**

- `cli/stubs/vite.hmr.nginx.conf` — location blocks + `proxy_http_version 1.1` + Upgrade headers
- `cli/stubs/vite.config.fragment.js` — printed or injectable fragment

**Implementation:**

| Component | Responsibility |
| --- | --- |
| `cli/Valet/Vite.php` | Detect, apply Nginx, print config advice |
| Extend site Nginx generation | Include HMR locations when flag set in site metadata |
| Config key | `sites.{name}.vite: { enabled, port }` in valet config or `.valet/vite.json` |

**Acceptance criteria:**

- Documented happy path: `valet link && valet secure && valet vite apply` + `npm run dev`
- `vite status` shows enabled/port/reachable (TCP check to localhost port)
- `vite remove` restores prior Nginx site config without leaving orphan locations
- Unit tests for config fragment generation and Nginx stub rendering

**Effort:** L (1–1.5 weeks)  
**Depends on:** F-101, F-302 (certs strongly recommended)

---

### F-606 — PHP performance presets (`valet tune`)

**Problem:** Distro PHP-FPM defaults are conservative; local TTFB feels slow even when code is fine.

**Command surface:**

```bash
valet tune [preset] [--version=] [--site=] [--dry-run] [--force]
# presets: dev | fast | debug | show
valet tune show [--version=] [--site=]
```

**Presets:**

| Preset | Intent | Knobs (illustrative) |
| --- | --- | --- |
| `dev` | Balanced local | OPcache on, validate timestamps=1, moderate `pm` |
| `fast` | Snappy iteration | OPcache on, larger memory, `pm=ondemand` or tuned `dynamic`, long `realpath_cache` |
| `debug` | Xdebug-friendly | OPcache off or restrictive; aligns with `xdebug on` |
| `show` | Report current | Read effective ini + FPM pool |

**Scope:**

- Global: edit Valet-managed FPM pool / conf.d drop-in under Valet control
- Per-site (`--site`): only when isolated — write pool override for that site

**Safety:**

- Backup previous drop-in to `~/.config/valet/Backups/tune/`
- `--dry-run` prints diff
- Never overwrite non-Valet-managed system ini silently (`--force` required)

**Files:** `cli/Valet/Tune.php`, stubs `cli/stubs/tune/{dev,fast,debug}.ini`, tests

**Acceptance criteria:**

- Switching `dev` → `fast` → `dev` is idempotent and reversible
- `tune debug` + `xdebug on` documented as the debugging pair
- Unit tests parse/write ini stubs

**Effort:** M (4–5 days)  
**Depends on:** `PhpFpm`, isolate (for `--site`)

---

## 5. Phase 6B — Process & Performance

### F-604 — Site sleep / wake

**Problem:** Many linked sites keep FPM pools and optional services warm forever; contradicts the light-RAM goal.

**Command surface:**

```bash
valet sleep [site] [--all] [--services]
valet wake [site] [--all]
valet idle [--minutes=30] [--enable|--disable|--status]
```

**Behavior:**

| Command | Actions |
| --- | --- |
| `sleep [site]` | Stop site-specific isolated FPM pool if safe; mark site `asleep` in config |
| `sleep --services` | Also stop opt-in services listed in site profile (redis/mailpit only if no other awake site needs them) |
| `wake` | Start pool / services again; clear `asleep` |
| `idle --enable` | Background watcher (user systemd unit) sleeps sites with no HTTP hits for N minutes |

**Idle watcher design:**

- Parse Nginx access log or maintain a tiny hit counter via existing dashboard/server bootstrap
- Prefer **user-level systemd timer** + oneshot (`valet idle:tick`) over root daemon
- Opt-in only (`idle --enable`)

**Request-driven wake (stretch):**

- Optional Nginx `error_page` / lua-free approach: document manual `wake`, or use a lightweight wrapper — **do not** require OpenResty in v1
- v1: explicit wake + idle timer is enough

**Implementation:**

| Component | Responsibility |
| --- | --- |
| `cli/Valet/SiteSleep.php` | sleep/wake state machine |
| `cli/Valet/IdleWatcher.php` | tick + enable/disable user unit |
| Config | `sites.{name}.asleep: bool`, `idle: { enabled, minutes }` |

**Acceptance criteria:**

- Sleeping an isolated site stops its pool; other sites unaffected
- `status` / dashboard show asleep sites
- `idle --enable` installs a user systemd unit; `--disable` removes it
- Refcount: do not stop redis if another awake profile still lists it

**Effort:** L (1–1.5 weeks)  
**Depends on:** F-202 profiles (for service lists), `ServiceRegistry`, isolate

---

### F-605 — Queue & schedule workers

**Problem:** Laravel (and similar) need long-running `queue:work` / `schedule:work`; developers use ad-hoc terminals or custom systemd.

**Command surface:**

```bash
valet queue start|stop|restart|status [--site=] [--queue=] [--tries=] [--timeout=]
valet schedule start|stop|restart|status [--site=]
valet worker list [--json]
valet worker logs [name] [--follow]
```

**Implementation:**

1. Detect framework worker commands via driver hooks (extend F-201 driver API):

```php
public function workerCommands(string $sitePath): array
{
    return [
        'queue' => 'php artisan queue:work --verbose --tries=1',
        'schedule' => 'php artisan schedule:work',
    ];
}
```

2. Create **user systemd units** (or service-manager equivalent):

   - `valet-worker@{site}-queue.service`
   - `valet-worker@{site}-schedule.service`

3. Run under site PHP binary (`which-php`).
4. Logs → `~/.config/valet/Log/workers/{site}-{name}.log` + `valet worker logs`.

**Non-Laravel:**

- Symfony Messenger / custom: allow `.valet/workers.json` override:

```json
{
  "queue": ["php", "bin/console", "messenger:consume", "async"],
  "schedule": null
}
```

**Safety:**

- Units are user-scope by default (no root)
- `stop` on `valet sleep` if profile says so (integration with F-604)
- Never start workers during `valet install`

**Files:** `cli/Valet/Worker.php`, stubs for systemd user units, driver hooks, tests

**Acceptance criteria:**

- `valet queue start` inside a Laravel app creates and starts a user unit
- Survives logout only if lingering enabled — documented
- `worker list --json` schema documented (F-102)
- Non-Laravel override via `.valet/workers.json` works

**Effort:** L (1–2 weeks)  
**Depends on:** F-101, F-201 driver hooks, F-604 (soft integration)

---

### F-607 — `valet bench`

**Problem:** Hard to tell whether slowness is Nginx, PHP, app boot, or DNS.

**Command surface:**

```bash
valet bench [site] [--requests=20] [--path=/] [--json] [--warmup=2]
```

**Metrics (local only):**

| Metric | Method |
| --- | --- |
| DNS resolve | resolve `site.test` timing |
| TCP connect | port 80/443 |
| TLS handshake | if secured |
| TTFB | first byte |
| Total | full small GET |
| PHP ping | optional internal script hit if Valet can inject `/__valet/ping` (optional, secure) |

**Output (human):**

```
Site: my-app.test (https)
DNS        2.1 ms
Connect    0.4 ms
TLS        3.2 ms
TTFB      28.0 ms  (p50)  41.0 ms (p95)
Total     29.1 ms  (p50)
```

**Implementation:** `cli/Valet/Bench.php` using curl multi or PHP streams; no new deps preferred.

**Acceptance criteria:**

- Exit 0 always unless site unreachable (exit 1)
- `--json` includes percentiles and timestamps
- Does not follow external redirects off the Valet domain

**Effort:** S–M (3 days)  
**Depends on:** F-101 (optional site resolve)

---

## 6. Phase 6C — Debug & Share

### F-608 — Webhook / request catcher

**Problem:** Mailpit covers email; Stripe/GitHub/local mobile webhooks still need ngrok + throwaway endpoints.

**Command surface:**

```bash
valet webhook install|uninstall|start|stop|status|open
valet addon enable webhook   # alias path via F-404
```

**Behavior:**

- Small PHP or static+PHP catcher served at `https://hooks.test` (or `webhooks.<domain>`)
- Persist last N requests (headers, body, method, path) under `~/.config/valet/webhooks/`
- UI: list + detail + “replay URL” copy
- Optional forward rules in config: match path → `http://127.0.0.1:8000/...`

**Implementation options (pick in spike):**

| Option | Pros | Cons |
| --- | --- | --- |
| A. Single-file PHP app behind Valet | No new binary | Must secure storage |
| B. Vendor webhook.site-like binary | Fast | Supply-chain / size |
| **Recommended:** A | Fits Valet PHP world | — |

**Security:**

- Bound to Valet DNS only; document that `share` exposes it publicly
- Truncate bodies &gt; 1 MB; redact `Authorization` in UI by default

**Files:** `cli/Valet/WebhookCatcher.php`, `cli/templates/webhook/`, Nginx proxy stub, tests

**Acceptance criteria:**

- POST to `https://hooks.test/stripe` appears in UI within 1s
- `valet webhook open` launches browser
- Data directory mode `0700`

**Effort:** M–L (1 week)  
**Depends on:** F-404 addons pattern, SiteProxy

---

### F-609 — `valet tinker` / framework REPL

**Problem:** Developers forget which PHP binary / bootstrap to use for a REPL.

**Command surface:**

```bash
valet tinker [--site=]
valet repl [--site=]     # alias
```

**Resolution order:**

1. Laravel → `php artisan tinker` if `artisan` + psy/tinker available
2. Symfony → `bin/console psysh` or `bin/console` if configured
3. Fallback → `psysh` with autoload if present
4. Else → plain `php -a` with site PHP binary

**Driver hook:**

```php
public function replCommand(string $sitePath): ?array; // argv
```

**Files:** extend drivers + `cli/Valet/Repl.php`, tests with stubs

**Acceptance criteria:**

- Inside Laravel app, `valet tinker` opens Tinker with isolated PHP
- Clear error if REPL deps missing (`composer require laravel/tinker`)

**Effort:** S (2 days)  
**Depends on:** F-101, `php` command path

---

### F-610 — Multi-provider share (Cloudflare Tunnel & friends)

**Problem:** `share` is ngrok-only; auth and corporate networks often block it.

**Command surface:**

```bash
valet share [--provider=ngrok|cloudflared|localrun] [--site=]
valet share:provider [ngrok|cloudflared|localrun]
valet fetch-share-url [--provider=]
valet share:auth ...   # provider-specific
```

**Providers:**

| Provider | Binary | Notes |
| --- | --- | --- |
| `ngrok` | existing | Default for compatibility |
| `cloudflared` | optional install | `cloudflared tunnel --url http(s)://site.test` |
| `localrun` | SSH-based | Zero install if `ssh` works; best-effort |

**Architecture:**

```
ShareManager
  ├─ NgrokProvider (wrap existing Ngrok)
  ├─ CloudflaredProvider
  └─ LocalRunProvider
```

**Config:** `share.provider` in `config.json`; profile may override.

**Acceptance criteria:**

- `valet share --provider=cloudflared` works when binary present
- Missing binary → install instructions (optional download helper like Ngrok)
- `fetch-share-url` works per provider or explains N/A
- Default remains ngrok (no breaking change)

**Effort:** M–L (1 week)  
**Depends on:** existing `Ngrok`, F-202 (optional profile key)

---

## 7. Phase 6D — Multi-project & Caches

### F-611 — Workspace / monorepo map

**Problem:** API + SPA + admin are three Valet sites; developers start/secure/link them separately.

**Command surface:**

```bash
valet workspace init
valet workspace add [path] [--name=]
valet workspace remove [name]
valet workspace list [--json]
valet workspace up [--secure] [--isolate]
valet workspace down
valet workspace status
```

**Storage:** `.valet/workspace.json` (committable):

```json
{
  "name": "acme",
  "sites": [
    { "name": "api", "path": "./apps/api", "php": "8.3", "secure": true },
    { "name": "web", "path": "./apps/web", "php": "8.3", "secure": true },
    { "name": "admin", "path": "./apps/admin", "php": "8.2" }
  ],
  "services": ["redis", "mailpit"]
}
```

**`workspace up`:** for each site → link (if needed) → optional isolate/secure → start shared services → optional wake.

**`workspace down`:** sleep sites or stop workspace-scoped workers (integrate F-604/F-605).

**Files:** `cli/Valet/Workspace.php`, schema in `docs/schemas/`, tests

**Acceptance criteria:**

- One command brings up all sites in the map
- Invalid paths fail with actionable errors
- JSON schema validated

**Effort:** M (5–6 days)  
**Depends on:** F-202, F-601 (soft), F-604 (soft)

---

### F-612 — SQLite first-class path

**Problem:** Throwaway apps and tests often want SQLite; Valet DX is MySQL/Postgres-centric.

**Command surface:**

```bash
valet db:sqlite [name] [--path=database/database.sqlite] [--env]
valet db:sqlite:reset [-y]
```

**Behavior:**

1. Create sqlite file under project (default Laravel path).
2. If `--env`: set `DB_CONNECTION=sqlite`, `DB_DATABASE` absolute path; comment or clear unused MySQL keys carefully (backup `.env`).
3. Ensure file permissions for PHP-FPM user.
4. Document PHP `pdo_sqlite` requirement; `diagnose` / `health` check extension.

**Files:** `cli/Valet/Sqlite.php` or methods on DatabaseSetup (F-204), tests

**Acceptance criteria:**

- Fresh Laravel project reaches migrate-ready SQLite via one command
- Does not delete MySQL databases
- `health` can report `pdo_sqlite` missing

**Effort:** S–M (3 days)  
**Depends on:** F-203, F-204 (soft)

---

### F-613 — Composer / npm cache awareness

**Problem:** Slow installs feel like “Valet is slow” when the real issue is cold caches or duplicated vendor downloads.

**Command surface:**

```bash
valet cache status [--json]
valet cache path
valet cache clear [--composer] [--npm] [--valet] [-y]
valet cache doctor
```

**Behavior:**

- Detect Composer cache dir (`composer config cache-dir`) using site PHP
- Detect npm/pnpm/yarn cache if binaries exist
- Show sizes via `du`
- `cache doctor`: recommend `COMPOSER_PROCESS_TIMEOUT`, mirror issues, disk full
- `clear --valet`: only Valet-managed temps (tune backups, webhook bodies older than N days, shell-hook cache) — **not** global Composer unless explicitly `--composer`

**Acceptance criteria:**

- Safe default: `cache clear` without flags only clears Valet temps
- `--json` for scripting
- Docs warn that `--composer` / `--npm` affect user-global caches

**Effort:** S (2–3 days)  
**Depends on:** F-101 (site composer), F-501 (soft for npm)

---

## 8. Cross-Cutting Concerns

### Driver / profile extensions

Add optional hooks used by multiple Phase 6 features:

```php
// ValetDriver (optional overrides)
public function initCommands(string $sitePath): array;
public function envKeys(string $sitePath): array;
public function workerCommands(string $sitePath): array;
public function replCommand(string $sitePath): ?array;
public function viteDetect(string $sitePath): bool;
```

Profile schema additions (F-202):

```json
{
  "php": "8.3",
  "vite": { "enabled": true, "port": 5173 },
  "workers": { "queue": true, "schedule": true },
  "sleep": { "idle_minutes": 45 },
  "share_provider": "cloudflared",
  "tune": "fast"
}
```

### JSON / API

Extend API v1 resources (F-503):

| Resource | New fields / endpoints |
| --- | --- |
| `sites` | `asleep`, `vite`, `workers` |
| `workspace` | full workspace document |
| `bench` | last run summary (optional) |
| `cache` | composer/npm/valet sizes |

### Testing strategy

| Layer | Coverage |
| --- | --- |
| Unit | Every new class; stub systemd / nginx writes via `Filesystem` mocks |
| CLI | Command registration in `CliTest` |
| Integration | Optional: Vite apply renders expected Nginx; clone with local git fixture |
| Manual | Laravel + Vite HMR checklist in docs |

### Documentation

- [ ] `docs/commands.md` — all new commands
- [ ] `docs/vite.md` — dedicated HMR troubleshooting
- [ ] `docs/workers.md` — user systemd lingering notes
- [ ] `README.md` — feature table + shell-hook snippet
- [ ] `CHANGELOG.md` — per release
- [ ] Schema updates under `docs/schemas/v1/`

---

## 9. Versioning

| Release | Scope |
| --- | --- |
| **3.1.0** | F-601 clone, F-609 tinker, F-612 sqlite, F-613 cache |
| **3.2.0** | F-602 shell-hook, F-606 tune, F-607 bench |
| **3.3.0** | F-603 vite, F-610 share providers |
| **3.4.0** | F-605 workers, F-604 sleep/wake |
| **3.5.0** | F-608 webhooks, F-611 workspace + polish |

Rationale: ship quick wins first; put Vite + share next (high daily pain); workers/sleep need more ops care; workspace/webhooks crown the multi-project story.

---

## 10. Risk Register

| Risk | Mitigation |
| --- | --- |
| Shell hook slows every `cd` | Warm cache; &lt;50ms budget; easy disable |
| Vite Nginx rules break production-like configs | Site-local snippets; `vite remove`; `--dry-run` |
| User systemd lingering confusion | Docs; `worker status` explains inactive+dead |
| Sleep stops shared redis too early | Refcount against awake profiles/workspaces |
| Cloudflare binary trust/supply chain | Same pattern as Ngrok; checksum when available |
| Webhook catcher becomes open relay via `share` | Redaction; docs warning; local-only default |
| `tune fast` hides code bugs via stale OPcache | `dev` default; timestamps validate in `dev` |
| `clone --init` too magical | Explicit flags; profile-based path preferred |
| Workspace paths differ per machine | Relative paths in JSON; document |

---

## 11. Suggested Implementation Order

1. **F-609 tinker** + **F-612 sqlite** + **F-613 cache** — small, immediate DX  
2. **F-601 clone** — compounds F-201 init  
3. **F-602 shell-hook** + **F-606 tune** + **F-607 bench** — everyday speed  
4. **F-603 vite** — largest daily pain for modern apps  
5. **F-610 share providers** — unblocks demos when ngrok fails  
6. **F-605 workers** → **F-604 sleep** — process management + RAM  
7. **F-608 webhooks** + **F-611 workspace** — platform completeness  

---

## 12. Definition of Done (Phase 6)

- All commands in this document documented with examples
- Unit tests for each new class; no regressions in link/secure/isolate/share
- Shell hook tested on bash and zsh at minimum
- Vite checklist verified on one Ubuntu and one Fedora/Arch machine
- Workers use user systemd (or SM abstraction) without requiring root
- `composer test`, `composer stan`, `composer cs:check` green
- CHANGELOG entries for 3.1.0–3.5.0

---

## 13. Feature Index (quick reference)

| ID | Command / feature | Phase | Effort | Primary value |
| --- | --- | --- | --- | --- |
| ID | Command / feature | Phase | Effort | Primary value |
| --- | --- | --- | --- | --- |
| F-601 | `valet clone` ✅ 3.1.0 | 6A | M | Faster onboarding |
| F-602 | `valet shell-hook` | 6A | M | Correct PHP on cd |
| F-603 | `valet vite` | 6A | L | HMR + HTTPS |
| F-606 | `valet tune` | 6A | M | Faster PHP responses |
| F-604 | `valet sleep` / `wake` / `idle` | 6B | L | Lower RAM |
| F-605 | `valet queue` / `schedule` / `worker` | 6B | L | Background jobs |
| F-607 | `valet bench` | 6B | S–M | Measure TTFB |
| F-608 | `valet webhook` | 6C | M–L | Webhook debugging |
| F-609 | `valet tinker` ✅ 3.1.0 | 6C | S | Instant REPL |
| F-610 | multi-provider `share` | 6C | M–L | Reliable demos |
| F-611 | `valet workspace` | 6D | M | Monorepos |
| F-612 | `valet db:sqlite` ✅ 3.1.0 | 6D | S–M | Throwaway DBs |
| F-613 | `valet cache:*` ✅ 3.1.0 | 6D | S | Faster installs |

---

*Created: 2026-09-04*  
*Companion to [roadmap-local-dev.md](roadmap-local-dev.md)*
