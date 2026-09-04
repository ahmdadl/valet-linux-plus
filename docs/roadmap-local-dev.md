# Valet Linux+ — Local Dev Feature Roadmap

**Version target:** 2.3.0 → 3.0.0  
**Date:** 2026-09-04  
**Status:** In progress — Phases 0–5 ✅ (F-501–F-503); next Phase 6 DX

This document is the implementation plan for the next wave of local-development features. It assumes the existing command surface documented in [commands.md](commands.md) and the architecture under `cli/Valet/`.

---

## 1. Goals

- Reduce time from `git clone` to a working local site.
- Make failures diagnosable and often self-healing.
- Expose machine-readable output for scripting, IDE plugins, and the dashboard.
- Stay native: no Docker requirement, opt-in services, backward-compatible commands.
- Every new class gets unit tests; integration smoke tests cover the happy path.

## 2. Principles

| Principle | Constraint |
| --- | --- |
| **Backward compatible** | Existing `install`, `park`, `link`, `secure`, `isolate`, `db:*`, `pg:*` unchanged |
| **Opt-in heaviness** | New services (Node, Meilisearch, etc.) are never auto-installed |
| **Safe by default** | Destructive actions require confirmation; `--force` / `-y` to skip |
| **Single integration pass** | Register all new commands in `cli/app.php` in one commit per phase |
| **JSON where useful** | `--json` on introspection commands; stable schema documented |

## 3. Prerequisites (Phase 0)

Complete before feature work. Items below; Phase 0 work is tracked in this document.

| ID | Task | Files | Outcome |
| --- | --- | --- | --- |
| P-01 | Fix `ServiceRegistry::start()` calling `restart` instead of `start` | `cli/Valet/ServiceRegistry.php` | `valet start` is idempotent |
| P-02 | Resolve PHPStan errors; regenerate baseline | `cli/Valet/SiteIsolate.php`, `phpstan-baseline.neon` | `composer stan` passes |
| P-03 | Sync version across README, `app.php`, CHANGELOG, docs | `README.md`, `cli/app.php`, `CHANGELOG.md`, `docs/commands.md` | Single source of truth |
| P-04 | Command-injection audit | `cli/Valet/CommandLine.php`, `Log.php`, all `passthru`/`exec` call sites | User input always escaped |
| P-05 | Make `Diagnose` service-manager aware | `cli/Valet/Diagnose.php` | Works on non-systemd distros |
| P-06 | Introduce `ServiceInterface` (optional refactor) | `cli/Valet/Contracts/ServiceInterface.php`, `ServiceRegistry.php` | Custom services first-class |

**Definition of done (Phase 0):** `composer test`, `composer stan`, `composer cs:check` green on PHP 8.2–8.4.

---

## 4. Dependency Graph

```
Phase 0 (Prerequisites)
  │
  ├─→ Phase 1 — Foundation (JSON schema, project context, health)
  │     ├─→ Phase 2 — Project onboarding (init, profiles, env)
  │     ├─→ Phase 3 — Reliability (doctor --fix, certs, logs)
  │     └─→ Phase 4 — Dashboard & tooling (UI, DB GUI, addons)
  │
  └─→ Phase 5 — Advanced (Node, snapshots, machine API v1)
        │
        └─→ Phase 6 — Day-to-day DX (clone, Vite, workers, sleep, …)
              See [roadmap-local-dev-phase6.md](roadmap-local-dev-phase6.md)
```

Phases 2–4 can overlap after Phase 1 lands; Phase 5 depends on stable JSON schemas from Phase 1. Phase 6 depends on Phase 5 foundation pieces (profiles, init, Node, API v1) and is specified separately.

---

## 5. Phase 1 — Foundation

Shared infrastructure used by later features.

### F-101 — Project context resolver

**Problem:** Many commands duplicate “what is the current project?” logic (CWD, linked site, driver, `.env`).

**Command surface:** Internal only (no new CLI command).

**Implementation:**

| Component | Responsibility |
| --- | --- |
| `cli/Valet/ProjectContext.php` | Resolve site name, path, driver, framework, `.env` path |
| `cli/Valet/ProjectDetector.php` | Detect Laravel, Symfony, WordPress, Bedrock, etc. from filesystem |
| Facade + container binding | Wire into existing commands |

**Files:** `ProjectContext.php`, `ProjectDetector.php`, `cli/Valet/Facades/ProjectContext.php`, `tests/Unit/ProjectContextTest.php`

**Acceptance criteria:**
- `ProjectContext::fromCwd()` returns site name, URL, driver class, framework enum
- Used by at least `open`, `which`, and `db:create` (refactor, no behavior change)

**Effort:** M (3–5 days)

---

### F-102 — JSON output schema

**Problem:** `diagnose --json` and `status` output shapes are ad hoc; scripts and dashboard need stability.

**Command surface:**

```bash
valet schema [--command=diagnose|status|env|health]
```

**Implementation:**

| Component | Responsibility |
| --- | --- |
| `cli/Valet/JsonSchema.php` | Versioned schema definitions (`schema_version: 1`) |
| Extend `Diagnose`, `ServiceRegistry::status()` | Emit `schema_version` field |
| Document schemas in `docs/json-schema.md` | Human + machine reference |

**Acceptance criteria:**
- Schema version bump only on breaking changes
- Unit test validates sample output against schema

**Effort:** S (2 days)

---

### F-103 — Service health checks

**Problem:** `status` shows systemd state, not whether MySQL accepts connections or Nginx serves HTTP.

**Command surface:**

```bash
valet health [--json] [--service=nginx|php|mysql|postgres|redis|mailpit|all]
```

**Implementation:**

| Service | Check |
| --- | --- |
| Nginx | HTTP GET `http://127.0.0.1:{port}/` or `nginx -t` |
| PHP-FPM | Socket exists + `php -v` via isolated FPM |
| MySQL | PDO connect with config credentials |
| PostgreSQL | PDO `pgsql` connect |
| Redis | `redis-cli ping` or PHP Redis extension |
| Mailpit | HTTP GET health endpoint |
| Custom | Use `healthCheck` URL from `config.json` |

**Files:** `cli/Valet/Health.php`, `cli/Valet/Facades/Health.php`, `tests/Unit/HealthTest.php`, `cli/app.php`

**Acceptance criteria:**
- Exit code 1 if any requested service fails health check
- `--json` output includes `healthy`, `latency_ms`, `message` per service

**Effort:** M (4 days)

---

## 6. Phase 2 — Project Onboarding

### F-201 — `valet init`

**Problem:** New projects require manual steps: database, `.env`, migrations, isolation.

**Command surface:**

```bash
valet init [--db] [--migrate] [--composer] [--isolate=8.3] [--secure] [--force]
```

**Flow:**

1. `ProjectContext` detects framework and driver.
2. Optional: `db:create` / `pg:create` using directory name.
3. Optional: copy `.env.example` → `.env`, inject `DB_*` / `DATABASE_URL` from Valet config.
4. Optional: `composer install` via site's PHP.
5. Optional: run framework-specific migrate (`artisan migrate`, etc.) behind driver hook.
6. Optional: `isolate` + `secure`.

**Driver extension:**

Add optional methods to `ValetDriver`:

```php
public function initCommands(string $sitePath): array; // e.g. ['migrate' => 'php artisan migrate --force']
public function envKeys(string $sitePath): array;      // DB_* overrides
```

**Files:** `cli/Valet/Init.php`, driver base + `LaravelValetDriver`, `BedrockValetDriver`, `WordPressValetDriver`, `tests/Unit/InitTest.php`

**Acceptance criteria:**
- Idempotent: second run warns, skips existing DB unless `--force`
- Works for Laravel and plain PHP (minimal path)
- Documented in `docs/commands.md`

**Effort:** L (1–2 weeks)

**Depends on:** F-101

---

### F-202 — Per-project profiles

**Problem:** PHP version, DB type, and required services vary per project; today scattered across `.valetphprc`, manual isolation, and memory.

**Command surface:**

```bash
valet profile list
valet profile show [name]
valet profile use [name] [--apply]
valet profile save [name]
valet profile delete [name]
```

**Storage:** `.valet/profile.json` in project root (git-committable) or `~/.config/valet/profiles/{name}.json` for global templates.

**Schema:**

```json
{
  "php": "8.3",
  "secure": true,
  "database": { "driver": "mysql", "name": "my_app" },
  "services": ["redis", "mailpit"],
  "node": "20",
  "isolate": true
}
```

**Apply behavior (`--apply`):** run `isolate`, `secure`, start listed services, optionally `db:create`.

**Files:** `cli/Valet/Profile.php`, `tests/Unit/ProfileTest.php`

**Acceptance criteria:**
- `valet profile use laravel-app --apply` configures site end-to-end
- Profile validated against JSON schema

**Effort:** M (5 days)

**Depends on:** F-101, F-201 (partial — can ship save/show before init integration)

---

### F-203 — `valet env`

**Problem:** Developers manually hunt for DB credentials, site URL, Mailpit URL.

**Command surface:**

```bash
valet env [--json] [--export=dotenv|shell|json]
valet env --print-db-url
```

**Output (human):**

```
SITE_URL=https://my-app.test
PHP_VERSION=8.3.12
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_app
DB_USERNAME=valet
DB_PASSWORD=...
REDIS_URL=redis://127.0.0.1:6379
MAIL_URL=https://mails.test
```

**Files:** `cli/Valet/Environment.php`, `tests/Unit/EnvironmentTest.php`

**Acceptance criteria:**
- Reads project `.env` when present, merges with Valet config defaults
- `--export=shell` emits `export KEY=value` for eval
- `--json` uses F-102 schema

**Effort:** S (2–3 days)

**Depends on:** F-101, F-102

---

### F-204 — Database-per-project automation

**Problem:** `db:create` exists but doesn't wire `.env`, seed, or refresh workflows.

**Command surface:**

```bash
valet db:setup [--seed] [--pg]
valet db:refresh [--seed] [--pg] [-y]
```

**Behavior:**

| Command | Actions |
| --- | --- |
| `db:setup` | create DB → update `.env` → optional migrate/seed via driver |
| `db:refresh` | `db:reset` → migrate → optional seed |

**Files:** extend `cli/Valet/Mysql.php`, `Postgres.php`, or new `cli/Valet/DatabaseSetup.php`

**Acceptance criteria:**
- Mirrors Laravel `migrate:fresh --seed` ergonomics without requiring Artisan globally
- Supports both MySQL and PostgreSQL via `--pg`

**Effort:** M (4 days)

**Depends on:** F-101, F-201 (driver hooks)

---

## 7. Phase 3 — Reliability & Observability

### F-301 — `valet doctor --fix`

**Problem:** `diagnose` reports issues but doesn't repair them.

**Command surface:**

```bash
valet doctor [--fix] [--json] [--dry-run]
```

**Fix actions (safe, ordered):**

| Issue | Fix |
| --- | --- |
| Nginx config invalid | Regenerate configs, `nginx -t`, restart |
| DnsMasq not resolving | Rewrite config, restart dnsmasq |
| Service inactive | `ServiceRegistry::start()` |
| Permission errors on `VALET_HOME_PATH` | `chown`/`chmod` (non-destructive paths only) |
| Expired/missing cert for secured site | Re-run `SiteSecure::secure()` |
| Port conflict | Report process using port; optional kill with `--force` |

**Files:** extend `cli/Valet/Diagnose.php` → rename or alias as `Doctor.php` with `fix()` method

**Safety:**
- `--dry-run` lists actions without executing
- Destructive fixes require `--force`
- Never uninstall or drop databases

**Acceptance criteria:**
- Fixes at least 5 common failure modes covered in `tests/Unit/DoctorTest.php` (mocked)
- Human output shows before/after per fix

**Effort:** L (1 week)

**Depends on:** P-05, F-103

---

### F-302 — Certificate management

**Problem:** `secure`/`unsecure` work but there's no visibility into cert expiry or trust issues.

**Command surface:**

```bash
valet trust [--check]
valet cert:list [--json]
valet cert:renew [site]
valet cert:info [site]
```

**Implementation:**

| Command | Behavior |
| --- | --- |
| `trust` | Install CA to system trust store (distro-specific: `update-ca-certificates`, etc.) |
| `cert:list` | All secured sites + expiry date |
| `cert:renew` | Regenerate cert if < 30 days to expiry or on demand |
| `cert:info` | OpenSSL parse of cert for one site |

**Files:** extend `cli/Valet/SiteSecure.php`, new `cli/Valet/Certificate.php`

**Acceptance criteria:**
- `cert:list --json` includes `expires_at`, `days_remaining`
- Document browser trust troubleshooting in docs

**Effort:** M (5 days)

---

### F-303 — Live log aggregation

**Problem:** `valet log` tails one service; debugging often needs Nginx + PHP + app together.

**Command surface:**

```bash
valet logs [--follow] [--services=nginx,php,mysql] [--tail=50] [--grep=pattern]
```

**Implementation:**
- Multiplex `tail -F` / `journalctl -f` with `[nginx]` prefixes
- Optional: detect Laravel log at `{project}/storage/logs/laravel.log` via `ProjectContext`
- Use `pcntl` if available; fallback to sequential tail

**Files:** extend `cli/Valet/Log.php`

**Acceptance criteria:**
- `--follow` streams until Ctrl+C
- Filters by service and grep pattern

**Effort:** M (4 days)

**Depends on:** F-101 (for app log path)

---

## 8. Phase 4 — Dashboard & Tooling

### F-401 — Enhanced dashboard

**Problem:** Dashboard is informational; no actions.

**Command surface:** Web UI at `valet.<domain>` (no new CLI except optional `dashboard --open` already exists).

**UI additions:**

| Action | Backend |
| --- | --- |
| Open site | link from `SiteLink` |
| Open Mailpit | existing `mail` command logic |
| Restart service | POST → internal API |
| View logs | tail last N lines |
| Switch PHP (global) | `PhpFpm::use()` |
| Site health | F-103 per linked site |

**Files:** `cli/templates/dashboard.html`, `cli/Valet/Dashboard.php`, optional lightweight `cli/Valet/DashboardApi.php`

**Acceptance criteria:**
- Read-only by default; mutating actions require confirmation in UI
- No new dependencies (vanilla JS or minimal inline)

**Effort:** L (1–2 weeks)

**Depends on:** F-103, F-102

---

### F-402 — Automatic project detection (command integration)

**Problem:** Detection logic should be transparent across commands.

**Scope:** Not a standalone command — enhance existing commands to use `ProjectContext`:

| Command | Enhancement |
| --- | --- |
| `open`, `which-php`, `php`, `composer` | Default to CWD project |
| `db:*`, `pg:*` | Default database name from project |
| `status` | Show current project row when in parked/linked dir |
| `env`, `init`, `profile` | Already depend on F-101 |

**Files:** `cli/app.php` command closures, `ProjectContext.php`

**Acceptance criteria:**
- Running commands inside a linked directory never requires repeating site name
- Regression tests for existing CLI behavior

**Effort:** M (3–5 days)

**Depends on:** F-101

---

### F-403 — Database GUI integration

**Problem:** Developers use external GUIs but must manually configure connections.

**Command surface:**

```bash
valet db:open [--gui=adminer|dbeaver|tableplus] [--pg]
valet db:url [--pg]
```

**Implementation:**
- `db:url` prints standardized connection string
- `db:open` launches Adminer via one-off Nginx proxy or opens `.dbeaver` connection file
- Ship Adminer as optional `valet addon enable adminer` (see F-404)

**Files:** `cli/Valet/DatabaseGui.php`, stub proxy config

**Acceptance criteria:**
- `db:open` works with at least one GUI (Adminer via browser is minimum)
- Never stores passwords in world-readable temp files

**Effort:** M (4 days)

**Depends on:** F-203

---

### F-404 — Local addon presets (MinIO, Meilisearch, etc.)

**Problem:** Custom services work but require manual JSON editing.

**Command surface:**

```bash
valet addon list
valet addon enable [name]
valet addon disable [name]
```

**Built-in templates (extend `service:add` templates):**

| Addon | Package | Proxy | Notes |
| --- | --- | --- | --- |
| `minio` | minio | `minio.test` | Already partially exists |
| `meilisearch` | meilisearch | `search.test` | Opt-in |
| `adminer` | — | `adminer.test` | Static PHP, no package |
| `mailpit` | — | — | Already built-in; expose as addon for consistency |

**Files:** extend `ServiceRegistry` templates, `cli/Valet/Addon.php`, `cli/stubs/addons/`

**Acceptance criteria:**
- `valet addon enable meilisearch` installs, proxies, starts, shows in `status`
- `disable` stops service and removes proxy, keeps data

**Effort:** M (5 days)

**Depends on:** P-06 (optional), F-103

---

## 9. Phase 5 — Advanced

### F-501 — Node.js version management

**Problem:** Full-stack projects need Node; Valet doesn't manage it.

**Command surface:**

```bash
valet node install [version]
valet node use [version]
valet node current
```

**Implementation options (pick one in spike):**

| Option | Pros | Cons |
| --- | --- | --- |
| A. Integrate `nvm` if present | Zero install for many devs | Requires nvm preinstalled |
| B. Bundle fnm binary | Self-contained | Download + maintain |
| C. Read `.nvmrc` / `.node-version` only | Minimal | No install capability |

**Recommended:** Option A with Option C fallback; document Option B for future.

**Profile integration:** F-202 `node` field triggers `valet node use` on `profile apply`.

**Files:** `cli/Valet/Node.php`, `tests/Unit/NodeTest.php`

**Acceptance criteria:**
- Reads `.nvmrc` in project root
- Does not install Node by default (`valet install` unchanged)

**Effort:** L (1 week)

**Depends on:** F-202

---

### F-502 — Project snapshots

**Problem:** Backup covers Valet home, not per-project state.

**Command surface:**

```bash
valet snapshot create [name] [--with-db] [--notes=]
valet snapshot list
valet snapshot restore [name] [--force]
valet snapshot delete [name]
```

**Snapshot contents:**

```
~/.config/valet/snapshots/{name}/
  manifest.json      # timestamp, project path, valet version
  profile.json       # copy of .valet/profile.json if exists
  database.sql.gz    # optional
  env.redacted       # .env with secrets masked
```

**Files:** `cli/Valet/Snapshot.php`, extends patterns from `Backup.php`

**Acceptance criteria:**
- Restore recreates DB and re-applies profile
- Snapshots are per-project, not full Valet backup

**Effort:** M (5 days)

**Depends on:** F-202, F-204, existing `Backup.php`

---

### F-503 — Machine-readable API v1

**Problem:** IDE extensions and scripts need a stable, documented interface.

**Command surface:**

```bash
valet api [--json] [resource]
# resource: sites | services | env | health | profiles
```

**Implementation:**
- Thin facade over F-102 schemas
- Document in `docs/api-v1.md`
- Commit JSON Schema files to `docs/schemas/v1/`

**Acceptance criteria:**
- No breaking changes within v1; deprecations warn for one minor release
- Example shell script in docs fetches all sites as JSON

**Effort:** S (2–3 days)

**Depends on:** F-102, F-103, F-203

---

## 10. Cross-Cutting Work

### Testing strategy

| Layer | Coverage |
| --- | --- |
| Unit | Every new class under `tests/Unit/` |
| CLI | Extend `tests/CliTest.php` for command registration |
| Integration | Docker smoke: Ubuntu + Fedora images running `install`, `link`, `init`, `health` |
| Schema | JSON schema validation tests |

### Documentation updates (each phase)

- [ ] `docs/commands.md` — full command reference
- [ ] `README.md` — feature summary table
- [ ] `CHANGELOG.md` — per release
- [ ] `docs/json-schema.md` — Phase 1
- [ ] `docs/api-v1.md` — Phase 5

### Versioning

| Release | Scope |
| --- | --- |
| **2.3.0** | Phase 0 + Phase 1 |
| **2.4.0** | Phase 2 |
| **2.5.0** | Phase 3 |
| **2.6.0** | Phase 4 |
| **3.0.0** | Phase 5 + API v1 stability guarantee |
| **3.1.0–3.5.0** | Phase 6 — see [roadmap-local-dev-phase6.md](roadmap-local-dev-phase6.md) |

---

## 11. Risk Register

| Risk | Mitigation |
| --- | --- |
| `init`/`doctor --fix` break projects | `--dry-run`, confirmations, idempotent operations |
| Framework detection wrong | Conservative defaults; explicit `--driver=` override |
| Node addon scope creep | Opt-in only; no `valet install` changes |
| Certificate trust varies by distro | Document per-distro; `--check` mode |
| Log multiplexing portability | Graceful fallback without `pcntl` |
| JSON schema breaking consumers | `schema_version` field; semver policy |

---

## 12. Suggested Implementation Order

Priority if shipping incrementally:

1. **Phase 0** — unblock quality gates  
2. **F-101 + F-103** — project context + health (enables everything else)  
3. **F-203 + F-301** — `valet env` + `doctor --fix` (immediate daily DX wins)  
4. **F-201 + F-204** — `init` + `db:setup`/`refresh` (onboarding)  
5. **F-202** — profiles (ties onboarding together)  
6. **F-303 + F-302** — logs + certs (debugging)  
7. **F-401 + F-404** — dashboard + addons (visibility)  
8. **F-501 + F-502 + F-503** — Node, snapshots, API (3.0.0)
9. **Phase 6** — day-to-day DX ([roadmap-local-dev-phase6.md](roadmap-local-dev-phase6.md))

---

## 13. Definition of Done (entire roadmap)

- All new commands documented in `docs/commands.md` with examples
- Unit test coverage for new classes
- `composer test`, `composer stan`, `composer cs:check` pass
- At least one Docker-based smoke test for `init` + `health`
- No regression in existing install/link/secure/isolate flows
- CHANGELOG entries for each release

---

*Created: 2026-09-04*  
*Phase 6 companion: [roadmap-local-dev-phase6.md](roadmap-local-dev-phase6.md)*
