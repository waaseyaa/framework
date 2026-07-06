<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Event;

/**
 * Lifecycle event names dispatched around a workflow transition (CW-v1 WP-1,
 * docs/specs/content-workflow.md). Payload is {@see WorkflowTransitionEvent}.
 *
 * @api
 */
enum WorkflowEvents: string
{
    case PRE_TRANSITION = 'waaseyaa.workflow.pre_transition';
    case POST_TRANSITION = 'waaseyaa.workflow.post_transition';
}
