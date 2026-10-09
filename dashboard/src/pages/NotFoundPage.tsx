/** Shown for any URL the router does not know. */

import { Link } from 'react-router-dom';

import { Button, EmptyState } from '../components/ui';

export function NotFoundPage() {
  return (
    <EmptyState
      title="No such page"
      hint="The dashboard has eleven sections; this is not one of them."
      action={
        <Link to="/">
          <Button variant="primary" size="sm">
            Back to the overview
          </Button>
        </Link>
      }
    />
  );
}
