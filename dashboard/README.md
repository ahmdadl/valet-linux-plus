# Dashboard

The React dashboard for `valet dashboard` — the web UI served at
`http://valet.<domain>` by `server.php`.

## Stack

- **React 19** + **TypeScript** (strict)
- **Vite 6** with `@vitejs/plugin-react`
- **React Router 7** (`createBrowserRouter`) for the twelve routes
- **TanStack Query 5** for polling, caching and mutations
- **Tailwind CSS 4** via `@tailwindcss/vite`, with the design tokens from the
  legacy single-file UI in `src/index.css`
- **Vitest** + **Testing Library** for unit tests

The build output goes to `../cli/templates/dashboard-dist` and is committed, so the
composer package ships a working dashboard without Node installed. `src/types.ts` is
a hand-written mirror of `DashboardApi`'s action registry and payload shapes.

## Commands

```bash
npm install
npm run dev         # http://127.0.0.1:5173 (or the next free port), /api proxied
npm run build       # tsc --noEmit, then emit ../cli/templates/dashboard-dist
npm run test        # vitest run
npm run typecheck   # tsc --noEmit only
```

To try the built app with real data instead, from the repository root:

```bash
php -S 127.0.0.1:8090 server.php
```

…and open `http://valet.test:8090`. `valet.test` resolves to loopback through
dnsmasq and `server.php` ignores the port, so the `Host` check matches; requests
arrive from loopback, so mutations are authorised exactly as they are under nginx.

From the repository root, `composer dashboard:build` runs the same build.

## Configuration

| Variable | Used by | Meaning |
| --- | --- | --- |
| `VITE_DEV_API_ORIGIN` | dev server only | Where the `/api` proxy points. Defaults to `https://valet.test`. |
| `VITE_DEV_PORT` | dev server only | Port the dev server tries first. Defaults to 5173. |
| `VITE_API_BASE` | built app | Origin the app calls its API on. Empty means same-origin, which is what the CSRF double-submit cookie and the loopback checks assume. |

`VITE_DEV_API_ORIGIN` must keep a `valet.<domain>` host, not an IP. `server.php`
decides a request is for the dashboard by reading the `Host` header, so pointing
the proxy at `http://127.0.0.1:8090` gets Valet's own 404 instead of the API. To
develop against a locally running `php -S 127.0.0.1:8090 server.php` rather than
the installed valet, use `http://valet.test:8090` — the `valet.test` name already
resolves to loopback via dnsmasq, and the proxy rewrites `Host` for you.

If port 5173 is already taken, the dev server binds the next free port and logs
the one it chose, so `npm run dev` never fails on a port clash.

## Why the proxy rewrites Origin

`changeOrigin: true` sets the outgoing `Host` to `valet.<domain>` so the dashboard
route matches. The browser, however, sends `Origin: http://127.0.0.1:<dev port>`,
and `DashboardRequest::hasValidOrigin()` compares that against the `Host` PHP saw.
That mismatch rejected every user- and root-tier mutation with "Missing or invalid
CSRF token". The proxy's `configure` hook in `vite.config.ts` therefore rewrites
`Origin` and `Referer` to the API origin, so a mutation from the dev server passes
exactly the checks it will face in production. A genuinely foreign `Origin` is
still rejected — that path is unchanged and covered by the PHPUnit suite.

## How it talks to the API

- `GET /api/data` is polled every 5s (paused while the tab is hidden).
- `GET /api/jobs` every 1.2s while any job is running.
- Mutations `POST /api/actions/<slug>` with `credentials: 'same-origin'` and the
  `valet_csrf` cookie echoed in the `X-Valet-CSRF` header — see
  `src/lib/api.ts`. On success the `data` and `jobs` queries are invalidated, so
  the page updates without waiting for the next poll.

Action forms are rendered from `GET /api/catalog` (`src/components/ActionModal.tsx`),
not hand-written per action, so a new parameter or a new action in `DashboardApi`
needs no UI change.

## Tests

`tests/api.test.ts` pins the CSRF behaviour and the response envelope,
`tests/panels.test.tsx` covers the action modal, and `tests/app.test.tsx` boots the
real router with a stub fetch. The PHP half is pinned by
`../tests/Unit/DashboardServerTest.php`.
