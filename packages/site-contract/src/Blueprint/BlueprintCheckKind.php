<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The closed set of generated behavioural checks a blueprint may declare
 * (#2785).
 *
 * @api
 */
enum BlueprintCheckKind: string
{
    case RolePermission = 'role_permission';
    case WorkflowTransition = 'workflow_transition';
    case EntityAccess = 'entity_access';
    case FixturePresent = 'fixture_present';
}
