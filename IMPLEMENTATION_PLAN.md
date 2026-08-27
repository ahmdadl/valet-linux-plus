# Valet Linux+ — Local Dev Enhancement Plan

**Version:** 2.1.0-dev  
**Date:** 2026-08-27  
**Status:** In Progress

---

## 1. Goals

Make Valet Linux+ the most complete zero-config local dev stack for PHP/Laravel on Linux by closing the gaps with modern local-dev expectations: database flexibility, service observability, PHP DX, and tooling ergonomics — without adding Docker or heavy dependencies.

## 2. Scope & Principles

- **Keep the 7 MB RAM promise:** services are opt-in, not auto-installed.
- **No Docker requirement:** every feature uses native packages or simple binaries.
- **Backward compatible:** existing `install`, `park`, `link`, `secure`, `isolate`, `db:*` commands unchanged.
- **Tested:** every new class gets a `tests/Unit/*Test.php`.
- **One `app.php` integration pass** to avoid merge conflicts across lanes.

## 3. Work Breakdown

### Phase 1 — Foundation (blocked by nothing, unblocks all)

| ID | Feature | Files | Risk |
|----|---------|-------|------|
| F-01 | **Service Registry** — deduplicate `start`/`restart`/`stop`/`status` switch blocks in `app.php` into a `Valet\ServiceRegistry` | `cli/Valet/ServiceRegistry.php` (new), `cli/app.php` (refactor) | Low |

### Phase 2 — Parallel DX Lanes (can run concurrently after F-01, or concurrently creating classes and integrating app.php last)

| ID | Feature | New / Modified Files | Command Surface | Notes |
|----|---------|----------------------|-----------------|-------|
| F-02 | **Diagnose** | `cli/Valet/Diagnose.php` (new), `cli/app.php` | `valet diagnose [--json]` | Collects OS, distro, PM, SM, PHP versions, Nginx config, DnsMasq, service status, config.json, site counts → Writer::table or JSON |
| F-03 | **PostgreSQL Support** | `cli/Valet/Postgres.php` (new, mirrors `Mysql.php`), `cli/app.php` | `valet pg:list`, `pg:create`, `pg:drop`, `pg:reset`, `pg:import`, `pg:export`, `pg:configure`, `valet install --with-pgsql` | Uses PDO pgsql, `psql`/`pg_dump`, `--defaults` pattern via `PGPASSWORD` env + temp file; reuses package/service manager |
| F-04 | **Xdebug Toggle** | `cli/Valet/PhpFpm.php` (extend), `cli/app.php` | `valet xdebug on\|off [--version=8.3]` , `valet xdebug status` | Scans `cli/stubs` fpm conf dirs + `/etc/php/*/mods-available/xdebug.ini`; enables via `phpenmod`/`phpdismod` fallback to symlink; restarts FPM |
| F-05 | **Logs + Mail + Enhanced Status** | `cli/Valet/Valet.php` or `cli/Valet/Log.php` (new), `cli/app.php`, `cli/Valet/Nginx.php` (log path) | `valet log [nginx\|php\|mysql\|mailpit\|redis] [--tail=50]`, `valet mail`, `valet status` (enhanced) | `log` tails `VALET_HOME_PATH/Log/nginx-error.log` + `journalctl`; `mail` does `xdg-open https://mails.<domain>`; `status` unified table |
| F-06 | **Atom Removal + Minor Cleanup** | `cli/Valet/DevTools.php`, `cli/app.php` | remove `valet atom` | Atom discontinued |

**Tier 2 — Next Cycle (scoped per user request):** 
- `valet backup` / `valet restore` — config + Nginx + certs + DB dumps (Mysqldump + pg_dump)
- **Configurable Services** — extensible `ServiceRegistry` via `config.json` (`services` key) + built-in templates (Minio as first class). Generic definition: `{name, package, service, port, proxyHost, healthCheck}` auto-wired to `start/restart/stop/status` and `valet proxy`/`valet status`/`Dashboard`.

**Dashboard update:** Valet dashboard (`valet.test` landing) completed as `feat(dashboard)` 13992e1 — serves `valet.<domain>` / `dashboard.<domain>` via `server.php` + `Dashboard.php`.

**Removed from Tier 2:** scaffold, Meilisearch, Node/NVM, Cloudflare Tunnel (Minio restored as configurable-services template).

## 4. Dependency Graph

```
F-01 (Service Registry)
  ├─→ F-02 (Diagnose) ─┐
  ├─→ F-03 (Postgres)  ─┤
  ├─→ F-04 (Xdebug)    ─┤─→ Integration pass (app.php) ─→ Verify
  └─→ F-05 (Logs/Mail) ┘
       F-06 (Atom) ────────────┘
```

Integration pass merges all `app.php` command registrations in one commit to avoid conflicts.

## 5. Detailed Specs

### F-01 Service Registry

- New class `Valet\ServiceRegistry` with `MAP = ['nginx'=>Nginx::class, 'php'=>PhpFpm::class, 'mailpit'=>Mailpit::class, 'dnsmasq'=>DnsMasq::class, 'mysql'=>Mysql::class, 'redis'=>ValetRedis::class, 'postgres'=>Postgres::class]`.
- Methods `start(array $services)`, `restart(array $services)`, `stop(array $services)`, `status()`, `resolve(string $name): object`.
- `app.php` `start`/`restart`/`stop` closures collapse from ~40 lines each to `ServiceRegistry::restart($services)` etc.

### F-02 Diagnose

- `Diagnose::run(bool $json)` gathers:
  - OS: `cat /etc/os-release`, kernel `uname -r`
  - Package manager + service manager class names
  - PHP: `PHP_VERSION`, `PhpFpm::getCurrentVersion()`, isolated sites
  - Nginx: `nginx -t` result, `VALET_HOME_PATH/Nginx/*` count
  - DNS: `Configuration::get('domain')`, DnsMasq status
  - Services: each `ServiceRegistry::status()` capture
  - Paths: `Configuration::get('paths')`, links, proxies, secured
- Output: human table (default) or `json_encode` with `--json`.
- No destructive actions, no sudo.

### F-03 PostgreSQL

- Mirror `Mysql.php` API but for Postgres: PDO `pgsql:host=localhost`, `CREATE DATABASE`, `DROP DATABASE`, `SELECT datname FROM pg_database`.
- `systemDatabases = ['postgres','template0','template1']`.
- Export via `PGPASSWORD` env + `pg_dump`, import via `psql`.
- `install(bool $withPostgres)` called from `app.php install --with-pgsql`.
- Config key `pgsql` with `user`/`password` stored in `config.json` (same pattern as `mysql`).

### F-04 Xdebug Toggle

- `PhpFpm::isXdebugEnabled(?string $version): bool` — checks `php -m` and `conf.d` scan.
- `PhpFpm::enableXdebug(?string $version)`, `disableXdebug(?string $version)` — uses `phpenmod`/`phpdismod` if available, else symlink `/etc/php/<ver>/mods-available/xdebug.ini` to `/etc/php/<ver>/fpm/conf.d/` and `cli/conf.d/`.
- Commands: `valet xdebug on` / `off` / `status`, with `--version` flag.
- Restarts FPM after toggle.

### F-05 Logs / Mail / Status

- `Valet::openMailpit()` → `xdg-open https://mails.<domain>` (or `http` if not secured).
- `Log::tail(string $service, int $lines)` — maps service to log file: `nginx`→`VALET_HOME_PATH/Log/nginx-error.log`, `php`→`journalctl -u php*`, `mysql`→`journalctl -u mysql`, fallback to `tail -n`.
- Enhanced `status`: single `Writer::table` showing service, installed?, enabled?, active? + PHP version + domain + port + site counts.

### F-06 Atom Removal

- Remove `DevTools::ATOM` constant, `valet atom` command, and `atom` case handling.

## 6. Verification

- `composer test` — all 17 existing unit tests must stay green; new tests for `ServiceRegistry`, `Diagnose`, `Postgres`, `PhpFpm::xdebug*`, `Log`.
- `composer stan` — PHPStan level as configured.
- `composer cs:check` — PHP-CS-Fixer.
- Manual smoke: `valet diagnose`, `valet status`, `valet xdebug status`, `valet log nginx --tail=5`, `valet mail --help`.

## 7. Rollout

- Branch: `feat/local-dev-enhancements`
- Commits: `feat: service registry`, `feat: diagnose`, `feat: postgres`, `feat: xdebug`, `feat: logs/mail/status`, `chore: remove atom`
- Bump version to `2.1.0` after integration.
