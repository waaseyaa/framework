<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The blueprint lifecycle state resolved from request and generated evidence by
 * {@see BlueprintLifecycleResolver} (#2785, ADR-023 D-5).
 *
 * @api
 */
enum BlueprintLifecycle: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
