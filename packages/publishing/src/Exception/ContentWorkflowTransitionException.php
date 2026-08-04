<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Exception;

/** A bound workflow offers no permitted transition to the requested visibility. */
final class ContentWorkflowTransitionException extends ContentPublishingException
{
    public function __construct(bool $published)
    {
        parent::__construct(
            'WORKFLOW_TRANSITION_UNAVAILABLE',
            $published
                ? 'No permitted workflow transition can publish this content from its current state.'
                : 'No permitted workflow transition can unpublish this content from its current state.',
        );
    }
}
