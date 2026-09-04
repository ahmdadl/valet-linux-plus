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
