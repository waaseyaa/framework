<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\FieldableInterface;

final class EditorialWorkflowService
{
    /**
     * @param list<string> $coreBundles
     */
    public function __construct(
        private readonly array $coreBundles,
        private readonly EditorialWorkflowStateMachine $stateMachine = new EditorialWorkflowStateMachine(),
        private readonly ?\Closure $clock = null,
    ) {}

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

        $transition = $this->stateMachine->assertTransitionAllowed($from, $to);
        $requiredPermission = $this->formatPermission($transition['permission'], $bundle);

        if ($requiredPermission !== ''
            && !$account->hasPermission('administer nodes')
            && !$account->hasPermission($requiredPermission)) {
            throw new \RuntimeException(sprintf(
                'Permission denied for workflow transition %s -> %s on "%s". Required: "%s".',
                $from,
                $to,
                $bundle,
                $requiredPermission,
            ));
        }

        $node->set('workflow_state', $to);
        $node->set('status', $this->stateMachine->statusForState($to));
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

        $transitions = $this->stateMachine->availableTransitions($from);
        $metadata = [];
        foreach ($transitions as $transition) {
            $metadata[] = [
                'id' => $transition['id'],
                'label' => $transition['label'],
                'from' => $transition['from'],
                'to' => $transition['to'],
                'required_permission' => $this->formatPermission($transition['permission'], $bundle),
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
        return $this->stateMachine->normalizeState(
            workflowState: $node->get('workflow_state'),
            status: $node->get('status'),
        );
    }

    private function formatPermission(string $pattern, string $bundle): string
    {
        return str_replace('{bundle}', $bundle, $pattern);
    }

    private function timestamp(): int
    {
        if ($this->clock instanceof \Closure) {
            return (int) ($this->clock)();
        }

        return time();
    }
}
