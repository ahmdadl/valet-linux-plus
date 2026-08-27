<p align="center"><img width="500" src="art/logo.png"></p>

<p align="center">
<a href="https://scrutinizer-ci.com/g/genesisweb/valet-linux-plus/?branch=master"><img src="https://scrutinizer-ci.com/g/genesisweb/valet-linux-plus/badges/quality-score.png?b=master" alt="Scrutinizer"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/license.svg" alt="License"></a>
</p>

## Introduction

Valet *Linux+* is an advanced development environment for Linux minimalists. No Vagrant, no `/etc/hosts` file. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Valet *Linux+* configures your system to always run Nginx in the background when your machine starts. Then, using [DnsMasq](https://en.wikipedia.org/wiki/Dnsmasq), Valet proxies all requests on the `*.test` domain to point to sites installed on your local machine.

In other words, a blazing fast PHP development environment that uses roughly 7mb of RAM. Valet *Linux+* isn't a complete replacement for Vagrant or Homestead, but provides a great alternative if you want flexible basics, prefer extreme speed, or are working on a machine with a limited amount of RAM.

## What's New in 2.1.0

### Diagnose

```bash
valet diagnose          # human-readable health report (OS, PHP, Nginx, DNS, services, paths)
valet diagnose --json   # machine-readable JSON
```

### PostgreSQL (opt-in)

```bash
valet install --with-pgsql
valet pg:list
valet pg:create [name]              # defaults to current directory name
valet pg:drop [name] [-y|--yes]
valet pg:reset [name] [-y|--yes]
valet pg:import <db> <dump.sql>
valet pg:export [name] [--sql]
valet pg:configure [--force]        # set pgsql user/password in config.json
```

Mirrors the `db:*` (MySQL) API via PDO pgsql / `psql` / `pg_dump`; system DBs `postgres,template0,template1` are hidden from `pg:list`.

### Xdebug Toggle

```bash
valet xdebug status [--version=8.3]
valet xdebug on  [--version=8.3]    # phpenmod/phpdismod with symlink fallback, restarts FPM
valet xdebug off [--version=8.3]
```

### Logs, Mail, Status

```bash
valet log [nginx|php|mysql|mailpit|redis] [--tail=50]
valet mail                          # opens https://mails.<domain> (xdg-open)
valet status                        # unified table: Service | Installed? | Enabled? | Active?
                                    # + Domain | Port | PHP Version | Paths | Sites
```

### Backup / Restore

```bash
valet backup [--output=/path/valet-backup.tgz] [--with-db]  # config + Nginx + certs + DB dumps
valet restore <archive.tgz> [--force]
```

### Configurable Services

Define extra services in `~/.config/valet/config.json` under the `services` key:

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

Built-in templates: `minio`.

```bash
valet services                                      # list builtin + custom + templates
valet service:add <name> --template=minio            # or --package --service --port --proxyHost --healthCheck
valet service:remove <name>
valet start [services]* / restart / stop / status    # custom services are auto-wired here
```

### Dashboard

```bash
valet dashboard          # prints URL
valet dashboard --open   # xdg-open http://valet.<domain> (also http://dashboard.<domain>)
```

Served by `server.php` + `Dashboard.php` at `valet.<domain>`.

## Official Documentation

Documentation for Valet can be found on the [Valet Linux website](https://valetlinux.plus/).

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
