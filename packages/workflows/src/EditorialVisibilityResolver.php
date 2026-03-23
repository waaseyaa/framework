<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

final class EditorialVisibilityResolver
{
    public function __construct(
        private readonly Workflow $workflow = new Workflow(),
    ) {}

    public function canRender(EntityInterface $entity, AccountInterface $account, bool $previewRequested = false): AccessResult
    {
        if ($entity->getEntityTypeId() !== 'node') {
            return AccessResult::allowed('Entity type is not workflow-gated for SSR.');
        }

        $state = $this->stateForEntity($entity);
        if ($state === 'published') {
            return AccessResult::allowed('Published node is publicly visible.');
        }

        if (!$previewRequested) {
            return AccessResult::forbidden(sprintf(
                'Workflow state "%s" is not publicly visible without preview.',
                $state,
            ));
        }

        if (!$account->isAuthenticated()) {
            return AccessResult::forbidden('Preview requires an authenticated account.');
        }

        if ($account->hasPermission('administer nodes')) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        $values = $entity->toArray();
        $bundle = $this->bundleForEntity($entity);
        $authorId = isset($values['uid']) ? (string) $values['uid'] : '';
        if ($authorId !== '' && (string) $account->id() === $authorId && $account->hasPermission('view own unpublished content')) {
            return AccessResult::allowed('Author can preview own unpublished content.');
        }

        $previewPermissions = [
            "view {$bundle} moderation queue",
            "publish {$bundle} content",
            "edit any {$bundle} content",
            "archive {$bundle} content",
            "restore {$bundle} content",
        ];
        foreach ($previewPermissions as $permission) {
            if ($account->hasPermission($permission)) {
                return AccessResult::allowed(sprintf(
                    'Preview authorized via "%s" permission.',
                    $permission,
                ));
            }
        }

        return AccessResult::forbidden(sprintf(
            'Preview denied for workflow state "%s" on bundle "%s".',
            $state,
            $bundle,
        ));
    }

    /**
     * @return array{state: string, is_public: bool, preview_requested: bool}
     */
    public function buildRenderContext(EntityInterface $entity, bool $previewRequested): array
    {
        $state = $this->stateForEntity($entity);

        return [
            'state' => $state,
            'is_public' => $state === 'published',
            'preview_requested' => $previewRequested,
        ];
    }

    public function stateForEntity(EntityInterface $entity): string
    {
        $values = $entity->toArray();

        return EditorialWorkflowPreset::normalizeState(
            workflowState: $values['workflow_state'] ?? null,
            status: $values['status'] ?? 0,
        );
    }

    private function bundleForEntity(EntityInterface $entity): string
    {
        $bundle = trim((string) $entity->bundle());
        if ($bundle !== '') {
            return $bundle;
        }

        $values = $entity->toArray();

        return trim((string) ($values['type'] ?? ''));
    }
}
