<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The request-scoped blueprint lifecycle state resolved by
 * {@see BlueprintLifecycleResolver} (#2785, ADR-023 D-5).
 *
 * Only `Proposed`, `Approved`, and `Rejected` are resolvable from a manifest
 * and an optional request-scoped {@see BlueprintDecisionReceipt} — the only
 * evidence #2785 owns. `Applied` and `Superseded` additionally require
 * `.waaseyaa/generated.json` evidence, which is #2787's initializer/doctor
 * extension; they are deliberately NOT cases here and must not be added by
 * this issue.
 *
 * @api
 */
enum BlueprintLifecycle: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
