<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

/**
 * Canonical state machine for editorial node lifecycle transitions.
 */
final class EditorialWorkflowStateMachine
{
    public const string STATE_DRAFT = 'draft';
    public const string STATE_REVIEW = 'review';
    public const string STATE_PUBLISHED = 'published';
    public const string STATE_ARCHIVED = 'archived';

    /**
     * @var array<string, array{id: string, label: string, from: list<string>, to: string, permission: string}>
     */
    private const array TRANSITIONS = [
        'submit_for_review' => [
            'id' => 'submit_for_review',
            'label' => 'Submit for Review',
            'from' => [self::STATE_DRAFT],
            'to' => self::STATE_REVIEW,
            'permission' => 'submit {bundle} for review',
        ],
        'send_back' => [
            'id' => 'send_back',
            'label' => 'Send Back to Draft',
            'from' => [self::STATE_REVIEW],
            'to' => self::STATE_DRAFT,
            'permission' => 'return {bundle} to draft',
        ],
        'publish' => [
            'id' => 'publish',
            'label' => 'Publish',
            'from' => [self::STATE_REVIEW],
            'to' => self::STATE_PUBLISHED,
            'permission' => 'publish {bundle} content',
        ],
        'unpublish' => [
            'id' => 'unpublish',
            'label' => 'Revert to Draft',
            'from' => [self::STATE_PUBLISHED],
            'to' => self::STATE_DRAFT,
            'permission' => 'revert {bundle} to draft',
        ],
        'archive' => [
            'id' => 'archive',
            'label' => 'Archive',
            'from' => [self::STATE_PUBLISHED],
            'to' => self::STATE_ARCHIVED,
            'permission' => 'archive {bundle} content',
        ],
        'restore' => [
            'id' => 'restore',
            'label' => 'Restore to Draft',
            'from' => [self::STATE_ARCHIVED],
            'to' => self::STATE_DRAFT,
            'permission' => 'restore {bundle} content',
        ],
    ];

    /**
     * @return list<string>
     */
    public function states(): array
    {
        return [
            self::STATE_DRAFT,
            self::STATE_REVIEW,
            self::STATE_PUBLISHED,
            self::STATE_ARCHIVED,
        ];
    }

    public function normalizeState(mixed $workflowState, mixed $status): string
    {
        if (\is_string($workflowState) && trim($workflowState) !== '') {
            return strtolower(trim($workflowState));
        }

        if (\is_bool($status)) {
            return $status ? self::STATE_PUBLISHED : self::STATE_DRAFT;
        }
        if (\is_numeric($status)) {
            return ((int) $status) === 1 ? self::STATE_PUBLISHED : self::STATE_DRAFT;
        }
        if (\is_string($status)) {
            $normalized = strtolower(trim($status));
            if (\in_array($normalized, ['1', 'true'], true)) {
                return self::STATE_PUBLISHED;
            }
        }

        return self::STATE_DRAFT;
    }

    public function isKnownState(string $state): bool
    {
        return \in_array($state, $this->states(), true);
    }

    /**
     * @return array{id: string, label: string, from: list<string>, to: string, permission: string}|null
     */
    public function transitionMetadata(string $from, string $to): ?array
    {
        foreach (self::TRANSITIONS as $transition) {
            if ($transition['to'] !== $to) {
                continue;
            }

            if (\in_array($from, $transition['from'], true)) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, label: string, from: list<string>, to: string, permission: string}>
     */
    public function availableTransitions(string $fromState): array
    {
        $transitions = [];
        foreach (self::TRANSITIONS as $transition) {
            if (\in_array($fromState, $transition['from'], true)) {
                $transitions[] = $transition;
            }
        }

        usort(
            $transitions,
            static fn(array $left, array $right): int => strcmp($left['id'], $right['id']),
        );

        return $transitions;
    }

    /**
     * @return array{id: string, label: string, from: list<string>, to: string, permission: string}
     */
    public function assertTransitionAllowed(string $from, string $to): array
    {
        if (!$this->isKnownState($from)) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow state: "%s".', $from));
        }
        if (!$this->isKnownState($to)) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow state: "%s".', $to));
        }

        $metadata = $this->transitionMetadata($from, $to);
        if ($metadata === null) {
            throw new \RuntimeException(sprintf(
                'Invalid workflow transition: %s -> %s.',
                $from,
                $to,
            ));
        }

        return $metadata;
    }

    public function statusForState(string $state): int
    {
        return $state === self::STATE_PUBLISHED ? 1 : 0;
    }
}
