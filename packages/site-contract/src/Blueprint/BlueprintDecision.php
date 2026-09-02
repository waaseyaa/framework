<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The closed decision a {@see BlueprintDecisionReceipt} may carry (#2785,
 * ADR-023 D-4). A rejection can never be treated as approval.
 *
 * @api
 */
enum BlueprintDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
