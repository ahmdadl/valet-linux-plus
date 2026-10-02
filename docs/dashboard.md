# Valet Dashboard

The dashboard is a web UI for everything Valet can do. It is served by `server.php`
under php-fpm at `http://valet.<domain>` (and `http://dashboard.<domain>`), so it
only exists once `valet install` has run.

Open it with:

```bash
valet dashboard          # print the URL
valet dashboard --open   # print the URL and launch a browser
```

## What it can do

The dashboard is split into two halves.

**Read-only everywhere.** Sites and their types, service status, health checks,
nginx site count, watched paths, PHP version, logs, certificates, addons, cache
sizes, database lists, snapshots, backups and Node version are all readable from
any network the dashboard is reachable on, including over ngrok.

**Mutating only where it is safe.** Everything that changes your machine — linking
and unlinking sites, creating and dropping databases, importing and exporting,
snapshots, backups, clearing caches, and (with the privileged helper) restarting
services, securing sites, switching PHP and trusting the CA — runs through one
POST endpoint with the rules described below.

Deliberately *not* exposed: arbitrary `php`/`composer` execution, `tinker`/`repl`,
foreground sharing, `install`/`uninstall`/`update`, `service:add`/`service:remove`,
`tune`, `clone` and `bench`. Those stay in the terminal, where their prompts and
output belong.

## Security model

| Control | Rule |
| --- | --- |
| Transport | Mutating requests must come from `127.0.0.1`, `::1` or `::ffff:127.0.0.1`. Anything else (LAN, ngrok, a tunnel) is read-only. |
| Method | Mutations are `POST` only. There is no `GET` route that changes anything. |
| CSRF | A double-submit cookie (`valet_csrf`, `SameSite=Strict`, readable by JS) must be echoed in the `X-Valet-CSRF` header. Both halves must match with `hash_equals`. |
| Origin | `Origin`, or `Referer` when there is no `Origin`, must name the dashboard's own host. A request with neither is treated as non-browser and still needs the CSRF token. |
| Confirmation | Destructive actions require the client to echo the exact value being destroyed (the database name, the site name, the archive file, the PHP version). `service.stop` and `cache.clear` require `confirm=1`. |
| Allowlist | The action slug, its tier and the shape of every parameter come from a fixed registry in `DashboardApi`. Anything not in it is refused. |
| Audit | Every user-tier and root-tier action appends one JSON line to `~/.config/valet/Log/dashboard-audit.log` with the time, slug, redacted parameters, outcome and remote address. |

The old `GET /?valet_action=restart&confirm=1&service=…` route is gone. It could be
triggered by any page the browser happened to be on.

## Privileged actions

Restarting nginx, switching PHP, toggling xdebug and trusting the mkcert CA all
need root. php-fpm has no terminal, so it cannot ask for a password.

```bash
sudo valet dashboard:privileges install
valet dashboard:privileges status
sudo valet dashboard:privileges uninstall
```

Installation is opt-in and adds two files:

| Path | Mode | Purpose |
| --- | --- | --- |
| `/usr/local/libexec/valet-dashboard-helper` | `0755` root:root | The privileged helper. |
| `/etc/sudoers.d/valet-dashboard` | `0440` root:root | The `NOPASSWD` rule. |

The sudoers rule is pinned to the exact helper path **and an empty argument list**
(`… NOPASSWD: /usr/local/libexec/valet-dashboard-helper ""`), which sudo only
matches when the command is invoked with no arguments at all. The rule is proved
parseable with `visudo -c` in a temporary directory before it is installed, so a
syntax error can never lock you out of sudo.

Before the dashboard will use the helper at all, it checks that the file exists, is
a regular file (not a symlink), is owned by root and is not group- or world-writable.
A NOPASSWD rule pointing at a script the invoking user can edit would be a privilege
escalation, so anything less than that is treated as not installed.

### How a privileged action actually runs

The helper has no idea what "restart nginx" means. It runs the same Valet code the
terminal does:

1. The web request hands the action slug and its parameters to the helper as JSON on
   **stdin** — never on the command line, where they would be visible in `ps` and
   would not match the pinned argument list.
2. The helper creates a file only it can create, then re-runs the requested action as
   your own user with `VALET_DASHBOARD_DEFERRED` pointing at that file.
3. Inside that process `DeferredPrivileged` recognises the privileged commands Valet
   *wants* to run — `sudo systemctl restart 'nginx'` and friends — and appends them to
   the file instead of running them. Read-only queries such as `systemctl is-enabled`
   run normally, because Valet branches on their output and deferring them would
   invert its decisions.
4. The helper asks Valet, in a separate unprivileged process, whether every recorded
   command is on the allowlist. One unapproved command aborts the whole request, so a
   half-applied change is not a possible outcome.
5. Only then does the helper run the approved commands as root, with the leading
   `sudo` stripped.

Because step 2 runs the same code as `valet secure` or `valet php 8.3`, there is
exactly one implementation of what those commands do, and the dashboard cannot drift
away from the terminal.

## HTTP API

All endpoints return the same envelope:

```json
{ "ok": true, "message": "", "data": {}, "job": null }
```

`ok` tells you whether the action succeeded; `message` is safe to show to a user;
`job` is a background job id when one was started.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/data` | Everything for the first paint: `dashboard`, `privileges`, `csrf`, `php_versions`, `php_isolation`. |
| `GET` | `/api/catalog` | The action registry (`tiers` and `actions`) so the browser can build its own forms. |
| `GET` | `/api/jobs` | The 25 most recent background jobs. |
| `GET` | `/api/jobs/<id>` | One job plus the tail of its log. |
| `POST` | `/api/actions/<slug>` | Run one action. Body is JSON: the action's parameters plus `confirm`. |

```bash
# Read the payload
curl -s http://valet.test/api/data | jq '.data.dashboard.counts'

# Mutate: the CSRF cookie must be sent back in the header
curl -s -c jar http://valet.test/api/data > /dev/null
TOKEN=$(awk '$6=="valet_csrf"{print $7}' jar)
curl -s -b jar -H "X-Valet-CSRF: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"my_app"}' http://valet.test/api/actions/db.create
```

### Catalog

`GET /api/catalog` returns each action as
`{slug, label, tier, destructive, confirm, job, params}`. `tier` is `read`, `user` or
`root`; `confirm` is `null`, `true` (send `"1"`) or a parameter name (send that
parameter's value); `params` is a map of `{type, required, default, in}`.

### Actions

`read` tier is safe from anywhere. `user` tier changes things as your own account.
`root` tier needs the privileged helper.

| Slug | Tier | Destructive | Confirmation | Job | Parameters |
| --- | --- | --- | --- | --- | --- |
| `logs.view` | read | | | | `service`* `lines` `grep` |
| `diagnose.run` | read | | | | |
| `health.run` | read | | | | |
| `services.list` | read | | | | |
| `databases.list` | read | | | | |
| `pg.databases.list` | read | | | | |
| `snapshots.list` | read | | | | `site` |
| `backups.list` | read | | | | |
| `profiles.list` | read | | | | |
| `certs.list` | read | | | | |
| `addons.list` | read | | | | |
| `cache.status` | read | | | | |
| `node.current` | read | | | | |
| `db.url` | read | | | | `name`* |
| `site.link` | user | | | | `name`* `path`* |
| `site.unlink` | user | yes | `name` | | `name`* |
| `site.park` | user | | | | `path`* |
| `site.forget` | user | yes | `path` | | `path`* |
| `db.create` | user | | | | `name`* |
| `db.drop` | user | yes | `name` | | `name`* |
| `db.reset` | user | yes | `name` | | `name`* |
| `db.import` | user | yes | `name` | yes | `name`* `file`* |
| `db.export` | user | | | yes | `name`* `sql` |
| `pg.create` | user | | | | `name`* |
| `pg.drop` | user | yes | `name` | | `name`* |
| `pg.reset` | user | yes | `name` | | `name`* |
| `pg.import` | user | yes | `name` | yes | `name`* `file`* |
| `pg.export` | user | | | yes | `name`* `sql` |
| `snapshot.create` | user | | | yes | `path`* `name` `with_db` `notes` |
| `snapshot.restore` | user | yes | `name` | yes | `path`* `name`* |
| `snapshot.delete` | user | yes | `name` | | `path`* `name`* |
| `backup.create` | user | | | yes | `with_db` |
| `backup.restore` | user | yes | `archive` | yes | `archive`* |
| `profile.use` | user | | | | `name`* `apply` |
| `profile.delete` | user | yes | `name` | | `name`* |
| `cache.clear` | user | yes | `1` | | `composer` `npm` `valet` |
| `addon.enable` | user | | | | `name`* |
| `addon.disable` | user | yes | `name` | | `name`* |
| `node.use` | user | | | | `version`* |
| `service.start` | root | | | | `service`* |
| `service.stop` | root | yes | `1` | | `service`* |
| `service.restart` | root | | | | `service`* |
| `site.secure` | root | | | | `name`* |
| `site.unsecure` | root | yes | `name` | | `name`* |
| `site.proxy` | root | | | | `name`* `host`* `secure` |
| `site.unproxy` | root | yes | `name` | | `name`* |
| `site.isolate` | root | | | | `name`* `version`* `secure` |
| `site.unisolate` | root | yes | `name` | | `name`* |
| `domain.set` | root | yes | `domain` | | `domain`* |
| `port.set` | root | yes | `port` | | `port`* `https` |
| `trust.ca` | root | | | | `check` |
| `cert.renew` | root | | | | `site` `force` |
| `php.switch` | root | yes | `version` | | `version`* |
| `xdebug.enable` | root | | | | `version` |
| `xdebug.disable` | root | | | | `version` |

`*` marks a required parameter. The `service` choices for the service actions are
`dnsmasq`, `nginx`, `php`, `mailpit`, `mysql`, `redis` and `postgres`. `version`
choices come from `PhpFpm::supportedPhpVersions()` (or `isolationSupportedPhpVersions()`
for `site.isolate`) and are returned in `php_versions` / `php_isolation` by
`/api/data`.

### Background jobs

Imports, exports, snapshots and backups take longer than a request should hold open,
so they return a `job` id immediately and run in a detached CLI process. Poll
`/api/jobs/<id>` until `status` is `done`, `failed` or `cancelled`. Job state lives in
`~/.config/valet/dashboard-jobs/`; nothing about a running operation is reachable over
HTTP except its own id.

## Audit log

```bash
tail -f ~/.config/valet/Log/dashboard-audit.log
```

One JSON object per line:

```json
{"time":"2026-10-02T22:12:16+00:00","action":"service.restart","params":{"service":"nginx"},"outcome":"succeeded","ip":"::1"}
```

`params` is redacted — anything whose name looks like a token, password, secret or
auth value is replaced before it is written.

## Troubleshooting

**A button says privileged actions are off.** Run
`sudo valet dashboard:privileges install`, then reload. `valet dashboard:privileges
status` shows whether the helper, the sudoers rule and the trust check each pass.

**"Privileged actions are not available" on a click.** The helper is installed but
untrusted. Check `valet dashboard:privileges status` — if `helper_installed` is true
and `helper_trusted` is false, something made the helper group- or world-writable, or
changed its owner.

**A mutation returns "Missing or invalid CSRF token."** The cookie was not sent back,
or the header is missing. This normally means the page was served from a different
host than the API is being called on, or a proxy is stripping custom headers.

**"This action must be confirmed." / "Confirmation did not match …".** The browser
did not echo the value being destroyed. Use the UI rather than calling the endpoint by
hand.

**A job stays `queued`.** The worker could not start. Run
`valet dashboard:job <id>` by hand and read `~/.config/valet/dashboard-jobs/<id>.log`.

**The dashboard is blank or shows raw JSON.** `server.php` could not build the
container. `valet diagnose` will say why.

## Implementation map

| File | Role |
| --- | --- |
| `cli/Valet/DashboardServer.php` | HTTP front controller: routing, the CSRF cookie, JSON encoding. |
| `cli/Valet/DashboardApi.php` | The action registry, parameter coercion, authorization, audit, and the `perform*` methods that mirror the CLI commands. |
| `cli/Valet/DashboardRequest.php` | Immutable snapshot of an incoming request; loopback, CSRF and origin checks. |
| `cli/Valet/DashboardPrivilege.php` | Installs the helper and sudoers rule; invokes them. |
| `cli/Valet/DashboardJob.php` | Queues and tracks background work. |
| `cli/Valet/DeferredPrivileged.php` | The allowlist of commands the helper may run as root. |
| `cli/scripts/valet-dashboard-helper` | The root-owned bash helper. |
| `cli/templates/dashboard.html` | The frontend. No build step, no dependencies. |
