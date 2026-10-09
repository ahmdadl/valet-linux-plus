/**
 * A single place to open any action.
 *
 * Pages describe what they want done ("unlink this site") rather than how to
 * build a dialog for it. The provider owns one modal instance, seeded from the
 * catalogue, so a page never has to know that an action needs a confirmation
 * value, a path or a version picker.
 */

import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';

import { ActionModal } from '../components/ActionModal';
import { useActions, useData } from './hooks';
import type { ActionSlug, ParamValues } from '../types';

interface ActionApi {
  /** Open the dialog for an action, optionally pre-filled. */
  open: (slug: ActionSlug, values?: ParamValues) => void;
  /** Run an action immediately, without a dialog. */
  run: (slug: ActionSlug, values?: ParamValues) => void;
  /** Whether the catalogue has loaded. */
  ready: boolean;
}

const ActionContext = createContext<ActionApi | null>(null);

export function ActionProvider({ children }: { children: ReactNode }) {
  const { actions, ready } = useActions();
  const { data } = useData();
  const [current, setCurrent] = useState<{ slug: ActionSlug; values?: ParamValues } | null>(null);

  const open = useCallback((slug: ActionSlug, values?: ParamValues) => {
    setCurrent({ slug, values });
  }, []);

  const run = useCallback((slug: ActionSlug, values?: ParamValues) => {
    // A "run" is just an open with no thought required: parameterless actions
    // show a dialog with only a confirm button, which is the point — the user
    // sees exactly what will happen and can still back out.
    setCurrent({ slug, values });
  }, []);

  const privileges = data?.data.privileges;

  const value = useMemo(() => ({ open, run, ready }), [open, run, ready]);

  const definition = current !== null ? actions.get(current.slug) : undefined;

  return (
    <ActionContext.Provider value={value}>
      {children}

      {current !== null && definition !== undefined && privileges !== undefined && (
        <ActionModal
          action={definition}
          privileges={privileges}
          initialValues={current.values}
          onClose={() => setCurrent(null)}
        />
      )}
    </ActionContext.Provider>
  );
}

export function useActionsApi(): ActionApi {
  const context = useContext(ActionContext);

  if (context === null) {
    throw new Error('useActionsApi must be used inside <ActionProvider>.');
  }

  return context;
}
