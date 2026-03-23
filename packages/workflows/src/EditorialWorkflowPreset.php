<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

/**
 * Factory for the standard editorial workflow preset.
 *
 * Creates a Workflow config entity pre-populated with the 4 editorial
 * states (draft, review, published, archived) and 6 transitions.
 * Also provides editorial-specific utility methods for legacy status
 * mapping and state normalization.
 */
final class EditorialWorkflowPreset
{
    /**
     * Permission patterns keyed by transition ID.
     * Used by EditorialTransitionAccessResolver.
     */
    public const array TRANSITION_PERMISSIONS = [
        'submit_for_review' => 'submit {bundle} for review',
        'send_back' => 'return {bundle} to draft',
        'publish' => 'publish {bundle} content',
        'unpublish' => 'revert {bundle} to draft',
        'archive' => 'archive {bundle} content',
        'restore' => 'restore {bundle} content',
    ];

    public static function create(): Workflow
    {
        return new Workflow([
            'id' => 'editorial',
            'label' => 'Editorial',
            'states' => [
                'draft' => ['label' => 'Draft', 'weight' => 0, 'metadata' => ['legacy_status' => 0]],
                'review' => ['label' => 'Review', 'weight' => 1, 'metadata' => ['legacy_status' => 0]],
                'published' => ['label' => 'Published', 'weight' => 2, 'metadata' => ['legacy_status' => 1]],
                'archived' => ['label' => 'Archived', 'weight' => 3, 'metadata' => ['legacy_status' => 0]],
            ],
            'transitions' => [
                'submit_for_review' => [
                    'label' => 'Submit for Review',
                    'from' => ['draft'],
                    'to' => 'review',
                ],
                'send_back' => [
                    'label' => 'Send Back to Draft',
                    'from' => ['review'],
                    'to' => 'draft',
                ],
                'publish' => [
                    'label' => 'Publish',
                    'from' => ['review'],
                    'to' => 'published',
                ],
                'unpublish' => [
                    'label' => 'Revert to Draft',
                    'from' => ['published'],
                    'to' => 'draft',
                ],
                'archive' => [
                    'label' => 'Archive',
                    'from' => ['published'],
                    'to' => 'archived',
                ],
                'restore' => [
                    'label' => 'Restore to Draft',
                    'from' => ['archived'],
                    'to' => 'draft',
                ],
            ],
        ]);
    }

    /**
     * Normalize a workflow state from mixed input (legacy status field support).
     */
    public static function normalizeState(mixed $workflowState, mixed $status): string
    {
        if (\is_string($workflowState) && trim($workflowState) !== '') {
            return strtolower(trim($workflowState));
        }

        if (\is_bool($status)) {
            return $status ? 'published' : 'draft';
        }
        if (\is_numeric($status)) {
            return ((int) $status) === 1 ? 'published' : 'draft';
        }
        if (\is_string($status)) {
            $normalized = strtolower(trim($status));
            if (\in_array($normalized, ['1', 'true'], true)) {
                return 'published';
            }
        }

        return 'draft';
    }

    /**
     * Map a workflow state to a legacy integer status field.
     */
    public static function statusForState(string $state): int
    {
        return $state === 'published' ? 1 : 0;
    }
}
