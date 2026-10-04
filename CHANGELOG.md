# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.5.0] - 2026-10-04

### Fixed

- `valet --version` and every other place that printed the Valet version now resolve it dynamically — `git describe --tags` in a checkout, `Composer\InstalledVersions` for a tagged global install — so the number can no longer go stale. `README.md` and `docs/commands.md` no longer hard-code a version string.
- `CHANGELOG.md` had two `## [Unreleased]` sections and was missing entries for `v3.3.0`/`v3.4.0`; both are now properly recorded.

### Changed

- **README rewritten** — tighter intro, feature highlights, requirements table, quick-start, dashboard preview and a clearer "What's Different vs Upstream" section. See `README.md`.

## [3.4.0] - 2026-10-04

### Added

- **Site sleep / wake** — `valet sleep [site] [--all] [--services]` and `valet wake [site] [--all]` plus `valet idle` timer config. Sites that were isolated to their own PHP-FPM pool have that pool stopped when no other site needs it; `valet status [--json]` reports `sites_sleep` with per-pool RAM (`ServiceMemory`). New services `SiteSleep` / `ServiceMemory` and facades.
- **Real version lists in the dashboard** — `PhpFpm::installedPhpVersions()` discovers binaries on disk plus pinned config instead of a fixed range (which included versions like 8.9 that nobody has), `PhpFpm::isVersionServable()` reports whether the pool/socket actually exists, and `Node::installedVersions()` lists what `nvm` has. The PHP and Node panels now offer `make default` / `use` on versions that are actually present.
- **Dashboard quick-actions bar** — PHP switcher, Xdebug toggle, Trust CA, Restart All, and Health check in one row above the services grid.
- **Registered paths panel** — every watched `park`/`link` path with a `Forget` action, plus live refresh.
- **Health summary card, service log buttons, and site-menu extras** — copy-URL / open-in-browser on each site row, copy connection URL on each database row, and a per-service `Logs` shortcut that jumps to the log viewer.
- **Tab-panel spacing** — toolbars are now a padded header band so rows and inputs no longer sit flush against the card border.

### Changed

- `Dashboard.php` / `DashboardServer.php` expose the real installed PHP list to the template instead of the theoretical range.
- `SiteSleepTest` asserts behaviour (pool stopped / asleep flag set) rather than swapping a `Writer` mock it cannot intercept.

## [3.3.0] - 2026-09-19

### Fixed

- `valet php` / `valet composer` no longer fail with a cryptic `command not found` when the PHP binary cannot be resolved: `PhpFpm::getPhpExecutablePath()` falls back to the configured `fallback_binary`, then `/usr/bin/php` (or `/usr/bin/phpX.Y` for versioned lookups), and the `valet` wrapper exits with a clear hint (`valet isolate <version>` or a `.valetphprc` file) instead of executing an empty binary.

## [3.2.0] - 2026-09-04

### Added

- Always-on Adminer at `https://database.valet.<domain>` (installed with Valet like Mailpit); `valet database` shows URL and plugin paths.
- Adminer plugins: drop files into `~/.config/valet/database/plugins` and enable them in `plugins/enabled.php`.
- `valet shell-hook` — emit zsh/bash/fish hooks to auto-switch PHP on `cd` into Valet sites; `valet env --php-bin` for the resolver (F-602).
- `valet tune` — PHP-FPM performance presets (`dev`, `fast`, `debug`, `show`) with drop-in backups (F-606).
- `valet bench` — local DNS/connect/TLS/TTFB latency measurement with percentiles and `--json` (F-607).

### Changed

- Adminer is built-in (no longer opt-in-only via addon); `addon:enable adminer` still refreshes the install.

### Security

- **Removed** the CSRF-able `GET /?valet_action=restart&confirm=1&service=…` dashboard route. Dashboard mutations are now POST-only and require a loopback address, a same-origin `Origin`/`Referer`, a matching double-submit CSRF cookie plus `X-Valet-CSRF` header, and a server-checked confirmation that echoes the exact value being destroyed.
- The privileged helper never executes user-supplied shell text. It records the privileged commands Valet *asks* for, re-validates every one of them against a single allowlist, and aborts the entire request if any command is not on it. Valet code itself always runs as the unprivileged install user.
- Read-only service queries (`systemctl is-enabled`, `is-active`, `status`) are explicitly excluded from the privileged allowlist so they run as the user; deferring them would have inverted Valet's own enable/disable decisions.

## [3.1.0] - 2026-09-04

### Added

- `valet clone` — clone a git repository and optionally link / init / secure / isolate / open (F-601).
- `valet tinker` / `valet repl` — framework REPL with the site PHP binary (Laravel tinker, Symfony console, psysh, or `php -a`) (F-609).
- `valet db:sqlite` / `db:sqlite:reset` — first-class SQLite file creation and `.env` wiring; `health` reports `pdo_sqlite` (F-612).
- `valet cache:status|path|clear|doctor` — Composer/npm/Valet cache awareness; default clear is Valet temps only (F-613).
- `valet node:current` / `node:install` / `node:use` — Node version helpers via nvm (reads `.nvmrc` / `.node-version`; no Node install without nvm).
- `valet snapshot:*` — per-project snapshots (`create`, `list`, `restore`, `delete`) with optional DB dump and redacted `.env`.
- `valet api` — machine-readable API v1 (`sites`, `services`, `env`, `health`, `profiles`); see `docs/api-v1.md`.
- `valet addon:*` — enable/disable local presets (minio, meilisearch, adminer, mailpit).
- `valet db:url` / `db:open` — connection URL and Adminer GUI helper (password never written to temp files).
- Dashboard enhancements: service health, Mailpit link, confirmed service restart API.
- Commands default to `ProjectContext` site name (`status`, `db:*`, `pg:*`, `secure`, `isolate`, `which-php`, …).
- `valet trust` / `cert:list` / `cert:info` / `cert:renew` — certificate visibility, renewal, and CA trust.
- `valet logs` — aggregate multi-service logs (`--follow`, `--services`, `--tail`, `--grep`), including optional Laravel `app` log.
- `valet profile:*` — per-project and global profiles (`list`, `show`, `save`, `use [--apply]`, `delete`).
- `valet init` — bootstrap a project (`--db`, `--migrate`, `--composer`, `--isolate`, `--secure`, `--force`, `--pg`).
- `valet db:setup` / `valet db:refresh` — create or reset the project database, sync `.env`, run migrate/seed.
- Driver hooks `initCommands()` / `envKeys()` on `ValetDriver` (Laravel, Bedrock, WordPress).
- `valet env` — print merged project / Valet environment variables (`--json`, `--export`, `--print-db-url`).
- `valet doctor` — diagnose and optionally repair common issues (`--fix`, `--dry-run`, `--json`).
- Driver hook `replCommand()` on `ValetDriver` (Laravel override).

### Fixed

- `Diagnose` DnsMasq check now uses the configured service manager instead of hard-coded `systemctl`.
- `ProjectContext` container binding no longer passes unused constructor arguments.

## [2.2.3] - 2026-09-04

### Changed

- PHP support is now open-ended: any PHP `>=8.2` is accepted for `valet use` (validation via `version_compare`, display list 8.2–8.12 & 9.0–9.6). Previously hard-coded 8.2–8.6.
- Site isolation now accepts any PHP `>=7.0` (was hard-coded 7.0–8.6) with the same display generation.
- `normalizePhpVersion` now handles two-digit minors (e.g. 8.10, 9.1).
- Requirements and CI updated: `composer.json` `php >=8.2`, README notes future-proof, CI matrix adds 8.6.

### Fixed

- Dashboard and diagnose now report dynamic supported versions.

## [2.1.5]

- Current release version (see git history for details).

## [2.1.4] - Isolation & distro fixes

- Fixed the isolated PHP version reported by the dashboard.
- Distro-aware PHP-FPM service name resolution so isolation works correctly across Debian/Ubuntu, Fedora, and Arch.

## [2.1.3] - Dashboard bootstrap

- Dashboard now bootstraps through `server.php` with a proper container/request lifecycle, improving reliability and data collection.

## [2.1.2] - Server hardening

- Hardened `server.php` (defensive requires using `__DIR__`, safer bootstrap helpers) to reduce the attack surface of the dashboard server.

## [2.1.1] - Package rename

- Renamed the Composer package to **`ahmdadl/valet-linux-plus`** for fork distribution; updated repository references and badges accordingly.

## [2.1.0] - Major feature release

- **Diagnose** &mdash; `valet diagnose` produces a human-readable health report (OS, PHP, Nginx, DNS, services, paths); `--json` for machine-readable output.
- **PostgreSQL (opt-in)** &mdash; `valet install --with-pgsql` enables a full `pg:*` command family mirroring `db:*` (list/create/drop/reset/import/export/configure). System DBs are hidden from `pg:list`.
- **Xdebug toggle** &mdash; `valet xdebug on|off|status [--version=8.3]` using `phpenmod`/`phpdismod` with a symlink fallback, restarting FPM.
- **Logs, Mail, Status** &mdash; `valet log [nginx|php|mysql|mailpit|redis] [--tail=50]`, `valet mail` opens the Mailpit UI, and `valet status` shows a unified service table plus a global config summary.
- **Backup / Restore** &mdash; `valet backup [--with-db]` archives config, Nginx, and certs (plus optional DB dumps); `valet restore <archive>` brings it back.
- **Configurable Services** &mdash; define extra services in `config.json` (built-in `minio` template) and manage them via `service:add` / `service:remove`, auto-wired into `start`/`restart`/`stop`/`status`.
- **Dashboard** &mdash; `valet dashboard [--open]` serves a landing page at `valet.<domain>` (also `dashboard.<domain>`).

[Unreleased]: https://github.com/ahmdadl/valet-linux-plus/compare/v3.5.0...HEAD
[3.5.0]: https://github.com/ahmdadl/valet-linux-plus/compare/v3.4.0...v3.5.0
[3.4.0]: https://github.com/ahmdadl/valet-linux-plus/compare/v3.3.0...v3.4.0
[3.3.0]: https://github.com/ahmdadl/valet-linux-plus/compare/v3.2.0...v3.3.0
[3.2.0]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v3.2.0
[3.1.0]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v3.1.0
[2.2.3]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.2.3
[2.1.5]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.5
[2.1.4]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.4
[2.1.3]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.3
[2.1.2]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.2
[2.1.1]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.1
[2.1.0]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.0
