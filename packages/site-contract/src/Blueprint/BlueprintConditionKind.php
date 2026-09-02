<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The closed set of policy condition kinds a blueprint may declare (#2785).
 *
 * No expression, script, callable, regex, or free-form text is accepted
 * anywhere in a policy condition — every condition is one of these three
 * closed, declarative shapes.
 *
 * @api
 */
enum BlueprintConditionKind: string
{
    case Permission = 'permission';
    case Ownership = 'ownership';
    case WorkflowState = 'workflow_state';
}
