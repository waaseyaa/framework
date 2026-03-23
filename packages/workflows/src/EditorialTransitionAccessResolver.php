<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;

final class EditorialTransitionAccessResolver
{
    /**
     * @var list<string>
     */
    private const array RECOGNIZED_ROLES = [
        'contributor',
        'reviewer',
        'editor',
        'administrator',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array TRANSITION_ROLE_MATRIX = [
        'submit_for_review' => ['contributor', 'reviewer', 'editor', 'administrator'],
        'send_back' => ['reviewer', 'editor', 'administrator'],
        'publish' => ['reviewer', 'editor', 'administrator'],
        'unpublish' => ['editor', 'administrator'],
        'archive' => ['editor', 'administrator'],
        'restore' => ['editor', 'administrator'],
    ];

    public function __construct(
        private readonly Workflow $workflow = new Workflow(),
    ) {}

    /**
     * @return array{id: string, label: string, from: list<string>, to: string, permission: string}
     */
    public function transition(string $fromState, string $toState): array
    {
        if (!$this->workflow->hasState($fromState)) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow state: "%s".', $fromState));
        }
        if (!$this->workflow->hasState($toState)) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow state: "%s".', $toState));
        }

        // Find the matching transition in the workflow
        foreach ($this->workflow->getTransitions() as $transition) {
            if ($transition->to === $toState && \in_array($fromState, $transition->from, true)) {
                return [
                    'id' => $transition->id,
                    'label' => $transition->label,
                    'from' => $transition->from,
                    'to' => $transition->to,
                    'permission' => EditorialWorkflowPreset::TRANSITION_PERMISSIONS[$transition->id] ?? '',
                ];
            }
        }

        throw new \RuntimeException(sprintf(
            'Invalid workflow transition: %s -> %s.',
            $fromState,
            $toState,
        ));
    }

    public function canTransition(string $bundle, string $fromState, string $toState, AccountInterface $account): AccessResult
    {
        try {
            $transition = $this->transition($fromState, $toState);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return AccessResult::forbidden($exception->getMessage());
        }

        if ($account->hasPermission('administer nodes')) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        $requiredPermission = $this->formatPermission($transition['permission'], $bundle);
        if ($requiredPermission !== '' && !$account->hasPermission($requiredPermission)) {
            return AccessResult::forbidden(sprintf(
                'Permission denied for workflow transition %s -> %s on "%s". Required: "%s".',
                $fromState,
                $toState,
                $bundle,
                $requiredPermission,
            ));
        }

        $roles = array_values(array_map(
            static fn(string $role): string => strtolower(trim($role)),
            array_filter($account->getRoles(), static fn(mixed $role): bool => \is_string($role) && trim($role) !== ''),
        ));

        if (!$this->isRoleAuthorizedForTransition($roles, $transition['id'])) {
            return AccessResult::forbidden(sprintf(
                'Role not authorized for transition "%s". Allowed roles: %s.',
                $transition['id'],
                implode(', ', $this->allowedRolesForTransition($transition['id'])),
            ));
        }

        return AccessResult::allowed(sprintf(
            'Transition "%s" authorized for workflow state change %s -> %s.',
            $transition['id'],
            $fromState,
            $toState,
        ));
    }

    public function requiredPermission(string $bundle, string $fromState, string $toState): string
    {
        $transition = $this->transition($fromState, $toState);

        return $this->formatPermission($transition['permission'], $bundle);
    }

    /**
     * @return list<string>
     */
    public function allowedRolesForTransition(string $transitionId): array
    {
        return self::TRANSITION_ROLE_MATRIX[$transitionId] ?? [];
    }

    /**
     * @param list<string> $roles
     */
    private function isRoleAuthorizedForTransition(array $roles, string $transitionId): bool
    {
        $allowedRoles = $this->allowedRolesForTransition($transitionId);
        if ($allowedRoles === []) {
            return true;
        }

        $recognized = array_values(array_intersect($roles, self::RECOGNIZED_ROLES));
        if ($recognized === []) {
            // Preserve backwards compatibility for accounts driven by permission-only setups.
            return true;
        }

        return array_intersect($recognized, $allowedRoles) !== [];
    }

    private function formatPermission(string $pattern, string $bundle): string
    {
        return str_replace('{bundle}', $bundle, $pattern);
    }
}
