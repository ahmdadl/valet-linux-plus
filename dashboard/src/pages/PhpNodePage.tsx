/**
 * PHP and Node.
 *
 * The global PHP version, per-site isolation, Xdebug and the Node version nvm
 * is pointed at. Switching either is root-tier and changes what every new site
 * runs, so the confirmations matter here more than anywhere else.
 */

import { QuickAction } from '../components/QuickAction';
import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorNote,
  PageHeader,
  Spinner,
} from '../components/ui';
import { useData, useReadAction } from '../lib/hooks';
import { humanizeName } from '../lib/format';
import type { NodeResult } from '../types';

export function PhpNodePage() {
  const { data, error } = useData();
  const node = useReadAction<NodeResult>('node.current');

  if (error) {
    return <ErrorNote error={error} />;
  }

  const dashboard = data?.data.dashboard;

  if (dashboard === undefined) {
    return (
      <div className="grid place-items-center py-24">
        <Spinner className="size-6" />
      </div>
    );
  }

  const installed = dashboard.php_versions.length > 0 ? dashboard.php_versions : [];
  const isolated = dashboard.sites.filter((site) => site.isolated !== null);

  return (
    <div className="grid gap-4">
      <PageHeader
        title="PHP & Node"
        description="What your sites run on, and what you run on the command line."
        actions={
          <QuickAction slug="php.switch" variant="primary" size="sm">
            Switch PHP
          </QuickAction>
        }
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader
            title="Global PHP version"
            subtitle="Used by every site that is not isolated"
          />
          <div className="grid gap-3 p-4">
            <p className="flex items-baseline gap-2">
              <span className="text-3xl font-semibold tracking-tight tabular-nums">
                {dashboard.php_version}
              </span>
              <Badge tone="accent">current</Badge>
            </p>

            <div className="flex flex-wrap gap-1.5">
              {installed.map((version) => (
                <QuickAction
                  key={version}
                  slug="php.switch"
                  values={{ version }}
                  size="sm"
                  variant={version === dashboard.php_version ? 'primary' : 'secondary'}
                  title={
                    version === dashboard.php_version
                      ? 'Already active'
                      : `Switch the whole machine to PHP ${version}`
                  }
                >
                  {version}
                </QuickAction>
              ))}
            </div>

            {installed.length === 0 && (
              <p className="text-[13px] text-muted dark:text-night-muted">
                No other PHP versions were detected.
              </p>
            )}

            <div className="flex flex-wrap gap-2 border-t border-line pt-3 dark:border-night-line">
              <QuickAction slug="xdebug.enable" size="sm" variant="ghost">
                Enable Xdebug
              </QuickAction>
              <QuickAction slug="xdebug.disable" size="sm" variant="ghost">
                Disable Xdebug
              </QuickAction>
            </div>
          </div>
        </Card>

        <Card>
          <CardHeader
            title="Node"
            subtitle="Through nvm; switching it is per-user, not per-site"
            actions={
              <Button size="sm" variant="ghost" onClick={() => void node.refetch()} disabled={node.isFetching}>
                {node.isFetching ? <Spinner className="size-3.5" /> : '↻'}
              </Button>
            }
          />
          <div className="grid gap-3 p-4">
            {node.error ? (
              <ErrorNote error={node.error} />
            ) : node.data === undefined ? (
              <Spinner className="size-5" />
            ) : (
              <>
                <p className="flex items-baseline gap-2">
                  <span className="text-3xl font-semibold tracking-tight tabular-nums">
                    {node.data.node.current ?? 'none'}
                  </span>
                  {node.data.node.available ? (
                    <Badge tone="ok">nvm available</Badge>
                  ) : (
                    <Badge tone="warn">nvm not found</Badge>
                  )}
                </p>

                <div className="flex flex-wrap gap-1.5">
                  {node.data.node.installed.map((version) => (
                    <QuickAction
                      key={version}
                      slug="node.use"
                      values={{ version }}
                      size="sm"
                      variant={version === node.data?.node.current ? 'primary' : 'secondary'}
                    >
                      {version}
                    </QuickAction>
                  ))}
                </div>

                {node.data.node.installed.length === 0 && (
                  <p className="text-[13px] text-muted dark:text-night-muted">
                    No Node versions are installed yet.
                  </p>
                )}
              </>
            )}
          </div>
        </Card>
      </div>

      <Card>
        <CardHeader
          title="Isolated sites"
          subtitle="Sites pinned to a PHP version of their own"
          actions={
            <QuickAction slug="site.isolate" size="sm">
              Isolate a site
            </QuickAction>
          }
        />
        {isolated.length === 0 ? (
          <EmptyState
            title="No isolated sites"
            hint="Isolation lets one site run an older PHP while the rest of the machine moves on."
          />
        ) : (
          <ul className="divide-y divide-line dark:divide-night-line">
            {isolated.map((site) => (
              <li key={site.name} className="flex items-center gap-3 px-4 py-2.5">
                <span className="min-w-0 flex-1 truncate text-[13px] font-medium">
                  {site.name}
                </span>
                <Badge tone="accent">PHP {String(site.isolated)}</Badge>
                <QuickAction
                  slug="site.unisolate"
                  values={{ name: site.name }}
                  size="sm"
                  variant="ghost"
                >
                  Unisolate
                </QuickAction>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader title="Switchable versions" subtitle="Reported by the PHP-FPM service" />
        <ul className="flex flex-wrap gap-1.5 p-3">
          {installed.map((version) => (
            <li key={version}>
              <Badge tone={version === dashboard.php_version ? 'accent' : 'neutral'}>
                {humanizeName(`php${version}`)}
              </Badge>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}
