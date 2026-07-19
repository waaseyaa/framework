<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Workflows\Read\EditorialWorkflowLegacySubjectReader;

/**
 * @api
 */
final class EditorialWorkflowService
{
    private readonly Workflow $workflow;
    private readonly EditorialTransitionAccessResolver $transitionAccessResolver;
    private readonly EditorialWorkflowLegacySubjectReader $subjectReader;

    /**
     * @param list<string> $coreBundles
     */
    public function __construct(
        private readonly array $coreBundles,
        ?Workflow $workflow = null,
        ?EditorialTransitionAccessResolver $transitionAccessResolver = null,
        private readonly ?\Closure $clock = null,
        ?EditorialWorkflowLegacySubjectReader $subjectReader = null,
    ) {
        $this->workflow = $workflow ?? EditorialWorkflowPreset::create();
        $this->transitionAccessResolver = $transitionAccessResolver ?? new EditorialTransitionAccessResolver($this->workflow);
        $this->subjectReader = $subjectReader ?? new EditorialWorkflowLegacySubjectReader();
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

        $audit = $this->subject($node)->audit;
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

        usort($metadata, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $metadata;
    }

    public function currentState(FieldableInterface $node): string
    {
        return $this->stateFromNode($node);
    }

    private function stateFromNode(FieldableInterface $node): string
    {
        $subject = $this->subject($node);

        return EditorialWorkflowPreset::normalizeState(
            workflowState: $subject->workflowState,
            status: $subject->status,
        );
    }

    private function subject(FieldableInterface $node): \Waaseyaa\Workflows\Read\EditorialWorkflowLegacySubject
    {
        return $this->subjectReader->read($node);
    }

    private function timestamp(): int
    {
        if ($this->clock instanceof \Closure) {
            return (int) ($this->clock)();
        }

        return time();
    }
}
