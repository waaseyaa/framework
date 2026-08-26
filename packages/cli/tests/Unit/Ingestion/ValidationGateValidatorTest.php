<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Ingestion\ValidationGateValidator;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowState;

#[CoversClass(ValidationGateValidator::class)]
final class ValidationGateValidatorTest extends TestCase
{
    #[Test]
    public function it_emits_workflow_semantic_and_visibility_violations_deterministically(): void
    {
        $validator = new ValidationGateValidator();

        $violations = $validator->validate(
            nodes: [
                'draft_node' => [
                    'workflow_state' => 'draft',
                    'status' => 0,
                    'body' => 'Draft content.',
                ],
                'published_node' => [
                    'workflow_state' => 'published',
                    'status' => 1,
                    'body' => 'tiny',
                ],
                'unknown_node' => [
                    'workflow_state' => 'mystery',
                    'status' => 1,
                    'body' => 'Unknown state content.',
                ],
            ],
            relationships: [
                [
                    'key' => 'r1',
                    'from' => 'published_node',
                    'to' => 'draft_node',
                    'status' => 1,
                ],
                [
                    'key' => 'r2',
                    'from' => 'published_node',
                    'to' => 'missing_node',
                    'status' => 1,
                ],
            ],
        );

        $codes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $violations,
        ));

        $this->assertContains('validation.semantic.insufficient_publishable_tokens', $codes);
        $this->assertContains('validation.workflow.unknown_state', $codes);
        $this->assertContains('validation.visibility.relationship_requires_public_endpoints', $codes);
        $this->assertContains('validation.visibility.missing_relationship_endpoint', $codes);
    }

    #[Test]
    public function it_flags_status_workflow_mismatch(): void
    {
        $validator = new ValidationGateValidator();
        $violations = $validator->validate(
            nodes: [
                'node_a' => [
                    'workflow_state' => 'published',
                    'status' => 0,
                    'body' => 'Published node with enough words for semantic validation pass.',
                ],
            ],
            relationships: [],
        );

        $mismatch = array_find(
            $violations,
            static fn(array $row): bool => (string) ($row['code'] ?? '') === 'validation.workflow.status_state_mismatch',
        );
        $this->assertNotNull($mismatch);
        $this->assertSame('/nodes/node_a/status', $mismatch['location']);
    }

    #[Test]
    public function it_uses_custom_state_declarations_instead_of_literal_ids(): void
    {
        $workflow = new Workflow(['id' => 'custom', 'label' => 'Custom']);
        $workflow->addState(new WorkflowState(id: 'live', label: 'Live', published: true));
        $workflow->addState(new WorkflowState(id: 'published', label: 'Published', published: false));
        $validator = new ValidationGateValidator($workflow);

        $violations = $validator->validate(
            nodes: [
                'live_node' => [
                    'workflow_state' => 'live',
                    'status' => 1,
                    'body' => 'Custom live content has enough semantic tokens.',
                ],
                'private_node' => [
                    'workflow_state' => 'published',
                    'status' => 0,
                    'body' => '',
                ],
            ],
            relationships: [[
                'key' => 'r1',
                'from' => 'live_node',
                'to' => 'private_node',
                'status' => 1,
            ]],
        );
        $codes = array_column($violations, 'code');

        self::assertNotContains('validation.workflow.status_state_mismatch', $codes);
        self::assertNotContains('validation.semantic.missing_publishable_body', $codes);
        self::assertContains('validation.visibility.relationship_requires_public_endpoints', $codes);
    }
}
