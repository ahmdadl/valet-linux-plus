<p align="center"><img width="500" src="art/logo.png" alt="Valet Linux+"></p>

<p align="center">
<a href="https://packagist.org/packages/ahmdadl/valet-linux-plus"><img src="https://poser.pugx.org/ahmdadl/valet-linux-plus/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/ahmdadl/valet-linux-plus"><img src="https://poser.pugx.org/ahmdadl/valet-linux-plus/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/ahmdadl/valet-linux-plus"><img src="https://poser.pugx.org/ahmdadl/valet-linux-plus/license.svg" alt="License"></a>
</p>

<p align="center">
<strong>Zero-config local development for Linux.</strong><br>
Nginx + DnsMasq, every project at <code>*.test</code>, ~7&nbsp;MB of RAM.<br>
No Vagrant, no Docker, no <code>/etc/hosts</code> edits.
</p>

<p align="center">
<a href="#quick-start">Quick start</a> ·
<a href="#features">Features</a> ·
<a href="docs/commands.md">Command reference</a> ·
<a href="docs/dashboard.md">Dashboard</a> ·
<a href="CHANGELOG.md">Changelog</a>
</p>

---

## Why Valet Linux+

Valet configures Nginx to start with your machine and uses DnsMasq to resolve every `*.test` domain to your local sites — linked directories, parked folders, even proxies to other ports. You get HTTPS with one command, per-site PHP versions, and a browser dashboard that can actually *change* things — all without a VM.

This fork extends the [upstream Valet Linux+](https://github.com/valet-linux-plus/valet-linux-plus) with a controllable dashboard, project bootstrapping, diagnostics, database tooling, idle sleep, and more. See [What's Different vs Upstream](#whats-different-vs-upstream).

## Features

### Serve

| Capability | What you get |
|---|---|
| **Link & park** | `valet link` a single project or `valet park` a whole code directory |
| **Proxy** | `valet proxy api.test http://127.0.0.1:3000` for Node, Docker, or anything else |
| **HTTPS** | `valet secure` / `valet unsecure`, `valet trust`, `cert:list` / `cert:renew` |
| **PHP isolation** | `valet isolate 8.3`, `valet use 8.4`, `valet which-php` — any PHP `>=7.0` |
| **Node via nvm** | `valet node:use`, `node:install`, `node:current` — reads `.nvmrc` |
| **Idle sleep** | `valet sleep` / `valet wake` — stops isolated FPM pools when nothing needs them |

### Dashboard

`valet dashboard` serves a web UI at `http://valet.<domain>`:

- **Read-only from anywhere** — sites, services, health, logs, certificates, caches, databases, snapshots. Safe to expose over ngrok.
- **Mutations only from your machine** — over `POST` from loopback, with CSRF (double-submit cookie + header), same-origin check, and a confirmation that echoes the exact value being destroyed. Every action is appended to `~/.config/valet/Log/dashboard-audit.log`.
- **Privileged work is opt-in** — `sudo valet dashboard:privileges install` drops a root-owned helper at `/usr/local/libexec/valet-dashboard-helper` with a `NOPASSWD` sudoers rule pinned to that exact path. Without it, the dashboard stays read-only and says so. The helper never executes user-supplied shell text; it re-validates every command against an allowlist.
- **Background jobs** — imports, exports, snapshots, and backups run detached and are polled so php-fpm timeouts don't kill them.
- **A React single-page app** — twelve routes (overview, sites, services, databases, PHP, snapshots, backups, certificates, logs, jobs, settings) with live polling, dark mode, and forms generated from the API's own action catalog. The built assets ship in `cli/templates/dashboard-dist/`, so no Node toolchain is needed to use it.

See [docs/dashboard.md](docs/dashboard.md) for the full security model and [docs/api-v1.md](docs/api-v1.md) for the machine API.

### Databases

- **MySQL / MariaDB** — `valet db:list|create|drop|reset|import|export|setup|refresh`, plus SQLite (`db:sqlite`, `db:sqlite:reset`), direct URLs (`db:url`, `db:open`) and always-on Adminer at `https://database.valet.<domain>` with a pluggable `~/.config/valet/database/plugins` directory.
- **PostgreSQL (opt-in)** — `valet install --with-pgsql` enables a matching `pg:*` family.

### Addons & object storage

- **MinIO (S3-compatible) via Valet** — `valet addon:enable minio` wires the binary at `/usr/local/bin/minio` as a Valet service (`cli/Valet/ServiceRegistry.php:50` template, `cli/Valet/Addon.php:18` catalog). It creates the proxy `https://minio.test` (`~/.config/valet/Nginx/minio.test` → `http://127.0.0.1:9000`), `config.json:services.minio`, and on Ubuntu 26.04 where no `minio` apt package exists it falls back to a direct download from `dl.min.io` and creates `/etc/systemd/system/minio.service` (`ExecStart=/usr/local/bin/minio server --license $MINIO_LICENSE $MINIO_OPTS $MINIO_VOLUMES`, `EnvironmentFile=/etc/default/minio`, user `minio-user`, data `/mnt/data`). `valet status` (`ServiceRegistry.php:300` resilient to missing unit) then shows `minio | installed/enabled/active`, and `valet start|stop|restart minio` delegate to `systemctl`. Console is `http://127.0.0.1:9001` (`MINIO_OPTS --console-address :9001`). Creds `minioadmin/minioadmin` in `/etc/default/minio`, license via `MINIO_LICENSE=/etc/minio/license` or `mc license update local ~/Downloads/minio.license` (`mc license --help`).

  **Valet vs standalone:** standalone you do the same manually — `curl dl.min.io... | install`, `groupadd/useradd`, `mkdir /mnt/data`, write `/etc/default/minio` + `minio.service` (`--license`), `daemon-reload; enable --now`, write an Nginx server block + cert. Valet automates it, integrates with `valet status`/`restart`/`proxy`/`domain`, and on 26.04 `AbstractPackageManager.php:74` detects `/usr/local/bin/minio` so `installed:true` without apt.

  ```env
  R2_BUCKET=zamil-ac-dev
  R2_ACCESS_KEY_ID=minioadmin
  R2_SECRET_ACCESS_KEY=minioadmin
  R2_ENDPOINT=https://minio.test
  R2_PUBLIC_URL=https://minio.test/zamil-ac-dev
  # direct: R2_ENDPOINT=http://127.0.0.1:9000
  ```

  Create bucket once: `mc alias set local http://127.0.0.1:9000 minioadmin minioadmin && mc mb local/zamil-ac-dev --ignore-existing` or via console `http://127.0.0.1:9001`.

- **Meilisearch** — `valet addon:enable meilisearch` (`ServiceRegistry.php:77` template `port 7700`) same pattern: fallback download from GitHub + systemd unit at `/etc/systemd/system/meilisearch.service`.

### Project DX

- `valet clone <repo> [--link --init --secure --isolate=8.3 --open]` — clone and bootstrap in one shot
- `valet init [--db --migrate --composer --isolate --secure]` — wire `.env`, create the DB, install deps, migrate
- `valet tinker` / `valet repl` — framework REPL with the site's PHP binary
- `valet env [--json --export=shell --print-db-url --php-bin]` — merged project + Valet env vars
- `valet shell-hook` — `eval "$(valet shell-hook)"` in your `.zshrc` to auto-switch PHP on `cd`
- `valet tune [dev|fast|debug]` / `valet bench [site]` — FPM presets and TTFB measurement
- `valet cache:status|clear|doctor` — Composer / npm / Valet cache awareness
- `valet snapshot:create|list|restore|delete`, `valet backup` / `valet restore`, `valet profile:save|use|list`

### Diagnostics & repair

- `valet diagnose [--json]` — one-shot health report (OS, PHP, Nginx, DNS, services, paths)
- `valet doctor [--fix --dry-run --json]` — diagnose and optionally repair
- `valet health` / `valet log` / `valet logs` / `valet mail` / `valet status`
- `valet xdebug on|off|status`

## Requirements

| Requirement | Notes |
|---|---|
| **PHP** | `>=8.2` on the CLI. Any `>=8.2` is accepted for `valet use`; isolation supports `>=7.0`. Extensions: `pdo`, `posix`, `json`, `mbstring`, `xml`. |
| **Nginx & DnsMasq** | Installed and configured automatically. |
| **Composer** | For the initial install (`composer global require`). |
| **OS** | Debian/Ubuntu, Fedora, and Arch-based distros (distro-aware services). |
| **Optional** | MySQL/MariaDB (default DB), Redis, Mailpit (mail catching), Ngrok (sharing), PostgreSQL (`--with-pgsql`). |

## Quick Start

```bash
# Install
composer global require ahmdadl/valet-linux-plus
valet install

# Options
valet install --with-pgsql   # also set up PostgreSQL
valet install --mariadb      # use MariaDB instead of MySQL

# Serve something
cd ~/Code/my-app
valet link                    # → http://my-app.test
valet secure                  # → https://my-app.test
valet isolate 8.3             # pin this site to PHP 8.3

# Or park a whole directory
valet park ~/Code             # every subfolder becomes *.test

# Dashboard
valet dashboard --open        # browser UI at http://valet.test
```

Add auto-switching PHP on `cd`:

```bash
echo 'eval "$(valet shell-hook)"' >> ~/.zshrc
```

Full option list for every command: **[docs/commands.md](docs/commands.md)**.

## Command Overview

| Category | Commands |
|---|---|
| **Service lifecycle** | `install`, `start`, `restart`, `stop`, `uninstall`, `status`, `services`, `service:add`, `service:remove` |
| **Version & update** | `is-latest`, `update` |
| **Domain, port & net** | `domain`, `port`, `which`, `proxy`, `unproxy`, `proxies` |
| **Paths & linking** | `park`, `paths`, `forget`, `link`, `unlink`, `links`, `secure`, `unsecure`, `secured`, `trust`, `cert:list`, `cert:info`, `cert:renew` |
| **Sleep & idle** | `sleep`, `wake`, `idle` |
| **Database (MySQL)** | `db:list`, `db:create`, `db:drop`, `db:reset`, `db:setup`, `db:refresh`, `db:sqlite`, `db:sqlite:reset`, `db:import`, `db:export`, `db:configure`, `db:url`, `db:open`, `database` |
| **PostgreSQL** | `pg:list`, `pg:create`, `pg:drop`, `pg:reset`, `pg:import`, `pg:export`, `pg:configure` |
| **PHP & Node** | `use`, `isolate`, `unisolate`, `isolated`, `which-php`, `php`, `composer`, `node:current`, `node:install`, `node:use`, `tinker`, `repl`, `shell-hook`, `tune`, `bench` |
| **Project** | `clone`, `init`, `env`, `profile:*`, `snapshot:*`, `cache:*`, `addon:*`, `api` |
| **IDE helpers** | `code`, `ps`, `subl`, `open` |
| **Sharing** | `share`, `fetch-share-url`, `ngrok-auth` |
| **Diagnostics** | `diagnose`, `doctor`, `health`, `xdebug`, `log`, `logs`, `mail`, `dashboard`, `dashboard:privileges`, `backup`, `restore` |

## What's Different vs Upstream

Based on [`valet-linux-plus/valet-linux-plus`](https://github.com/valet-linux-plus/valet-linux-plus). Everything below is new in this fork:

- **Controllable dashboard** — a React single-page app for sites, databases, PHP & Node, backups & certificates, logs & diagnostics, and settings, with 55 curated actions behind one `POST /api/actions/<slug>` endpoint. Read-only from anywhere; mutations only from the local machine through an opt-in root helper and an audit log.
- **Idle sleep** — per-site `sleep`/`wake` plus an `idle` timer and pool-RAM reporting so isolated FPM pools don't burn RAM when nothing needs them.
- **Real version discovery** — the dashboard and CLI show PHP and Node versions that are actually installed, not a fixed range.
- **Project DX** — `clone`, `init`, `tinker`/`repl`, `db:sqlite`, `cache:*`, `shell-hook`, `tune`, `bench`, `snapshot:*`.
- **Databases** — always-on Adminer with plugins, `db:url`/`db:open`, full `pg:*` family.
- **Profiles, env & API** — `profile:*`, `valet env`, and a machine-readable `valet api` (v1).
- **Diagnostics** — `diagnose`/`doctor`/`health`, `xdebug` toggle, log tailing, Mailpit launcher, custom services (`service:add` / `service:remove` with templates).
- **Hardening** — command-injection and SQL-injection fixes, PackageManager/ServiceManager abstractions, hardened `server.php`, PHPStan level 9.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Credits

A community fork of [`genesisweb/valet-linux-plus`](https://github.com/genesisweb/valet-linux-plus) (now [`valet-linux-plus/valet-linux-plus`](https://github.com/valet-linux-plus/valet-linux-plus)) by [Uttam Rabadiya](https://github.com/uttamrab) and [Divyank Munjapara](https://github.com/divyankmunjapara). All credit for the original base goes to them — this fork maintains and extends their work. Not affiliated with or endorsed by the original authors.

## License

MIT — see [LICENSE.md](LICENSE.md).
