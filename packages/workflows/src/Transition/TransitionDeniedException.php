<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Transition;

/**
 * Typed denial thrown by {@see TransitionService::transition()} and the
 * save-path guard when a state change fails validation. Fail closed, deny
 * loudly: a machine-readable reason, never a silent no-op (CW-v1 WP-1,
 * docs/specs/content-workflow.md, design invariant 5).
 *
 * @api
 */
final class TransitionDeniedException extends \RuntimeException
{
    public const string REASON_UNBOUND = 'unbound';
    public const string REASON_UNKNOWN_TRANSITION = 'unknown_transition';
    public const string REASON_ILLEGAL_EDGE = 'illegal_edge';
    public const string REASON_PERMISSION = 'permission';
    public const string REASON_GROUP_CONSTRAINT = 'group_constraint';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
