# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

[Unreleased]: https://github.com/ahmdadl/valet-linux-plus/compare/v2.2.0...HEAD
[2.2.0]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.2.0
[2.1.5]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.5
[2.1.4]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.4
[2.1.3]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.3
[2.1.2]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.2
[2.1.1]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.1
[2.1.0]: https://github.com/ahmdadl/valet-linux-plus/releases/tag/v2.1.0
