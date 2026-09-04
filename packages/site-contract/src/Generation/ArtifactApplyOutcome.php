<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * How one artifact apply terminated (ADR-025 D-6.4).
 *
 * `Planned` and `Cancelled` describe a return value rather than a durable
 * event -- they absorb today's `dryRun` and `cancelled` booleans -- which is
 * why neither maps onto a change-receipt outcome (D-14.4).
 *
 * @api
 */
enum ArtifactApplyOutcome: string
{
    case Planned = 'planned';
    case Applied = 'applied';
    case NoChanges = 'no_changes';
    case Cancelled = 'cancelled';
    case Refused = 'refused';
}
