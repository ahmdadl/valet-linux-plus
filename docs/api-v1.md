# Valet Linux+ API v1

Stable, machine-readable JSON for IDE extensions and scripts.

## Usage

```bash
# Catalog of resources
valet api

# Fetch a resource (always JSON)
valet api sites
valet api services
valet api env
valet api health
valet api profiles
```

Example:

```bash
valet api sites | jq '.data[] | .name'
```

## Envelope

Every resource response uses this envelope:

```json
{
  "schema_version": 1,
  "schema_command": "sites",
  "data": {},
  "timestamp": "2026-09-04T12:00:00+00:00"
}
```

| Field | Type | Description |
| --- | --- | --- |
| `schema_version` | integer | API major version (`1`). Breaking changes bump this. |
| `schema_command` | string | Resource name. |
| `data` | object/array | Resource payload. |
| `timestamp` | string | ISO-8601 UTC time of the response. |

Within v1, fields are additive only. Deprecations warn for at least one minor release before removal.

## Resources

| Resource | `data` shape | Source |
| --- | --- | --- |
| `sites` | array of site rows | Dashboard |
| `services` | array of service rows | Dashboard |
| `env` | map of env vars | `valet env` |
| `health` | `{ services, healthy }` | `valet health` |
| `profiles` | `{ project, list }` | Profile store |

JSON Schema files live in [`docs/schemas/v1/`](schemas/v1/).

## Compatibility with `valet schema`

`valet schema` documents CLI command output shapes (`diagnose`, `status`, `env`, `health`).  
`valet api` is the stable aggregation surface for tooling; prefer it for new integrations.

## Not part of v1

`valet api` is a read-only, loopback-agnostic surface. The dashboard has its own HTTP
API at `http://valet.<domain>/api/*`, which *can* change things and is therefore
deliberately excluded from this stable contract:

- it is only served when `server.php` is installed (that is, after `valet install`);
- mutations are refused unless the request comes from the loopback interface, carries a
  matching CSRF cookie and `X-Valet-CSRF` header, and echoes a confirmation value;
- it does not use the envelope above, and its action surface changes with the dashboard.

Use `valet api` for tooling, and `valet <command>` for scripts that need to change
something. See [`docs/dashboard.md`](dashboard.md) for the dashboard's own contract.
