/**
 * Databases.
 *
 * MySQL and PostgreSQL behind one page, because which engine a project uses is
 * a detail of the project, not of the dashboard. The list comes from
 * databases.list / pg.databases.list and is refreshed after every change.
 */

import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';

import { QuickAction } from '../components/QuickAction';
import { NameList } from '../components/panels';
import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorNote,
  Input,
  PageHeader,
  Spinner,
} from '../components/ui';
import { useActionsApi } from '../lib/actionContext';
import { useData, useReadAction } from '../lib/hooks';
import { matches } from '../lib/format';
import type { ActionSlug } from '../types';

type Engine = 'mysql' | 'postgres';

export function DatabasesPage() {
  const { data, isFetching } = useData();
  const { open } = useActionsApi();

  const [engine, setEngine] = useState<Engine>('mysql');
  const [search, setSearch] = useState('');

  const slug: ActionSlug = engine === 'mysql' ? 'databases.list' : 'pg.databases.list';
  const prefix = engine === 'mysql' ? 'db' : 'pg';

  const list = useReadAction<{ databases: string[] }>(slug, {});

  const databases = list.data?.databases ?? [];
  const visible = useMemo(
    () => databases.filter((name) => matches(name, search)),
    [databases, search],
  );

  return (
    <div className="grid gap-4">
      <PageHeader
        title="Databases"
        description="Create, drop, import and export the databases behind your sites."
        actions={
          <>
            <Button
              size="sm"
              onClick={() => void list.refetch()}
              disabled={list.isFetching}
              title="Reload the list"
            >
              {list.isFetching ? <Spinner className="size-3.5" /> : '↻'}
            </Button>
            <QuickAction
              slug={`${prefix}.create` as ActionSlug}
              variant="primary"
              size="sm"
            >
              New {engine === 'mysql' ? 'MySQL' : 'PostgreSQL'} database
            </QuickAction>
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="inline-flex rounded-lg border border-line p-0.5 dark:border-night-line">
          {(['mysql', 'postgres'] as Engine[]).map((option) => (
            <button
              key={option}
              type="button"
              onClick={() => setEngine(option)}
              className={
                option === engine
                  ? 'rounded-md bg-accent px-3 py-1.5 text-[13px] font-medium text-white'
                  : 'rounded-md px-3 py-1.5 text-[13px] font-medium text-muted hover:text-ink dark:text-night-muted dark:hover:text-night-ink'
              }
            >
              {option === 'mysql' ? 'MySQL' : 'PostgreSQL'}
            </button>
          ))}
        </div>

        <Input
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Filter databases…"
          aria-label="Filter databases"
          className="max-w-xs"
        />

        {isFetching && <span className="text-[11px] text-muted dark:text-night-muted">syncing…</span>}
      </div>

      <ErrorNote error={list.error} />

      <Card>
        <CardHeader
          title={`${engine === 'mysql' ? 'MySQL' : 'PostgreSQL'} databases`}
          subtitle={`${visible.length} of ${databases.length} shown`}
          actions={
            <Button
              size="sm"
              variant="ghost"
              onClick={() => open('db.url', { name: visible[0] ?? '' })}
              disabled={visible.length === 0}
            >
              Show a URL
            </Button>
          }
        />

        {list.isLoading ? (
          <div className="grid place-items-center py-10">
            <Spinner className="size-5" />
          </div>
        ) : visible.length === 0 ? (
          <EmptyState
            title={databases.length === 0 ? 'No databases yet' : 'Nothing matches that filter'}
            hint={
              databases.length === 0
                ? 'Create one, or check that the database service is running.'
                : 'Try a shorter search term.'
            }
          />
        ) : (
          <ul className="divide-y divide-line dark:divide-night-line">
            {visible.map((name) => (
              <li key={name} className="flex flex-wrap items-center gap-2 px-4 py-2.5">
                <span className="min-w-0 flex-1 truncate font-mono text-[13px]">{name}</span>
                <span className="flex flex-wrap items-center gap-1.5">
                  <QuickAction
                    slug={`${prefix}.export` as ActionSlug}
                    values={{ name }}
                    size="sm"
                    variant="ghost"
                  >
                    Export
                  </QuickAction>
                  <QuickAction
                    slug={`${prefix}.import` as ActionSlug}
                    values={{ name }}
                    size="sm"
                    variant="ghost"
                  >
                    Import
                  </QuickAction>
                  <QuickAction
                    slug={`${prefix}.reset` as ActionSlug}
                    values={{ name }}
                    size="sm"
                    variant="ghost"
                    title={`Drop every table in ${name} and rebuild it`}
                  >
                    Reset
                  </QuickAction>
                  <QuickAction
                    slug={`${prefix}.drop` as ActionSlug}
                    values={{ name }}
                    size="sm"
                    variant="ghost"
                  >
                    Drop
                  </QuickAction>
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader title="All names" subtitle="Useful when copying a DATABASE_URL" />
        <NameList items={visible} />
      </Card>

      <p className="text-[11px] text-muted dark:text-night-muted">
        Imports and exports run as background jobs — follow them in the{' '}
        <Link to="/jobs" className="text-accent hover:underline dark:text-accent">
          activity feed
        </Link>
        . The database UI itself lives at{' '}
        <a
          href={data?.data.dashboard.database_url}
          target="_blank"
          rel="noreferrer"
          className="text-accent hover:underline dark:text-accent"
        >
          {data?.data.dashboard.database_url ?? 'database.valet.test'}
        </a>
        <Badge tone="neutral" className="ml-1.5">
          {engine}
        </Badge>
      </p>
    </div>
  );
}
