/**
 * Renderers for read-only action results.
 *
 * The read actions (logs.view, databases.list, snapshots.list, …) each return a
 * differently shaped blob. Rather than write a bespoke view per action, this
 * module maps each known shape to a compact presentation and falls back to a
 * JSON dump for anything new, so an added PHP read action is never invisible.
 */

import type { ReactNode } from 'react';

import { bytes, cx, humanizeName, shortenPath, timeAgo } from '../lib/format';
import type { ActionResultData, HealthRow, ServiceStatusRow } from '../types';
import { Badge, CodeBlock, DataTable, EmptyState, Td, Th, Tr } from './ui';

/** A number with a label, used across the overview. */
export function StatCard({
  label,
  value,
  hint,
  tone = 'neutral',
}: {
  label: string;
  value: ReactNode;
  hint?: ReactNode;
  tone?: 'neutral' | 'accent' | 'ok' | 'warn' | 'bad';
}) {
  const accent = tone !== 'neutral';

  return (
    <div
      className={cx(
        'rounded-[var(--radius-card)] border bg-card p-3.5 shadow-[var(--shadow-card)]',
        'dark:bg-night-card',
        accent ? 'border-accent/40' : 'border-line dark:border-night-line',
      )}
    >
      <p className="text-[11px] font-medium tracking-wide text-muted uppercase dark:text-night-muted">
        {label}
      </p>
      <p className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
      {hint !== undefined && hint !== null && (
        <p className="mt-0.5 text-[11px] text-muted dark:text-night-muted">{hint}</p>
      )}
    </div>
  );
}

/** Log lines, in a monospace pane with the service prefix kept. */
export function LogPane({ lines }: { lines: string[] }) {
  if (lines.length === 0) {
    return <EmptyState title="No log lines" hint="The service may not have written anything yet." />;
  }

  return <CodeBlock maxHeight="34rem">{lines.join('\n')}</CodeBlock>;
}

/** Service status rows from the services.list action. */
export function ServiceRows({ rows }: { rows: ServiceStatusRow[] }) {
  if (rows.length === 0) {
    return <EmptyState title="No services reported" />;
  }

  return (
    <DataTable
      head={
        <>
          <Th>Service</Th>
          <Th>Installed</Th>
          <Th>Enabled</Th>
          <Th>Active</Th>
        </>
      }
    >
      {rows.map((row) => (
        <Tr key={row.service}>
          <Td className="font-medium">{humanizeName(row.service)}</Td>
          <Td>
            <Badge tone={row.installed ? 'ok' : 'neutral'}>{row.installed ? 'yes' : 'no'}</Badge>
          </Td>
          <Td>
            <Badge tone={row.enabled ? 'ok' : 'warn'}>{row.enabled ? 'yes' : 'no'}</Badge>
          </Td>
          <Td>
            <Badge tone={row.active ? 'ok' : 'bad'}>{row.active ? 'running' : 'stopped'}</Badge>
          </Td>
        </Tr>
      ))}
    </DataTable>
  );
}

/** Health rows from health.run. */
export function HealthRows({ rows }: { rows: HealthRow[] }) {
  if (rows.length === 0) {
    return <EmptyState title="No health checks reported" />;
  }

  return (
    <ul className="grid gap-1.5 p-3 sm:grid-cols-2">
      {rows.map((row) => (
        <li
          key={row.service}
          className={cx(
            'flex items-start gap-2 rounded-lg border px-3 py-2 text-[13px]',
            row.healthy
              ? 'border-ok/30 bg-ok/5'
              : 'border-bad/30 bg-bad/5 dark:border-bad/40',
          )}
        >
          <span
            aria-hidden="true"
            className={cx(
              'mt-1.5 size-2 shrink-0 rounded-full',
              row.healthy ? 'bg-ok' : 'bg-bad',
            )}
          />
          <div className="min-w-0">
            <p className="font-medium">{humanizeName(row.service)}</p>
            <p className="text-[12px] text-muted dark:text-night-muted">{row.message}</p>
          </div>
        </li>
      ))}
    </ul>
  );
}

/** A plain list of names (databases, backup archives, …). */
export function NameList({ items }: { items: string[] }) {
  if (items.length === 0) {
    return <EmptyState title="Nothing here" />;
  }

  return (
    <ul className="grid gap-1 p-3 sm:grid-cols-2 lg:grid-cols-3">
      {items.map((item) => (
        <li
          key={item}
          className="truncate rounded-lg border border-line bg-paper px-2.5 py-1.5 font-mono text-[12px] dark:border-night-line dark:bg-night"
          title={item}
        >
          {item}
        </li>
      ))}
    </ul>
  );
}

/** Certificates, with expiry front and centre. */
export function CertificateRows({ rows }: { rows: NonNullable<ActionResultData['certificates']> }) {
  if (rows.length === 0) {
    return <EmptyState title="No certificates yet" hint="Secure a site to issue one." />;
  }

  return (
    <DataTable
      head={
        <>
          <Th>Site</Th>
          <Th>Valid</Th>
          <Th>Expires</Th>
          <Th>Days left</Th>
        </>
      }
    >
      {rows.map((row) => (
        <Tr key={row.site}>
          <Td className="font-medium">{row.site}</Td>
          <Td>
            <Badge tone={row.valid ? 'ok' : 'bad'}>{row.valid ? 'valid' : 'invalid'}</Badge>
          </Td>
          <Td className="text-muted dark:text-night-muted">{row.expires_at ?? '—'}</Td>
          <Td className="tabular-nums">
            {row.days_remaining === null ? '—' : row.days_remaining}
          </Td>
        </Tr>
      ))}
    </DataTable>
  );
}

/** Backup archives, shown as file paths. */
export function BackupRows({ rows }: { rows: string[] }) {
  if (rows.length === 0) {
    return <EmptyState title="No backups yet" hint="Create one from the Backups page." />;
  }

  return (
    <ul className="grid gap-1 p-3">
      {rows.map((row) => (
        <li
          key={row}
          className="truncate rounded-lg border border-line bg-paper px-3 py-2 font-mono text-[12px] dark:border-night-line dark:bg-night"
          title={row}
        >
          {row}
        </li>
      ))}
    </ul>
  );
}

/** Snapshot rows. */
export function SnapshotRows({
  rows,
  onRestore,
  onDelete,
}: {
  rows: NonNullable<ActionResultData['snapshots']>;
  onRestore?: (snapshot: { name: string; path: string }) => void;
  onDelete?: (snapshot: { name: string; path: string }) => void;
}) {
  if (rows.length === 0) {
    return <EmptyState title="No snapshots" hint="Snapshots are per-site; pick a site or create one." />;
  }

  return (
    <DataTable
      head={
        <>
          <Th>Name</Th>
          <Th>Site</Th>
          <Th>Taken</Th>
          <Th>Database</Th>
          <Th />
        </>
      }
    >
      {rows.map((row) => (
        <Tr key={`${row.site}/${row.name}`}>
          <Td className="font-medium">{row.name}</Td>
          <Td className="text-muted dark:text-night-muted">{row.site}</Td>
          <Td className="text-muted dark:text-night-muted">{timeAgo(row.timestamp)}</Td>
          <Td>
            <Badge tone={row.includes_db ? 'ok' : 'neutral'}>
              {row.includes_db ? 'included' : 'files only'}
            </Badge>
          </Td>
          <Td className="text-right">
            <span className="inline-flex gap-1.5">
              {onRestore !== undefined && (
                <button
                  type="button"
                  className="text-xs font-medium text-accent hover:underline dark:text-accent"
                  onClick={() => onRestore(row)}
                >
                  Restore
                </button>
              )}
              {onDelete !== undefined && (
                <button
                  type="button"
                  className="text-xs font-medium text-bad hover:underline"
                  onClick={() => onDelete(row)}
                >
                  Delete
                </button>
              )}
            </span>
          </Td>
        </Tr>
      ))}
    </DataTable>
  );
}

/** Addon rows with enable/disable state. */
export function AddonRows({ rows }: { rows: NonNullable<ActionResultData['addons']> }) {
  if (rows.length === 0) {
    return <EmptyState title="No addons in the catalogue" />;
  }

  return (
    <DataTable
      head={
        <>
          <Th>Addon</Th>
          <Th>Type</Th>
          <Th>State</Th>
        </>
      }
    >
      {rows.map((row) => (
        <Tr key={row.name}>
          <Td>
            <p className="font-medium">{humanizeName(row.name)}</p>
            <p className="text-[12px] text-muted dark:text-night-muted">{row.description}</p>
          </Td>
          <Td className="text-muted dark:text-night-muted">{row.type}</Td>
          <Td>
            <Badge tone={row.enabled ? 'ok' : 'neutral'}>
              {row.enabled ? 'enabled' : 'disabled'}
            </Badge>
          </Td>
        </Tr>
      ))}
    </DataTable>
  );
}

/** Cache entries with sizes. */
export function CacheRows({ rows }: { rows: NonNullable<ActionResultData['cache']> }) {
  const entries = Object.entries(rows);

  if (entries.length === 0) {
    return <EmptyState title="No cache locations found" />;
  }

  return (
    <ul className="grid gap-2 p-3 sm:grid-cols-2">
      {entries.map(([name, entry]) => (
        <li
          key={name}
          className="rounded-lg border border-line px-3 py-2 dark:border-night-line"
        >
          <div className="flex items-baseline justify-between gap-2">
            <p className="text-sm font-medium">{humanizeName(name)}</p>
            <p className="text-sm font-semibold tabular-nums">{bytes(entry.bytes)}</p>
          </div>
          <p className="truncate font-mono text-[11px] text-muted dark:text-night-muted" title={entry.path}>
            {shortenPath(entry.path)}
          </p>
        </li>
      ))}
    </ul>
  );
}

/**
 * Render whatever a read action returned.
 *
 * Known shapes get a real view; anything else is pretty-printed JSON so a new
 * PHP action is still usable from the dashboard on the day it ships.
 */
export function ReadResult({ data }: { data: ActionResultData | undefined }) {
  if (data === undefined) {
    return <EmptyState title="Nothing to show" />;
  }

  if (data.lines !== undefined) {
    return <LogPane lines={data.lines} />;
  }

  if (data.services !== undefined) {
    return <ServiceRows rows={data.services} />;
  }

  if (data.health !== undefined) {
    return <HealthRows rows={data.health} />;
  }

  if (data.certificates !== undefined) {
    return <CertificateRows rows={data.certificates} />;
  }

  if (data.snapshots !== undefined) {
    return <SnapshotRows rows={data.snapshots} />;
  }

  if (data.addons !== undefined) {
    return <AddonRows rows={data.addons} />;
  }

  if (data.cache !== undefined) {
    return <CacheRows rows={data.cache} />;
  }

  if (data.backups !== undefined) {
    return <BackupRows rows={data.backups} />;
  }

  if (data.databases !== undefined) {
    return <NameList items={data.databases} />;
  }

  if (data.profiles !== undefined) {
    return (
      <NameList
        items={data.profiles.map((profile) => `${profile.name} (${profile.scope})`)}
      />
    );
  }

  if (data.node !== undefined) {
    return (
      <div className="grid gap-2 p-3">
        <p className="text-sm">
          Current:{' '}
          <span className="font-mono font-semibold">{data.node.current ?? 'none'}</span>
          {data.node.available && (
            <Badge tone="ok" className="ml-2">
              nvm available
            </Badge>
          )}
        </p>
        <p className="text-xs text-muted dark:text-night-muted">
          Installed: {data.node.installed.join(', ') || 'none'}
        </p>
      </div>
    );
  }

  if (data.diagnose !== undefined) {
    return (
      <CodeBlock maxHeight="40rem">
        {JSON.stringify(data.diagnose, null, 2)}
      </CodeBlock>
    );
  }

  return (
    <CodeBlock maxHeight="40rem">{JSON.stringify(data, null, 2)}</CodeBlock>
  );
}
