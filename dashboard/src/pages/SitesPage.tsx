/**
 * Sites.
 *
 * One table for every kind of site — parked, linked and proxied — because the
 * user thinks of them as the same thing. Actions are offered per row from the
 * same catalogue the modal uses.
 */

import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';

import { QuickAction } from '../components/QuickAction';
import {
  Badge,
  Button,
  Card,
  DataTable,
  EmptyState,
  ErrorNote,
  Input,
  PageHeader,
  Select,
  Td,
  Th,
  Tr,
} from '../components/ui';
import { useActionsApi } from '../lib/actionContext';
import { useData } from '../lib/hooks';
import { matches, shortenPath } from '../lib/format';

type TypeFilter = 'all' | 'parked' | 'linked' | 'proxy';
type StateFilter = 'all' | 'secured' | 'isolated';

export function SitesPage() {
  const { data, isLoading, error } = useData();
  const { open } = useActionsApi();

  const [search, setSearch] = useState('');
  const [type, setType] = useState<TypeFilter>('all');
  const [state, setState] = useState<StateFilter>('all');

  const sites = data?.data.dashboard.sites ?? [];

  const visible = useMemo(() => {
    return sites.filter((site) => {
      if (!matches(site.name, search) && !matches(site.url, search) && !matches(site.path, search)) {
        return false;
      }

      if (type !== 'all' && site.type !== type) {
        return false;
      }

      if (state === 'secured' && !site.secured) {
        return false;
      }

      if (state === 'isolated' && site.isolated === null) {
        return false;
      }

      return true;
    });
  }, [sites, search, type, state]);

  if (error) {
    return <ErrorNote error={error} />;
  }

  const domain = data?.data.dashboard.domain ?? 'test';

  return (
    <div className="grid gap-4">
      <PageHeader
        title="Sites"
        description={`Every site served under *.${domain}, however it was added.`}
        actions={
          <>
            <QuickAction slug="site.park" size="sm">
              Park a path
            </QuickAction>
            <QuickAction slug="site.link" variant="primary" size="sm">
              Link a site
            </QuickAction>
          </>
        }
      />

      <Card>
        <div className="grid gap-2 border-b border-line p-3 sm:grid-cols-[1fr_auto_auto] dark:border-night-line">
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Filter by name, URL or path…"
            aria-label="Filter sites"
          />
          <Select
            value={type}
            onChange={(event) => setType(event.target.value as TypeFilter)}
            aria-label="Filter by type"
            className="sm:w-40"
          >
            <option value="all">All types</option>
            <option value="parked">Parked</option>
            <option value="linked">Linked</option>
            <option value="proxy">Proxied</option>
          </Select>
          <Select
            value={state}
            onChange={(event) => setState(event.target.value as StateFilter)}
            aria-label="Filter by state"
            className="sm:w-40"
          >
            <option value="all">Any state</option>
            <option value="secured">Secured</option>
            <option value="isolated">Isolated</option>
          </Select>
        </div>

        {isLoading && sites.length === 0 ? (
          <EmptyState title="Loading sites…" />
        ) : visible.length === 0 ? (
          <EmptyState
            title={sites.length === 0 ? 'No sites yet' : 'Nothing matches those filters'}
            hint={
              sites.length === 0
                ? 'Park your projects folder, or link a single directory.'
                : 'Try a different search term or clear the filters.'
            }
            action={
              sites.length === 0 ? (
                <QuickAction slug="site.park" variant="primary" size="sm">
                  Park a path
                </QuickAction>
              ) : (
                <Button
                  size="sm"
                  onClick={() => {
                    setSearch('');
                    setType('all');
                    setState('all');
                  }}
                >
                  Clear filters
                </Button>
              )
            }
          />
        ) : (
          <DataTable
            head={
              <>
                <Th>Site</Th>
                <Th>Type</Th>
                <Th>State</Th>
                <Th className="text-right">Actions</Th>
              </>
            }
          >
            {visible.map((site) => (
              <Tr key={site.name}>
                <Td>
                  <Link
                    to={`/sites/${encodeURIComponent(site.name)}`}
                    className="text-[13px] font-medium hover:text-accent hover:underline dark:hover:text-accent"
                  >
                    {site.name}
                  </Link>
                  <p className="truncate font-mono text-[11px] text-muted dark:text-night-muted" title={site.path}>
                    {site.proxy ? `proxy → ${site.proxy}` : shortenPath(site.path)}
                  </p>
                </Td>
                <Td>
                  <Badge tone={site.type === 'proxy' ? 'warn' : 'neutral'}>{site.type}</Badge>
                </Td>
                <Td>
                  <span className="flex flex-wrap items-center gap-1">
                    <Badge tone={site.secured ? 'ok' : 'neutral'}>
                      {site.secured ? 'https' : 'http'}
                    </Badge>
                    {site.isolated !== null && (
                      <Badge tone="accent">PHP {site.isolated}</Badge>
                    )}
                  </span>
                </Td>
                <Td>
                  <span className="flex flex-wrap items-center justify-end gap-1.5">
                    <a
                      href={site.url}
                      target="_blank"
                      rel="noreferrer"
                      className="rounded-md px-1.5 py-1 text-xs font-medium text-muted hover:text-ink dark:text-night-muted dark:hover:text-night-ink"
                    >
                      Visit
                    </a>

                    {site.secured ? (
                      <QuickAction
                        slug="site.unsecure"
                        values={{ name: site.name }}
                        size="sm"
                        variant="ghost"
                        title={`Remove the certificate for ${site.name}`}
                      >
                        Unsecure
                      </QuickAction>
                    ) : (
                      <QuickAction
                        slug="site.secure"
                        values={{ name: site.name }}
                        size="sm"
                        variant="ghost"
                        title={`Issue a trusted certificate for ${site.name}`}
                      >
                        Secure
                      </QuickAction>
                    )}

                    {site.proxy !== null ? (
                      <QuickAction
                        slug="site.unproxy"
                        values={{ name: site.name }}
                        size="sm"
                        variant="ghost"
                      >
                        Unproxy
                      </QuickAction>
                    ) : (
                      <QuickAction
                        slug="site.proxy"
                        values={{ name: site.name }}
                        size="sm"
                        variant="ghost"
                        title={`Forward ${site.name}.${domain} to another host`}
                      >
                        Proxy
                      </QuickAction>
                    )}

                    {site.isolated !== null ? (
                      <QuickAction
                        slug="site.unisolate"
                        values={{ name: site.name }}
                        size="sm"
                        variant="ghost"
                      >
                        Unisolate
                      </QuickAction>
                    ) : (
                      <QuickAction
                        slug="site.isolate"
                        values={{ name: site.name }}
                        size="sm"
                        variant="ghost"
                        title={`Pin ${site.name} to a specific PHP version`}
                      >
                        Isolate
                      </QuickAction>
                    )}

                    <Link
                      to={`/sites/${encodeURIComponent(site.name)}`}
                      className="rounded-md px-1.5 py-1 text-xs font-medium text-accent hover:underline dark:text-accent"
                    >
                      Details
                    </Link>
                  </span>
                </Td>
              </Tr>
            ))}
          </DataTable>
        )}

        <p className="border-t border-line px-4 py-2 text-[11px] text-muted dark:border-night-line dark:text-night-muted">
          Showing {visible.length} of {sites.length} sites.
        </p>
      </Card>

      <Card>
        <div className="grid gap-2 p-3 sm:grid-cols-3">
          <QuickAction
            slug="site.forget"
            className="justify-start"
            title="Stop parking a path"
          >
            Forget a parked path
          </QuickAction>
          <QuickAction slug="site.unlink" className="justify-start" title="Remove a link">
            Unlink a site
          </QuickAction>
          <Button
            className="justify-start"
            variant="secondary"
            onClick={() => open('site.link')}
            title="Link a directory under a name"
          >
            Link another directory
          </Button>
        </div>
      </Card>
    </div>
  );
}
