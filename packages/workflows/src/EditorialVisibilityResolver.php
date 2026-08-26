<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Read\EditorialPreviewSubjectReader;
use Waaseyaa\Workflows\Read\WorkflowEntitySnapshotReader;

/**
 * @api
 */
final class EditorialVisibilityResolver
{
    private readonly Workflow $workflow;
    private readonly WorkflowEntitySnapshotReader $workflowValues;
    private readonly EditorialPreviewSubjectReader $previewSubject;
    private readonly WorkflowVisibility $visibility;

    public function __construct(
        ?Workflow $workflow = null,
        ?WorkflowEntitySnapshotReader $workflowValues = null,
        ?EditorialPreviewSubjectReader $previewSubject = null,
        ?WorkflowVisibility $visibility = null,
        private readonly ?WorkflowBindingResolver $bindings = null,
    ) {
        $this->workflow = $workflow ?? new Workflow(DefaultWorkflows::EDITORIAL);
        $this->workflowValues = $workflowValues ?? new WorkflowEntitySnapshotReader();
        $this->previewSubject = $previewSubject ?? new EditorialPreviewSubjectReader();
        $this->visibility = $visibility ?? new WorkflowVisibility($this->workflowValues);
    }

    public function canRender(EntityInterface $entity, AccountInterface $account, bool $previewRequested = false): AccessResult
    {
        if ($entity->getEntityTypeId() !== 'node') {
            return AccessResult::allowed('Entity type is not workflow-gated for SSR.');
        }

        $workflow = $this->workflowForEntity($entity);
        $state = $this->stateForEntity($entity, $workflow);
        $isPublic = $previewRequested
            ? $this->visibility->isCandidateStatePublic($workflow, $state)
            : $this->visibility->isEntityServedPublicForEntity($entity);
        if ($isPublic) {
            return AccessResult::allowed('Node is publicly visible.');
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

        $bundle = $this->bundleForEntity($entity);
        $uid = $this->previewSubject->read($entity)->authorId;
        $authorId = ($uid !== null && $uid !== '') ? (string) $uid : '';
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
        $workflow = $this->workflowForEntity($entity);
        $state = $this->stateForEntity($entity, $workflow);

        return [
            'state' => $state,
            'is_public' => $previewRequested
                ? $this->visibility->isCandidateStatePublic($workflow, $state)
                : $this->visibility->isEntityServedPublicForEntity($entity),
            'preview_requested' => $previewRequested,
        ];
    }

    public function stateForEntity(EntityInterface $entity, ?Workflow $workflow = null): string
    {
        $values = $this->workflowValues->read($entity);

        return is_string($values->workflowState) && trim($values->workflowState) !== ''
            ? strtolower(trim($values->workflowState))
            : ($workflow ?? $this->workflowForEntity($entity))->getInitialState();
    }

    private function workflowForEntity(EntityInterface $entity): Workflow
    {
        return $this->bindings?->resolve($entity->getEntityTypeId(), $this->bundleForEntity($entity))
            ?? $this->workflow;
    }

    private function bundleForEntity(EntityInterface $entity): string
    {
        $bundle = trim((string) $entity->bundle());
        if ($bundle !== '') {
            return $bundle;
        }

        return '';
    }
}
