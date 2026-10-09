/**
 * The action modal.
 *
 * One dialog drives every mutation in the dashboard. Its shape comes entirely
 * from /api/catalog: the parameter list, the types, the allowed values, whether
 * the action is destructive and what has to be typed to confirm it. That means
 * a new PHP action appears in the UI the moment it is added to
 * DashboardApi::ACTIONS, with no front-end change.
 */

import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';

import { useAction, useActionForm, useJob } from '../lib/hooks';
import { cx } from '../lib/format';
import { useToast } from '../lib/toast';
import type { ActionDefinition, ParamValues, Privileges } from '../types';
import {
  Badge,
  Button,
  CodeBlock,
  ErrorNote,
  Field,
  Input,
  Modal,
  Select,
  Spinner,
  Toggle,
} from './ui';

/** Tier labels shown in the modal header. */
const TIER_LABEL: Record<ActionDefinition['tier'], string> = {
  read: 'Read only',
  user: 'Runs as you',
  root: 'Needs root',
};

/** Render the right control for one parameter. */
function ParamField({
  name,
  param,
  value,
  onChange,
  disabled,
}: {
  name: string;
  param: ActionDefinition['params'][string];
  value: string | number | boolean | undefined;
  onChange: (value: string | number | boolean) => void;
  disabled: boolean;
}) {
  const id = `param-${name}`;
  const label = name.replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase());
  const required = param.required ? ' *' : '';

  if (param.type === 'bool') {
    return (
      <Toggle
        checked={value === true}
        onChange={onChange}
        label={label + required}
        hint={param.default === true ? 'On by default' : 'Off by default'}
        disabled={disabled}
      />
    );
  }

  if (param.type === 'int') {
    return (
      <Field label={label + required} htmlFor={id}>
        <Input
          id={id}
          type="number"
          inputMode="numeric"
          value={typeof value === 'number' ? value : ''}
          onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))}
          disabled={disabled}
        />
      </Field>
    );
  }

  if (param.in !== null && param.in.length > 0) {
    return (
      <Field label={label + required} htmlFor={id}>
        <Select
          id={id}
          value={typeof value === 'string' ? value : ''}
          onChange={(event) => onChange(event.target.value)}
          disabled={disabled}
        >
          <option value="">{param.required ? 'Choose…' : 'Not set'}</option>
          {param.in.map((choice) => (
            <option key={choice} value={choice}>
              {choice}
            </option>
          ))}
        </Select>
      </Field>
    );
  }

  const placeholder =
    param.type === 'path'
      ? '/absolute/path'
      : param.type === 'database'
        ? 'database_name'
        : param.type === 'archive'
          ? '/path/to/archive.tar.gz'
          : '';

  return (
    <Field
      label={label + required}
      htmlFor={id}
      hint={param.default !== null ? `Default: ${String(param.default)}` : undefined}
    >
      <Input
        id={id}
        type="text"
        value={typeof value === 'string' ? value : String(value ?? '')}
        placeholder={placeholder}
        onChange={(event) => onChange(event.target.value)}
        disabled={disabled}
      />
    </Field>
  );
}

export interface ActionModalProps {
  action: ActionDefinition;
  privileges: Privileges;
  /** Pre-filled parameters, e.g. the site a row belongs to. */
  initialValues?: ParamValues;
  onClose: () => void;
  /** Called after a successful run, so callers can refresh local state. */
  onDone?: () => void;
}

export function ActionModal({ action, privileges, initialValues, onClose, onDone }: ActionModalProps) {
  const { push } = useToast();
  const mutation = useAction();
  const [jobId, setJobId] = useState<string | null>(null);
  const form = useActionForm(action);

  // Seed any caller-provided values (site name, database, …).
  useEffect(() => {
    if (initialValues === undefined) {
      return;
    }

    for (const [name, value] of Object.entries(initialValues)) {
      form.setValue(name, value);
    }
    // Deliberately keyed on the action only: re-seeding on every render would
    // fight the user's typing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [action.slug]);

  const running = mutation.isPending;
  const rootBlocked = action.tier === 'root' && !privileges.enabled;
  const confirmTarget = form.confirmTarget;

  const job = useJob(jobId);
  const jobState = job.data?.data.job ?? null;
  const jobFinished =
    jobState !== null && jobState.status !== 'queued' && jobState.status !== 'running';

  // A finished job is a result too: say so and let the caller refresh.
  useEffect(() => {
    if (!jobFinished || jobState === null) {
      return;
    }

    push({
      tone: jobState.status === 'done' ? 'success' : 'error',
      title: `${action.label} ${jobState.status}`,
      description: jobState.message || undefined,
    });

    onDone?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [jobFinished, jobState?.status]);

  const entries = useMemo(() => Object.entries(action.params), [action]);

  function submit() {
    if (!form.canSubmit || rootBlocked) {
      return;
    }

    mutation.mutate(
      { slug: action.slug, params: form.values },
      {
        onSuccess: (envelope) => {
          if (envelope.job !== null) {
            setJobId(envelope.job);
            push({
              tone: 'info',
              title: `${action.label} started`,
              description: 'Running in the background. You can close this dialog.',
            });
            return;
          }

          push({
            tone: 'success',
            title: action.label,
            description: envelope.message || undefined,
          });

          onDone?.();
          onClose();
        },
        onError: (error) => {
          push({ tone: 'error', title: `${action.label} failed`, description: error.message });
        },
      },
    );
  }

  return (
    <Modal
      open
      onClose={onClose}
      busy={running}
      title={
        <span className="flex flex-wrap items-center gap-2">
          {action.label}
          <Badge tone={action.tier === 'root' ? 'warn' : action.tier === 'user' ? 'accent' : 'neutral'}>
            {TIER_LABEL[action.tier]}
          </Badge>
          {action.destructive && <Badge tone="bad">Destructive</Badge>}
          {action.job && <Badge tone="neutral">Background job</Badge>}
        </span>
      }
      description={<code className="font-mono text-[11px]">{action.slug}</code>}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={running}>
            {jobId !== null && !jobFinished ? 'Run in background' : 'Cancel'}
          </Button>
          <Button
            variant={action.destructive ? 'danger' : 'primary'}
            onClick={submit}
            disabled={!form.canSubmit || running || rootBlocked}
          >
            {running ? (
              <>
                <Spinner className="size-3.5" /> Running…
              </>
            ) : (
              action.label
            )}
          </Button>
        </>
      }
    >
      {entries.length === 0 && (
        <p className="text-sm text-muted dark:text-night-muted">
          This action takes no parameters and cannot be undone once confirmed.
        </p>
      )}

      {entries.map(([name, param]) => (
        <ParamField
          key={name}
          name={name}
          param={param}
          value={form.values[name]}
          onChange={(value) => form.setValue(name, value)}
          disabled={running}
        />
      ))}

      {rootBlocked && (
        <div className="rounded-lg border border-warn/30 bg-warn/10 px-3 py-2 text-[13px] text-warn dark:border-warn/40 dark:bg-warn/15">
          <p>This action needs the privileged helper, which is not installed yet.</p>
          <p className="mt-1 font-mono text-[11px]">{privileges.install_command}</p>
        </div>
      )}

      {confirmTarget !== null && (
        <Field
          label={`Type “${confirmTarget}” to confirm`}
          htmlFor="action-confirm"
          error={form.confirmInput !== '' && !form.confirmSatisfied ? 'Does not match.' : undefined}
        >
          <Input
            id="action-confirm"
            value={form.confirmInput}
            onChange={(event) => form.setConfirmInput(event.target.value)}
            placeholder={confirmTarget === true ? 'yes' : confirmTarget}
            autoComplete="off"
            disabled={running}
            className={cx(form.confirmSatisfied && form.confirmInput !== '' && 'border-ok')}
          />
        </Field>
      )}

      {form.missing.length > 0 && (
        <p className="text-xs text-muted dark:text-night-muted">
          Required: {form.missing.join(', ')}
        </p>
      )}

      <ErrorNote error={mutation.error} />

      {jobId !== null && (
        <div className="grid gap-2 rounded-lg border border-line bg-paper p-3 dark:border-night-line dark:bg-night">
          <div className="flex items-center justify-between gap-2 text-xs">
            <span className="font-mono text-muted dark:text-night-muted">{jobId}</span>
            <Badge
              tone={
                jobState?.status === 'done'
                  ? 'ok'
                  : jobState?.status === 'failed'
                    ? 'bad'
                    : 'accent'
              }
            >
              {jobState?.status ?? 'queued'}
            </Badge>
          </div>

          {jobState?.message && <p className="text-[13px]">{jobState.message}</p>}

          {jobState?.log !== undefined && jobState.log !== '' && (
            <CodeBlock maxHeight="10rem">{jobState.log}</CodeBlock>
          )}

          <Link
            to="/jobs"
            onClick={onClose}
            className="text-xs font-medium text-accent hover:underline dark:text-accent"
          >
            Open the activity feed →
          </Link>
        </div>
      )}
    </Modal>
  );
}
