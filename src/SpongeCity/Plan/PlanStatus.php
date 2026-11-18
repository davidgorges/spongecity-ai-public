<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use Symfony\Component\Workflow\Attribute\Place;

/**
 * Where a plan stands in the import of its site: the places of the PlanImport state machine.
 */
enum PlanStatus: string
{
    #[Place(description: 'Just created: its import is still to be requested', initial: true)]
    case New = 'new';

    #[Place(description: 'Queued: waiting for a worker to import its site')]
    case Pending = 'pending';

    #[Place(description: 'A worker is importing its site')]
    case Importing = 'importing';

    #[Place(description: 'Its site is imported: it can be planned')]
    case Ready = 'ready';

    #[Place(description: 'The import failed: it can be requested again')]
    case Failed = 'failed';

    /** the import is still to come or running: the page and the agent wait for it */
    public function isImporting(): bool
    {
        return self::New === $this || self::Pending === $this || self::Importing === $this;
    }
}
