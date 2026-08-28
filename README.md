<p align="center"><img width="500" src="art/logo.png"></p>

<p align="center">
<a href="https://packagist.org/packages/ahmdadl/valet-linux-plus"><img src="https://poser.pugx.org/ahmdadl/valet-linux-plus/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/ahmdadl/valet-linux-plus"><img src="https://poser.pugx.org/ahmdadl/valet-linux-plus/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/ahmdadl/valet-linux-plus"><img src="https://poser.pugx.org/ahmdadl/valet-linux-plus/license.svg" alt="License"></a>
</p>

<p align="center"><strong>Version 2.2.0</strong> &middot; An advanced local development environment for Linux.</p>

## Table of Contents

- [Introduction](#introduction)
- [Installation & Requirements](#installation--requirements)
- [Command Overview](#command-overview)
- [Changelog](CHANGELOG.md)
- [What's Different vs Upstream](#whats-different-vs-upstream)
- [Credits](#credits)
- [License](#license)

## Introduction

Valet *Linux+* is an advanced development environment for Linux minimalists. No Vagrant, no `/etc/hosts` file. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Valet *Linux+* configures your system to always run Nginx in the background when your machine starts. Then, using [DnsMasq](https://en.wikipedia.org/wiki/Dnsmasq), Valet proxies all requests on the `*.test` domain to point to sites installed on your local machine.

In other words, a blazing fast PHP development environment that uses roughly 7mb of RAM. Valet *Linux+* isn't a complete replacement for Vagrant or Homestead, but provides a great alternative if you want flexible basics, prefer extreme speed, or are working on a machine with a limited amount of RAM.

## Installation & Requirements

Valet *Linux+* is distributed as a Composer package and requires the following:

| Requirement | Notes |
| --- | --- |
| **PHP** | `>=8.2` (CLI) — any PHP >=8.2 is supported (8.2, 8.3, 8.4 … 9.x, future-proof). Extensions `pdo`, `posix`, `json`, `mbstring`, `xml` are required. Valet validates with `version_compare >=8.2` and isolation supports `>=7.0`. |
| **Nginx** | Installed and managed automatically by Valet. |
| **DnsMasq** | Installed and configured automatically for the `*.test` TLD. |
| **Composer** | Required to install Valet (`composer global require ahmdadl/valet-linux-plus`). |
| **OS support** | Debian/Ubuntu, Fedora, and Arch-based distributions (distro-aware service handling). |
| **Optional** | MySQL/MariaDB (default DB), Redis, Mailpit (mail catching), Ngrok (sharing), and PostgreSQL (opt-in via `--with-pgsql`). |

Install globally with Composer, then run the installer:

```bash
composer global require ahmdadl/valet-linux-plus
valet install
```

Use `valet install --with-pgsql` to also set up PostgreSQL, or `--mariadb` to use MariaDB instead of MySQL. See [docs/commands.md](docs/commands.md) for the full `install` options.

## Command Overview

Valet *Linux+* ships with a large command set. The complete, detailed reference — including every option, argument, and example — lives in **[docs/commands.md](docs/commands.md)**.

| Category | Commands |
| --- | --- |
| **Service lifecycle** | `install`, `start`, `restart`, `stop`, `uninstall`, `status`, `services`, `service:add`, `service:remove` |
| **Version & update** | `is-latest`, `update` |
| **Domain, port & networking** | `domain`, `port`, `which`, `proxy`, `unproxy`, `proxies` |
| **Paths & linking** | `park`, `paths`, `forget`, `link`, `unlink`, `links`, `secure`, `unsecure`, `secured` |
| **Database (MySQL/MariaDB)** | `db:list`, `db:create`, `db:drop`, `db:reset`, `db:import`, `db:export`, `db:configure` |
| **PostgreSQL** | `pg:list`, `pg:create`, `pg:drop`, `pg:reset`, `pg:import`, `pg:export`, `pg:configure` |
| **PHP isolation** | `use`, `isolate`, `unisolate`, `isolated`, `which-php`, `php`, `composer` |
| **IDE helpers** | `code`, `ps`, `subl`, `open` |
| **Sharing** | `share`, `fetch-share-url`, `ngrok-auth` |
| **Diagnostics & 2.1.x** | `diagnose`, `xdebug`, `log`, `mail`, `dashboard`, `backup`, `restore` |

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## What's Different vs Upstream

This fork is based on [`valet-linux-plus/valet-linux-plus`](https://github.com/valet-linux-plus/valet-linux-plus) and extends it with the following capabilities not present in the upstream base:

- **Diagnose** &mdash; a built-in, scriptable health check (`valet diagnose [--json]`).
- **PostgreSQL (opt-in)** &mdash; a complete `pg:*` API alongside the existing MySQL tooling.
- **Xdebug toggle** &mdash; one-command enable/disable/status per PHP version.
- **Logs / Mail / Status** &mdash; service log tailing, Mailpit UI launcher, and a unified status view.
- **Backup / Restore** &mdash; archive and recover the full Valet home directory, optionally with database dumps.
- **Configurable Services** &mdash; register custom services (e.g. MinIO) via `config.json` templates and manage their lifecycle.
- **Dashboard** &mdash; a web UI served at `valet.<domain>` for at-a-glance environment info.
- **Security / architecture hardening** &mdash; SQL injection and command-injection fixes, PackageManager/ServiceManager abstractions, a hardened `server.php`, and PHPStan level 9 across the codebase.

## Credits

This is a community fork of [`genesisweb/valet-linux-plus`](https://github.com/genesisweb/valet-linux-plus) (now [`valet-linux-plus/valet-linux-plus`](https://github.com/valet-linux-plus/valet-linux-plus)) created by [Uttam Rabadiya](https://github.com/uttamrab) and [Divyank Munjapara](https://github.com/divyankmunjapara). **All credit for the original base goes to them** &mdash; this fork maintains and extends their work (2.1.x: diagnose, PostgreSQL, Xdebug toggle, logs/mail/status, backup/restore, configurable services, dashboard). This project is not affiliated with or endorsed by the original authors.

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
