# JSON Schema and Machine-Readable Output

Valet Linux+ supports versioned, machine-readable JSON output for several
commands so they can be consumed by editors, dashboards, and CI tooling.

## Schema Version

The current schema version is `1`. Every JSON envelope includes
`schema_version`, `schema_command`, `data`, and `timestamp` (ISO 8601 UTC).

## Commands

| Command    | Flag            | Description                                  |
| ---------- | --------------- | -------------------------------------------- |
| `diagnose` | `--json`        | Diagnostic snapshot of the Valet install.    |
| `status`   | `--json`        | Service status + global config summary.      |
| `env`      | always JSON     | Project context derived from the CWD.        |
| `health`   | `--json`        | Health checks for known services.            |
| `schema`   | (default JSON)  | Print the JSON schema for a command.         |

Run `valet schema <command>` to print the schema definition for any of the
commands above.

## Envelope

```json
{
  "schema_version": 1,
  "schema_command": "diagnose",
  "data": { /* command-specific payload */ },
  "timestamp": "2026-09-04T12:34:56Z"
}
```

## Examples

```bash
# Print the diagnose schema
valet schema diagnose

# Run a health check and print JSON
valet health --json

# Resolve project context from the CWD
valet env
```

## Health Result Shape

Each service in the `services` array has the following fields:

- `service` — service name (e.g. `nginx`, `php`, `mysql`)
- `healthy` — boolean indicating reachability
- `latency_ms` — measured check latency
- `message` — human-readable status
- `timestamp` — ISO 8601 UTC
