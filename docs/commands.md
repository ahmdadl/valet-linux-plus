# Valet Linux+ Command Reference

This document is the complete reference for every command shipped with **Valet Linux+** (version `2.1.4`). It is generated from `cli/app.php`, which is the source of truth for command syntax, options, and descriptions.

All commands are invoked through the `valet` binary (e.g. `valet start`, `valet db:create`). Most commands require Valet to be installed first (`valet install`).

## Table of Contents

- [Service Lifecycle](#service-lifecycle)
  - [install](#install)
  - [start](#start)
  - [restart](#restart)
  - [stop](#stop)
  - [uninstall](#uninstall)
  - [status](#status)
  - [services](#services)
  - [service:add](#serviceadd)
  - [service:remove](#serviceremove)
- [Version & Update](#version--update)
  - [is-latest](#is-latest)
  - [update](#update)
- [Domain, Port & Networking](#domain-port--networking)
  - [domain](#domain)
  - [port](#port)
  - [which](#which)
  - [proxy](#proxy)
  - [unproxy](#unproxy)
  - [proxies](#proxies)
- [Paths & Linking](#paths--linking)
  - [park](#park)
  - [paths](#paths)
  - [forget](#forget)
  - [link](#link)
  - [unlink](#unlink)
  - [links](#links)
  - [secure](#secure)
  - [unsecure](#unsecure)
  - [secured](#secured)
- [Database (MySQL / MariaDB)](#database-mysql--mariadb)
  - [db:list](#dblist)
  - [db:create](#dbcreate)
  - [db:drop](#dbdrop)
  - [db:reset](#dbreset)
  - [db:import](#dbimport)
  - [db:export](#dbexport)
  - [db:configure](#dbconfigure)
- [PostgreSQL](#postgresql)
  - [pg:list](#pglist)
  - [pg:create](#pgcreate)
  - [pg:drop](#pgdrop)
  - [pg:reset](#pgreset)
  - [pg:import](#pgimport)
  - [pg:export](#pgexport)
  - [pg:configure](#pgconfigure)
- [PHP Isolation](#php-isolation)
  - [use](#use)
  - [isolate](#isolate)
  - [unisolate](#unisolate)
  - [isolated](#isolated)
  - [which-php](#which-php)
  - [php](#php)
  - [composer](#composer)
- [IDE Helpers](#ide-helpers)
  - [code](#code)
  - [ps](#ps)
  - [subl](#subl)
  - [open](#open)
- [Sharing](#sharing)
  - [share](#share)
  - [fetch-share-url](#fetch-share-url)
  - [ngrok-auth](#ngrok-auth)
- [Diagnostics & 2.1.x Features](#diagnostics--21x-features)
  - [diagnose](#diagnose)
  - [xdebug](#xdebug)
  - [log](#log)
  - [mail](#mail)
  - [dashboard](#dashboard)
  - [backup](#backup)
  - [restore](#restore)

---

## Service Lifecycle

### install

Install all Valet services (Nginx, PHP-FPM, DnsMasq, Mailpit, Redis, MySQL/MariaDB, Ngrok) and symlink the `valet` binary into your user `bin`.

```bash
valet install [--ignore-selinux] [--mariadb] [--with-pgsql]
```

| Option | Description |
| --- | --- |
| `--ignore-selinux` | Skip SELinux checks during installation. |
| `--mariadb` | Install MariaDB instead of MySQL. |
| `--with-pgsql` | Also install and configure PostgreSQL (opt-in). |

At the end of the install you are asked whether to link Valet's bundled `php` helper to `/usr/local/bin/php`.

Examples:

```bash
valet install
valet install --mariadb
valet install --with-pgsql --ignore-selinux
```

### start

Start the Valet daemon services. Pass one or more service names to start only those services.

```bash
valet start [services]*
```

Examples:

```bash
valet start
valet start nginx php-fpm redis
```

### restart

Restart the Valet daemon services. Accepts an optional list of service names.

```bash
valet restart [services]*
```

Examples:

```bash
valet restart
valet restart nginx
```

### stop

Stop the Valet daemon services. Accepts an optional list of service names.

```bash
valet stop [services]*
```

Examples:

```bash
valet stop
valet stop mailpit
```

### uninstall

Uninstall Valet entirely: removes Nginx, PHP-FPM, DnsMasq, Mailpit, and configuration, and unlinks the binary.

```bash
valet uninstall
```

### status

Print a unified status report: a per-service table (`Service | Installed? | Enabled? | Active?`) plus a global summary table (`Domain | Port | PHP Version | Paths | Sites`).

```bash
valet status
```

### services

List all known services — built-in, custom (defined in `config.json`), and available templates — with their type, port, and proxy host.

```bash
valet services
```

### service:add

Add a custom service definition to `~/.config/valet/config.json`. If a `--proxyHost` is given, a proxy site is created automatically.

```bash
valet service:add [name] [--template=] [--package=] [--service=] [--port=] [--proxyHost=] [--healthCheck=]
```

| Argument / Option | Description |
| --- | --- |
| `name` | (Required) Name of the custom service. |
| `--template` | Use a built-in template (e.g. `minio`). |
| `--package` | System package name. |
| `--service` | Service unit name. |
| `--port` | Service port. |
| `--proxyHost` | Upstream host to proxy (e.g. `http://127.0.0.1:9000`). |
| `--healthCheck` | Health check URL. |

Examples:

```bash
valet service:add minio --template=minio
valet service:add redis-commander --package=redis-commander --service=redis-commander --port=8081 --proxyHost=http://127.0.0.1:8081
```

### service:remove

Remove a custom service definition. Built-in services cannot be removed.

```bash
valet service:remove [name]
```

Example:

```bash
valet service:remove minio
```

---

## Version & Update

### is-latest

Check whether the installed Valet Linux+ is the latest published release. Prints `YES` or `NO`.

```bash
valet is-latest
```

### update

Update Valet Linux+ to the latest release and clean up cruft. If already on the latest version it just runs the cleanup script.

```bash
valet update
```

---

## Domain, Port & Networking

### domain

Get or set the TLD domain Valet uses for sites (default `test`). Changing the domain re-secures secured sites and restarts Nginx/PHP-FPM.

```bash
valet domain [domain]
```

Examples:

```bash
valet domain            # prints current domain
valet domain example    # switches to *.example
```

### port

Get or set the Nginx port used for Valet sites. Use `--https` to change the HTTPS port instead.

```bash
valet port [port] [--https]
```

| Option | Description |
| --- | --- |
| `--https` | Set the HTTPS port instead of the HTTP port. |

Examples:

```bash
valet port              # prints current HTTP/HTTPS ports
valet port 8080
valet port 8443 --https
```

### which

Determine which Valet driver serves the current working directory.

```bash
valet which
```

### proxy

Create an Nginx proxy site that forwards a `*.test` domain to an arbitrary host (useful for Docker, Node, or other local servers). The domain is automatically suffixed with the Valet TLD if missing.

```bash
valet proxy [domain] [host] [--secure]
```

| Argument / Option | Description |
| --- | --- |
| `domain` | (Required) The Valet domain to proxy (e.g. `api` or `api.test`). |
| `host` | (Required) Target host, must be a valid `http://` or `https://` URL. |
| `--secure` | Create the proxy with a trusted TLS certificate. |

Examples:

```bash
valet proxy api http://127.0.0.1:3000
valet proxy api http://127.0.0.1:3000 --secure
```

### unproxy

Delete an Nginx proxy config created with `proxy`.

```bash
valet unproxy [domain]
```

Example:

```bash
valet unproxy api
```

### proxies

Display all currently configured proxy sites (`URL | SSL | Host`).

```bash
valet proxies
```

---

## Paths & Linking

### park

Register the current (or specified) directory as a "parked" path. Any subdirectory becomes immediately accessible as `subdir.test`.

```bash
valet park [path]
```

Example:

```bash
valet park
valet park ~/projects
```

### paths

List all directories registered with Valet via `park`.

```bash
valet paths
```

### forget

Remove a directory from Valet's registered paths.

```bash
valet forget [path]
```

Example:

```bash
valet forget
valet forget ~/projects
```

### link

Symbolically link the current working directory to Valet so it is served as `name.test`. Defaults to the directory basename.

```bash
valet link [name]
```

Example:

```bash
valet link
valet link my-app
```

### unlink

Remove a previously created Valet link.

```bash
valet unlink [name]
```

Example:

```bash
valet unlink my-app
```

### links

Display all registered symbolic links (`URL | SSL | Path`).

```bash
valet links
```

### secure

Secure the given domain (or current directory) with a trusted self-signed TLS certificate so it is served over HTTPS.

```bash
valet secure [domain]
```

Example:

```bash
valet secure
valet secure my-app
```

### unsecure

Stop serving the given domain over HTTPS and remove its trusted certificate.

```bash
valet unsecure [domain]
```

Example:

```bash
valet unsecure my-app
```

### secured

Check whether a site (or the current directory) is secured.

```bash
valet secured [site]
```

Example:

```bash
valet secured
valet secured my-app
```

---

## Database (MySQL / MariaDB)

All `db:*` commands operate on the MySQL/MariaDB server installed by Valet. When a database name is omitted it defaults to the current directory name.

### db:list

List all available databases.

```bash
valet db:list
```

### db:create

Create a new database. Defaults to the current directory name.

```bash
valet db:create [databaseName]
```

Example:

```bash
valet db:create
valet db:create my_app
```

### db:drop

Drop a database. Prompts for confirmation unless `-y`/`--yes` is passed.

```bash
valet db:drop [databaseName] [-y|--yes]
```

| Option | Description |
| --- | --- |
| `-y`, `--yes` | Skip the confirmation prompt. |

Example:

```bash
valet db:drop my_app --yes
```

### db:reset

Drop and recreate a database (clearing all tables). Prompts for confirmation unless `-y`/`--yes` is passed.

```bash
valet db:reset [databaseName] [-y|--yes]
```

Example:

```bash
valet db:reset my_app -y
```

### db:import

Import a SQL dump file into the named database.

```bash
valet db:import [databaseName] [dumpFile]
```

| Argument | Description |
| --- | --- |
| `databaseName` | (Required) Target database. |
| `dumpFile` | (Required) Path to the SQL dump file. |

Example:

```bash
valet db:import my_app ./dump.sql
```

### db:export

Export a database to a file. Use `--sql` to force a plain `.sql` dump instead of the default compressed archive.

```bash
valet db:export [databaseName] [--sql]
```

| Option | Description |
| --- | --- |
| `--sql` | Export as a plain SQL file. |

Example:

```bash
valet db:export my_app
valet db:export my_app --sql
```

### db:configure

Configure the Valet database user for MySQL/MariaDB (creates/updates the `valet` user and grants privileges). Use `--force` to reconfigure without prompts.

```bash
valet db:configure [--force]
```

| Option | Description |
| --- | --- |
| `--force` | Skip confirmation and reconfigure. |

---

## PostgreSQL

The `pg:*` commands mirror the `db:*` API but target PostgreSQL, which is opt-in (install with `valet install --with-pgsql`). System databases (`postgres`, `template0`, `template1`) are hidden from `pg:list`.

### pg:list

List all available PostgreSQL databases (system databases excluded).

```bash
valet pg:list
```

### pg:create

Create a new PostgreSQL database. Defaults to the current directory name.

```bash
valet pg:create [databaseName]
```

Example:

```bash
valet pg:create my_app
```

### pg:drop

Drop a PostgreSQL database. Prompts for confirmation unless `-y`/`--yes` is passed.

```bash
valet pg:drop [databaseName] [-y|--yes]
```

Example:

```bash
valet pg:drop my_app --yes
```

### pg:reset

Drop and recreate a PostgreSQL database. Prompts for confirmation unless `-y`/`--yes` is passed.

```bash
valet pg:reset [databaseName] [-y|--yes]
```

Example:

```bash
valet pg:reset my_app -y
```

### pg:import

Import a dump file into a PostgreSQL database (uses `psql`/`pg_restore`).

```bash
valet pg:import [databaseName] [dumpFile]
```

| Argument | Description |
| --- | --- |
| `databaseName` | (Required) Target database. |
| `dumpFile` | (Required) Path to the dump file. |

Example:

```bash
valet pg:import my_app ./dump.sql
```

### pg:export

Export a PostgreSQL database. Use `--sql` for a plain SQL dump.

```bash
valet pg:export [databaseName] [--sql]
```

Example:

```bash
valet pg:export my_app --sql
```

### pg:configure

Configure the Valet database user for PostgreSQL (sets user/password in `config.json`). Use `--force` to reconfigure.

```bash
valet pg:configure [--force]
```

---

## PHP Isolation

### use

Set the global PHP version Valet uses to serve sites. Pass `default` or leave empty to use the system PHP. Supports `--update-cli` and `--ignore-ext`.

```bash
valet use [preferredVersion] [--update-cli] [--ignore-ext]
```

| Argument / Option | Description |
| --- | --- |
| `preferredVersion` | PHP version such as `8.3`, or `default`. |
| `--update-cli` | Also update the CLI `php` version. |
| `--ignore-ext` | Install extensions for the selected PHP version. |

Example:

```bash
valet use 8.3
valet use default --update-cli
```

### isolate

Serve the current (or specified) site with a specific PHP version, independent of the global version. Writes a `.valetphprc` if a version is found there.

```bash
valet isolate [phpVersion] [--site=] [--secure]
```

| Argument / Option | Description |
| --- | --- |
| `phpVersion` | PHP version to isolate (e.g. `php@8.1`). If omitted, reads `.valetphprc`. |
| `--site` | Specify the site to isolate (when it isn't linked as its directory name). |
| `--secure` | Create the isolated site with a trusted TLS certificate. |

Example:

```bash
valet isolate php@8.1
valet isolate 8.2 --site=my-app --secure
```

### unisolate

Revert a site to the global PHP version.

```bash
valet unisolate [--site=]
```

| Option | Description |
| --- | --- |
| `--site` | Specify the site to un-isolate. |

Example:

```bash
valet unisolate --site=my-app
```

### isolated

List all sites currently using an isolated PHP version (`URL | SSL | PHP Version`).

```bash
valet isolated
```

### which-php

Print the absolute path to the PHP executable used for a given site (respecting isolation and `.valetphprc`).

```bash
valet which-php [site]
```

Example:

```bash
valet which-php my-app
```

### php

Proxy a command through the isolated PHP executable of a site. (When run via `cli/valet.php` directly it prints a warning; use the `valet` script instead.)

```bash
valet php [--site=] [command]
```

| Argument / Option | Description |
| --- | --- |
| `command` | Command to run with the site's PHP executable. |
| `--site` | Site used to resolve the PHP version. |

Example:

```bash
valet php --site=my-app -v
```

### composer

Proxy a Composer command through the isolated PHP executable of a site. (Use the `valet` script, not `cli/valet.php` directly.)

```bash
valet composer [--site=] [command]
```

| Argument / Option | Description |
| --- | --- |
| `command` | Composer command to run with the site's PHP executable. |
| `--site` | Site used to resolve the PHP version. |

Example:

```bash
valet composer --site=my-app install
```

---

## IDE Helpers

### code

Open the current (or specified) folder in Visual Studio Code.

```bash
valet code [folder]
```

Example:

```bash
valet code
valet code ~/projects/my-app
```

### ps

Open the current (or specified) folder in PHPStorm.

```bash
valet ps [folder]
```

Example:

```bash
valet ps
```

### subl

Open the current (or specified) folder in Sublime Text.

```bash
valet subl [folder]
```

Example:

```bash
valet subl
```

### open

Open the site for the current (or specified) directory in your default browser via `xdg-open`.

```bash
valet open [domain]
```

Example:

```bash
valet open
valet open my-app
```

---

## Sharing

### share

Generate a publicly accessible URL for your project using an Ngrok tunnel. (Run via the `valet` script, not `cli/valet.php` directly.)

```bash
valet share
```

### fetch-share-url

Print the URL of the currently active Ngrok tunnel.

```bash
valet fetch-share-url
```

### ngrok-auth

Set the Ngrok authentication token (required before `share` works).

```bash
valet ngrok-auth [authtoken]
```

Example:

```bash
valet ngrok-auth <your-token>
```

---

## Diagnostics & 2.1.x Features

### diagnose

Run a health check across the OS, PHP, Nginx, DNS, services, and paths. Add `--json` for machine-readable output.

```bash
valet diagnose [--json]
```

| Option | Description |
| --- | --- |
| `--json` | Output the report as JSON. |

Examples:

```bash
valet diagnose
valet diagnose --json
```

### xdebug

Toggle Xdebug for a PHP version. Modes: `on`, `off`, `status` (default). Uses `phpenmod`/`phpdismod` with a symlink fallback and restarts FPM.

```bash
valet xdebug [mode] [--version=]
```

| Argument / Option | Description |
| --- | --- |
| `mode` | `on`, `off`, or `status` (default). |
| `--version` | PHP version (e.g. `8.3`). Defaults to the active version. |

Examples:

```bash
valet xdebug status
valet xdebug on --version=8.3
valet xdebug off
```

### log

Tail the logs for a given service. Supported services include `nginx`, `php`, `mysql`, `mailpit`, `redis`. Defaults to `nginx`.

```bash
valet log [service] [--tail=]
```

| Option | Description |
| --- | --- |
| `--tail` | Number of lines to show (default `50`). |

Examples:

```bash
valet log
valet log php --tail=100
valet log mailpit
```

### mail

Open the Mailpit web UI (served at `https://mails.<domain>`) in your browser.

```bash
valet mail
```

### dashboard

Print the URL of the Valet dashboard (served at `http://valet.<domain>`, also `http://dashboard.<domain>`). Pass `--open` to launch it in your browser.

```bash
valet dashboard [--open]
```

| Option | Description |
| --- | --- |
| `--open` | Open the dashboard in your browser. |

Examples:

```bash
valet dashboard
valet dashboard --open
```

### backup

Create an archive of the Valet home directory (config, Nginx, certificates). Add `--with-db` to include database dumps. Use `--output` to choose the archive path.

```bash
valet backup [--output=] [--with-db]
```

| Option | Description |
| --- | --- |
| `--output` | Path to write the backup archive to. |
| `--with-db` | Include database dumps in the backup. |

Examples:

```bash
valet backup
valet backup --with-db --output=/tmp/valet-backup.tgz
```

### restore

Restore a previously created backup archive. Pass `--force` to skip the confirmation prompt.

```bash
valet restore [file] [--force]
```

| Option | Description |
| --- | --- |
| `--force` | Skip the confirmation prompt. |

Example:

```bash
valet restore /tmp/valet-backup.tgz --force
```

---

## Configurable Services (JSON Reference)

Custom services are defined in `~/.config/valet/config.json` under the `services` key. Each service supports the following fields:

| Field | Description |
| --- | --- |
| `package` | System package name. |
| `service` | Service unit name. |
| `port` | Port the service listens on. |
| `proxyHost` | Upstream host to proxy (e.g. `http://127.0.0.1:9000`). |
| `healthCheck` | Health check URL. |
| `description` | Human-readable description. |

Built-in templates currently available: `minio`.

Example `config.json` snippet:

```json
{
  "services": {
    "minio": {
      "package": "minio",
      "service": "minio",
      "port": 9000,
      "proxyHost": "http://127.0.0.1:9000",
      "healthCheck": "http://127.0.0.1:9000/minio/health/live",
      "description": "MinIO object storage"
    }
  }
}
```

Once defined, custom services are automatically wired into `start`, `restart`, `stop`, and `status`.
