/**
 * UI primitives.
 *
 * A small hand-rolled set instead of a component library: the dashboard needs
 * about a dozen shapes, and owning them keeps the palette, the focus rings and
 * the keyboard behaviour consistent without a build-time dependency.
 */

import {
  forwardRef,
  useEffect,
  useId,
  useRef,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react';

import { cx } from '../lib/format';

/* ------------------------------------------------------------------ button */

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
type ButtonSize = 'sm' | 'md';

const BUTTON_BASE =
  'inline-flex items-center justify-center gap-2 rounded-lg font-medium whitespace-nowrap ' +
  'transition-colors disabled:cursor-not-allowed disabled:opacity-50';

const BUTTON_VARIANTS: Record<ButtonVariant, string> = {
  primary:
    'bg-accent text-white hover:bg-accent-strong shadow-sm ' +
    'dark:bg-accent dark:hover:bg-accent-strong',
  secondary:
    'bg-card text-ink border border-line hover:border-line-strong hover:bg-accent-soft/60 ' +
    'dark:bg-night-card dark:text-night-ink dark:border-night-line ' +
    'dark:hover:border-night-line-strong dark:hover:bg-white/5',
  ghost:
    'text-muted hover:text-ink hover:bg-line/60 ' +
    'dark:text-night-muted dark:hover:text-night-ink dark:hover:bg-white/5',
  danger:
    'bg-bad text-white hover:brightness-110 shadow-sm',
};

const BUTTON_SIZES: Record<ButtonSize, string> = {
  sm: 'h-8 px-2.5 text-[13px]',
  md: 'h-10 px-3.5 text-sm',
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'secondary', size = 'md', className, type = 'button', ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      className={cx(BUTTON_BASE, BUTTON_VARIANTS[variant], BUTTON_SIZES[size], className)}
      {...props}
    />
  );
});

/* -------------------------------------------------------------------- card */

export function Card({
  children,
  className,
  as: Tag = 'section',
}: {
  children: ReactNode;
  className?: string;
  as?: 'section' | 'div' | 'article' | 'li';
}) {
  return (
    <Tag
      className={cx(
        'rounded-[var(--radius-card)] border border-line bg-card shadow-[var(--shadow-card)]',
        'dark:border-night-line dark:bg-night-card',
        className,
      )}
    >
      {children}
    </Tag>
  );
}

export function CardHeader({
  title,
  subtitle,
  actions,
  className,
}: {
  title: ReactNode;
  subtitle?: ReactNode;
  actions?: ReactNode;
  className?: string;
}) {
  return (
    <header
      className={cx(
        'flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3',
        'dark:border-night-line',
        className,
      )}
    >
      <div className="min-w-0">
        <h2 className="truncate text-sm font-semibold tracking-tight">{title}</h2>
        {subtitle !== undefined && (
          <p className="mt-0.5 text-xs text-muted dark:text-night-muted">{subtitle}</p>
        )}
      </div>
      {actions !== undefined && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
    </header>
  );
}

/* ------------------------------------------------------------------- badge */

type BadgeTone = 'neutral' | 'accent' | 'ok' | 'warn' | 'bad';

const BADGE_TONES: Record<BadgeTone, string> = {
  neutral: 'bg-line/70 text-muted dark:bg-white/5 dark:text-night-muted',
  accent: 'bg-accent-soft text-accent-strong dark:bg-accent/15 dark:text-accent',
  ok: 'bg-ok/10 text-ok dark:bg-ok/15 dark:text-ok',
  warn: 'bg-warn/10 text-warn dark:bg-warn/15 dark:text-warn',
  bad: 'bg-bad/10 text-bad dark:bg-bad/15 dark:text-bad',
};

export function Badge({
  children,
  tone = 'neutral',
  className,
}: {
  children: ReactNode;
  tone?: BadgeTone;
  className?: string;
}) {
  return (
    <span
      className={cx(
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
        BADGE_TONES[tone],
        className,
      )}
    >
      {children}
    </span>
  );
}

/** A coloured dot plus label, used for service and health state. */
export function StatusDot({
  state,
  label,
  className,
}: {
  state: 'on' | 'off' | 'unknown';
  label: string;
  className?: string;
}) {
  const colour =
    state === 'on'
      ? 'bg-ok'
      : state === 'off'
        ? 'bg-line-strong dark:bg-night-line-strong'
        : 'bg-warn';

  return (
    <span className={cx('inline-flex items-center gap-1.5 text-xs', className)}>
      <span className={cx('size-2 shrink-0 rounded-full', colour)} aria-hidden="true" />
      <span className="text-muted dark:text-night-muted">{label}</span>
    </span>
  );
}

/* ------------------------------------------------------------------- forms */

export function Field({
  label,
  hint,
  error,
  children,
  htmlFor,
}: {
  label: string;
  hint?: string;
  error?: string;
  children: ReactNode;
  htmlFor?: string;
}) {
  return (
    <div className="grid gap-1.5">
      <label
        htmlFor={htmlFor}
        className="text-xs font-medium text-muted dark:text-night-muted"
      >
        {label}
      </label>
      {children}
      {error ? (
        <p className="text-xs text-bad">{error}</p>
      ) : hint ? (
        <p className="text-xs text-muted dark:text-night-muted">{hint}</p>
      ) : null}
    </div>
  );
}

const CONTROL =
  'h-10 w-full rounded-lg border border-line bg-card px-3 text-sm text-ink ' +
  'placeholder:text-muted/70 dark:border-night-line dark:bg-night-card dark:text-night-ink';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
  function Input({ className, ...props }, ref) {
    return <input ref={ref} className={cx(CONTROL, className)} {...props} />;
  },
);

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
  function Select({ className, children, ...props }, ref) {
    return (
      <select ref={ref} className={cx(CONTROL, 'pr-8', className)} {...props}>
        {children}
      </select>
    );
  },
);

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(
  function Textarea({ className, ...props }, ref) {
    return (
      <textarea
        ref={ref}
        className={cx(CONTROL, 'h-auto min-h-24 py-2 font-mono text-[13px] leading-relaxed', className)}
        {...props}
      />
    );
  },
);

/** A switch for boolean parameters. */
export function Toggle({
  checked,
  onChange,
  label,
  hint,
  disabled,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label: string;
  hint?: string;
  disabled?: boolean;
}) {
  const id = useId();

  return (
    <div className="flex items-start justify-between gap-4">
      <div className="min-w-0">
        <label htmlFor={id} className="text-sm font-medium">
          {label}
        </label>
        {hint !== undefined && (
          <p className="mt-0.5 text-xs text-muted dark:text-night-muted">{hint}</p>
        )}
      </div>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={cx(
          'relative h-6 w-11 shrink-0 rounded-full border transition-colors disabled:opacity-50',
          checked
            ? 'border-accent bg-accent'
            : 'border-line-strong bg-line dark:border-night-line-strong dark:bg-night-line',
        )}
      >
        <span
          className={cx(
            'absolute top-0.5 size-4.5 rounded-full bg-white shadow transition-[left]',
            checked ? 'left-[26px]' : 'left-0.5',
          )}
          aria-hidden="true"
        />
      </button>
    </div>
  );
}

/* ---------------------------------------------------------------- feedback */

export function Spinner({ className }: { className?: string }) {
  return (
    <span
      role="status"
      aria-label="Loading"
      className={cx(
        'inline-block size-4 animate-spin rounded-full border-2 border-line-strong border-t-accent',
        'dark:border-night-line-strong dark:border-t-accent',
        className,
      )}
    />
  );
}

export function EmptyState({
  title,
  hint,
  action,
}: {
  title: string;
  hint?: string;
  action?: ReactNode;
}) {
  return (
    <div className="grid place-items-center gap-2 px-6 py-10 text-center">
      <p className="text-sm font-medium">{title}</p>
      {hint !== undefined && (
        <p className="max-w-md text-xs text-muted dark:text-night-muted">{hint}</p>
      )}
      {action !== undefined && <div className="mt-1">{action}</div>}
    </div>
  );
}

/** Inline error box for a failed read or mutation. */
export function ErrorNote({ error }: { error: unknown }) {
  if (!error) {
    return null;
  }

  const message = error instanceof Error ? error.message : String(error);

  return (
    <div
      role="alert"
      className="rounded-lg border border-bad/30 bg-bad/10 px-3 py-2 text-[13px] text-bad dark:border-bad/40 dark:bg-bad/15"
    >
      {message}
    </div>
  );
}

/* ------------------------------------------------------------------ layout */

/** Page title block used at the top of every route. */
export function PageHeader({
  title,
  description,
  actions,
}: {
  title: string;
  description?: string;
  actions?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
        {description !== undefined && (
          <p className="mt-1 max-w-2xl text-sm text-muted dark:text-night-muted">{description}</p>
        )}
      </div>
      {actions !== undefined && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  );
}

/** A monospace block for paths, JSON and command output. */
export function CodeBlock({
  children,
  className,
  maxHeight = '20rem',
}: {
  children: ReactNode;
  className?: string;
  maxHeight?: string;
}) {
  return (
    <pre
      style={{ maxHeight }}
      className={cx(
        'scroll-thin overflow-auto rounded-lg border border-line bg-paper p-3 font-mono text-[12px] leading-relaxed',
        'dark:border-night-line dark:bg-night',
        className,
      )}
    >
      {children}
    </pre>
  );
}

/* ------------------------------------------------------------------- modal */

/**
 * The one modal in the app.
 *
 * Focus is trapped, Escape closes, and the backdrop click only closes when the
 * dialog is not busy — a running action must not be cancelled by a stray click.
 */
export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  busy = false,
}: {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  busy?: boolean;
}) {
  const panel = useRef<HTMLDivElement>(null);
  const titleId = useId();

  useEffect(() => {
    if (!open) {
      return;
    }

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape' && !busy) {
        onClose();
        return;
      }

      if (event.key !== 'Tab' || !panel.current) {
        return;
      }

      const focusable = panel.current.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]',
      );

      if (focusable.length === 0) {
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      }
    }

    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, [open, busy, onClose]);

  useEffect(() => {
    if (open) {
      panel.current?.querySelector<HTMLElement>('input, select, textarea, button')?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
      <div
        className="absolute inset-0 bg-ink/40 backdrop-blur-[2px] dark:bg-black/60"
        onClick={() => {
          if (!busy) {
            onClose();
          }
        }}
        aria-hidden="true"
      />
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={cx(
          'relative z-10 max-h-[92vh] w-full max-w-lg overflow-auto scroll-thin',
          'rounded-t-2xl border border-line bg-card shadow-[var(--shadow-pop)] sm:rounded-2xl',
          'dark:border-night-line dark:bg-night-card',
        )}
      >
        <header className="border-b border-line px-4 py-3 dark:border-night-line">
          <h2 id={titleId} className="text-sm font-semibold">
            {title}
          </h2>
          {description !== undefined && (
            <p className="mt-1 text-xs text-muted dark:text-night-muted">{description}</p>
          )}
        </header>

        <div className="grid gap-3 px-4 py-4">{children}</div>

        {footer !== undefined && (
          <footer className="flex items-center justify-end gap-2 border-t border-line px-4 py-3 dark:border-night-line">
            {footer}
          </footer>
        )}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------- table */

/**
 * A table that degrades to stacked cards on narrow screens, the way the
 * vanilla dashboard's sites table did.
 */
export function DataTable({
  head,
  children,
  className,
}: {
  head: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className="overflow-x-auto scroll-thin">
      <table className={cx('w-full min-w-[38rem] border-collapse text-sm', className)}>
        <thead>
          <tr className="border-b border-line text-left text-[11px] uppercase tracking-wide text-muted dark:border-night-line dark:text-night-muted">
            {head}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}

export function Th({
  children,
  className,
}: {
  children?: ReactNode;
  className?: string;
}) {
  return <th className={cx('px-4 py-2 font-medium', className)}>{children}</th>;
}

export function Td({
  children,
  className,
}: {
  children?: ReactNode;
  className?: string;
}) {
  return (
    <td className={cx('px-4 py-2.5 align-middle', className)}>{children}</td>
  );
}

export function Tr({
  children,
  className,
  onClick,
}: {
  children: ReactNode;
  className?: string;
  onClick?: () => void;
}) {
  return (
    <tr
      onClick={onClick}
      className={cx(
        'border-b border-line last:border-0 dark:border-night-line',
        onClick && 'cursor-pointer hover:bg-accent-soft/50 dark:hover:bg-white/5',
        className,
      )}
    >
      {children}
    </tr>
  );
}
