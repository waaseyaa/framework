<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Ingestion;

use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowVisibility;

final class ValidationGateValidator
{
    private const int MIN_PUBLISHED_BODY_TOKENS = 5;

    private readonly Workflow $workflow;

    public function __construct(
        ?Workflow $workflow = null,
        private readonly WorkflowVisibility $visibility = new WorkflowVisibility(),
    ) {
        $this->workflow = $workflow ?? EditorialWorkflowPreset::create();
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $relationships
     * @return list<array<string, mixed>>
     */
    public function validate(array $nodes, array $relationships): array
    {
        $violations = [];
        $sortedNodes = $nodes;
        ksort($sortedNodes);

        $nodePublic = [];
        foreach ($sortedNodes as $key => $node) {
            $state = strtolower(trim((string) ($node['workflow_state'] ?? '')));
            if (!$this->workflow->hasState($state)) {
                $violations[] = [
                    'code' => 'validation.workflow.unknown_state',
                    'location' => '/nodes/' . $key . '/workflow_state',
                    'item_index' => null,
                    'node_key' => $key,
                    'value' => $state,
                    'expected' => array_keys($this->workflow->getStates()),
                    'remediation' => 'Use a supported workflow_state before ingestion.',
                ];
                $nodePublic[$key] = false;
                continue;
            }

            $status = $this->normalizeStatus($node['status'] ?? 0);
            $expectedStatus = EditorialWorkflowPreset::statusForState($state);
            if ($status !== $expectedStatus) {
                $violations[] = [
                    'code' => 'validation.workflow.status_state_mismatch',
                    'location' => '/nodes/' . $key . '/status',
                    'item_index' => null,
                    'node_key' => $key,
                    'value' => $status,
                    'expected' => $expectedStatus,
                    'workflow_state' => $state,
                    'remediation' => 'Align status with workflow_state before publication.',
                ];
            }

            $nodePublic[$key] = $this->visibility->isNodePublic($node);
            if ($state === EditorialWorkflowPreset::STATE_PUBLISHED) {
                $body = trim((string) ($node['body'] ?? ''));
                if ($body === '') {
                    $violations[] = [
                        'code' => 'validation.semantic.missing_publishable_body',
                        'location' => '/nodes/' . $key . '/body',
                        'item_index' => null,
                        'node_key' => $key,
                        'value' => '',
                        'expected' => 'non-empty body',
                        'remediation' => 'Provide body content before publishing.',
                    ];
                    continue;
                }

                $tokenCount = preg_match_all('/[^\s]+/u', $body) ?: 0;
                if ($tokenCount < self::MIN_PUBLISHED_BODY_TOKENS) {
                    $violations[] = [
                        'code' => 'validation.semantic.insufficient_publishable_tokens',
                        'location' => '/nodes/' . $key . '/body',
                        'item_index' => null,
                        'node_key' => $key,
                        'value' => $tokenCount,
                        'expected' => self::MIN_PUBLISHED_BODY_TOKENS,
                        'remediation' => 'Add richer content before publishing.',
                    ];
                }
            }
        }

        $normalizedRelationships = array_values($relationships);
        usort(
            $normalizedRelationships,
            static fn(array $left, array $right): int => strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? '')),
        );

        foreach ($normalizedRelationships as $index => $relationship) {
            $from = (string) ($relationship['from'] ?? '');
            $to = (string) ($relationship['to'] ?? '');
            $status = $this->normalizeStatus($relationship['status'] ?? 0);

            if ($from === '' || !array_key_exists($from, $nodePublic)) {
                $violations[] = [
                    'code' => 'validation.visibility.missing_relationship_endpoint',
                    'location' => '/relationships/' . $index . '/from',
                    'item_index' => null,
                    'relationship_index' => $index,
                    'value' => $from,
                    'expected' => 'known node key',
                    'remediation' => 'Ensure relationship source exists in the ingested batch.',
                ];
            }
            if ($to === '' || !array_key_exists($to, $nodePublic)) {
                $violations[] = [
                    'code' => 'validation.visibility.missing_relationship_endpoint',
                    'location' => '/relationships/' . $index . '/to',
                    'item_index' => null,
                    'relationship_index' => $index,
                    'value' => $to,
                    'expected' => 'known node key',
                    'remediation' => 'Ensure relationship target exists in the ingested batch.',
                ];
            }

            if (
                $status === 1
                && array_key_exists($from, $nodePublic)
                && array_key_exists($to, $nodePublic)
                && (!$nodePublic[$from] || !$nodePublic[$to])
            ) {
                $violations[] = [
                    'code' => 'validation.visibility.relationship_requires_public_endpoints',
                    'location' => '/relationships/' . $index . '/status',
                    'item_index' => null,
                    'relationship_index' => $index,
                    'value' => $status,
                    'expected' => 0,
                    'from_key' => $from,
                    'to_key' => $to,
                    'remediation' => 'Publish both endpoint nodes or demote relationship status.',
                ];
            }
        }

        return $violations;
    }

    private function normalizeStatus(mixed $status): int
    {
        if (is_bool($status)) {
            return $status ? 1 : 0;
        }
        if (is_numeric($status)) {
            return ((int) $status) === 1 ? 1 : 0;
        }
        if (is_string($status)) {
            return in_array(strtolower(trim($status)), ['1', 'true', 'published', 'yes'], true) ? 1 : 0;
        }

        return 0;
    }
}
