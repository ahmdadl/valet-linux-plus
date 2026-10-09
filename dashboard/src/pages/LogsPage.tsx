/**
 * Logs and diagnostics.
 *
 * Reading logs is a read-tier action, so this page can be used from anywhere —
 * including a phone on the same network. Everything else here is a one-shot
 * read that shells out on the server.
 */

import { useState } from 'react';

import { QuickAction } from '../components/QuickAction';
import { HealthRows, ReadResult } from '../components/panels';
import {
  Card,
  CardHeader,
  ErrorNote,
  Field,
  Input,
  PageHeader,
  Select,
  Spinner,
} from '../components/ui';
import { useReadAction } from '../lib/hooks';
import type { DiagnoseResult, HealthResult, LogsResult } from '../types';

/** The service names logs.view understands. */
const LOG_SERVICES = ['nginx', 'php', 'mailpit', 'mysql', 'redis', 'postgres', 'dnsmasq'];

/** How many lines to read by default. */
const DEFAULT_LINES = 200;

export function LogsPage() {
  const [service, setService] = useState('nginx');
  const [lines, setLines] = useState(DEFAULT_LINES);
  const [grep, setGrep] = useState('');

  const logs = useReadAction<LogsResult>('logs.view', { service, lines, grep });
  const health = useReadAction<HealthResult>('health.run');
  const diagnose = useReadAction<DiagnoseResult>('diagnose.run');

  const logLines = logs.data?.lines ?? [];

  return (
    <div className="grid gap-4">
      <PageHeader
        title="Logs & diagnostics"
        description="Tail a service, check the health of everything, or collect a full report."
        actions={
          <QuickAction slug="diagnose.run" variant="primary" size="sm">
            Run diagnostics
          </QuickAction>
        }
      />

      <Card>
        <CardHeader
          title="Service logs"
          subtitle="Read straight from the journal or the log file"
          actions={
            <button
              type="button"
              onClick={() => void logs.refetch()}
              disabled={logs.isFetching}
              className="text-xs font-medium text-accent hover:underline disabled:opacity-50 dark:text-accent"
            >
              {logs.isFetching ? 'Reading…' : 'Reload'}
            </button>
          }
        />

        <div className="grid gap-3 border-b border-line p-3 sm:grid-cols-[12rem_8rem_1fr] dark:border-night-line">
          <Field label="Service" htmlFor="log-service">
            <Select
              id="log-service"
              value={service}
              onChange={(event) => setService(event.target.value)}
            >
              {LOG_SERVICES.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Lines" htmlFor="log-lines">
            <Input
              id="log-lines"
              type="number"
              min={10}
              max={1000}
              step={10}
              value={lines}
              onChange={(event) => setLines(Number(event.target.value))}
            />
          </Field>

          <Field label="Filter" htmlFor="log-grep" hint="Case-sensitive substring match">
            <Input
              id="log-grep"
              value={grep}
              onChange={(event) => setGrep(event.target.value)}
              placeholder="error, warning, 127.0.0.1…"
            />
          </Field>
        </div>

        <ErrorNote error={logs.error} />

        {logs.isFetching && logLines.length === 0 ? (
          <div className="grid place-items-center py-10">
            <Spinner className="size-5" />
          </div>
        ) : (
          <ReadResult data={{ lines: logLines }} />
        )}
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader
            title="Health"
            subtitle="One check per service"
            actions={
              <button
                type="button"
                onClick={() => void health.refetch()}
                disabled={health.isFetching}
                className="text-xs font-medium text-accent hover:underline disabled:opacity-50 dark:text-accent"
              >
                {health.isFetching ? 'Checking…' : 'Run'}
              </button>
            }
          />
          <ErrorNote error={health.error} />
          {health.data === undefined ? (
            <p className="px-4 py-6 text-[13px] text-muted dark:text-night-muted">
              Run a check to see whether Nginx, DNS, PHP-FPM, mail and the databases are answering.
            </p>
          ) : (
            <HealthRows rows={health.data.health} />
          )}
        </Card>

        <Card>
          <CardHeader
            title="Diagnostics"
            subtitle="Everything the doctor collects, as JSON"
            actions={
              <button
                type="button"
                onClick={() => void diagnose.refetch()}
                disabled={diagnose.isFetching}
                className="text-xs font-medium text-accent hover:underline disabled:opacity-50 dark:text-accent"
              >
                {diagnose.isFetching ? 'Collecting…' : 'Run'}
              </button>
            }
          />
          <ErrorNote error={diagnose.error} />
          {diagnose.data === undefined ? (
            <p className="px-4 py-6 text-[13px] text-muted dark:text-night-muted">
              Collect a report to attach to a bug report: OS, package and service managers, PHP
              versions, Nginx configuration, DNS, paths and the Valet version.
            </p>
          ) : (
            <ReadResult data={{ diagnose: diagnose.data.diagnose }} />
          )}
        </Card>
      </div>
    </div>
  );
}
