/**
 * A button that runs an action.
 *
 * Every button in the dashboard that changes something is one of these: it
 * names the action slug and the values it applies to, and the shared modal
 * supplies the confirmation, the missing parameters and the tier rules.
 */

import { useActionsApi } from '../lib/actionContext';
import type { ActionSlug, ParamValues } from '../types';
import { Button, type ButtonProps } from './ui';

export interface QuickActionProps extends Omit<ButtonProps, 'onClick'> {
  slug: ActionSlug;
  values?: ParamValues;
  /** Shown as the button's accessible name when there is no visible label. */
  title?: string;
}

export function QuickAction({ slug, values, children, title, ...props }: QuickActionProps) {
  const { open } = useActionsApi();

  return (
    <Button {...props} title={title} onClick={() => open(slug, values)}>
      {children}
    </Button>
  );
}
