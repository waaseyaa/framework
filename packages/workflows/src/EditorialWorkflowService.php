<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\FieldableInterface;

final class EditorialWorkflowService
{
    private readonly Workflow $workflow;
    private readonly EditorialTransitionAccessResolver $transitionAccessResolver;

    /**
     * @param list<string> $coreBundles
     */
    public function __construct(
        private readonly array $coreBundles,
        ?Workflow $workflow = null,
        ?EditorialTransitionAccessResolver $transitionAccessResolver = null,
        private readonly ?\Closure $clock = null,
    ) {
        $this->workflow = $workflow ?? EditorialWorkflowPreset::create();
        $this->transitionAccessResolver = $transitionAccessResolver ?? new EditorialTransitionAccessResolver($this->workflow);
    }

    /**
     * @param FieldableInterface&\Waaseyaa\Entity\EntityInterface $node
     */
    public function transitionNode(FieldableInterface $node, string $toState, AccountInterface $account): void
    {
        $bundle = (string) ($node->get('type') ?? '');
        if (!\in_array($bundle, $this->coreBundles, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Workflow transition is only supported for configured bundles. Got: "%s".',
                $bundle,
            ));
        }

        $to = strtolower(trim($toState));
        $from = $this->stateFromNode($node);
        if ($from === $to) {
            return;
        }

        $access = $this->transitionAccessResolver->canTransition($bundle, $from, $to, $account);
        if ($access->isForbidden()) {
            throw new \RuntimeException($access->reason);
        }
        $transition = $this->transitionAccessResolver->transition($from, $to);
        $requiredPermission = $this->transitionAccessResolver->requiredPermission($bundle, $from, $to);

        $node->set('workflow_state', $to);
        $node->set('status', EditorialWorkflowPreset::statusForState($to));
        $node->set('workflow_last_transition', [
            'id' => $transition['id'],
            'label' => $transition['label'],
            'from' => $transition['from'],
            'to' => $transition['to'],
            'required_permission' => $requiredPermission,
        ]);

        $audit = $node->get('workflow_audit');
        if (!\is_array($audit)) {
            $audit = [];
        }
        $audit[] = [
            'transition' => $transition['id'],
            'from' => $from,
            'to' => $to,
            'uid' => (string) $account->id(),
            'at' => $this->timestamp(),
        ];
        $node->set('workflow_audit', $audit);
    }

    /**
     * @return list<array{id: string, label: string, from: list<string>, to: string, required_permission: string}>
     */
    public function getAvailableTransitionMetadata(FieldableInterface $node): array
    {
        $bundle = (string) ($node->get('type') ?? '');
        $from = $this->stateFromNode($node);

        $validTransitions = $this->workflow->getValidTransitions($from);
        $metadata = [];
        foreach ($validTransitions as $transition) {
            $metadata[] = [
                'id' => $transition->id,
                'label' => $transition->label,
                'from' => $transition->from,
                'to' => $transition->to,
                'required_permission' => $this->transitionAccessResolver->requiredPermission($bundle, $from, $transition->to),
            ];
        }

        return $metadata;
    }

    public function currentState(FieldableInterface $node): string
    {
        return $this->stateFromNode($node);
    }

    private function stateFromNode(FieldableInterface $node): string
    {
        return EditorialWorkflowPreset::normalizeState(
            workflowState: $node->get('workflow_state'),
            status: $node->get('status'),
        );
    }

    private function timestamp(): int
    {
        if ($this->clock instanceof \Closure) {
            return (int) ($this->clock)();
        }

        return time();
    }
}
