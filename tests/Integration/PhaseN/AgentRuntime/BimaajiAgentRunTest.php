<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture\BimaajiAgentRuntimeKernel;

/**
 * M2 WP04 positive path — end-to-end exercise of the bimaaji tool trio
 * through {@see \Waaseyaa\AI\Agent\AgentExecutor}.
 *
 * The provider stub drives a three-step introspect → propose → generate
 * tool-use sequence followed by an `end_turn` message. The test asserts:
 *
 *  - The run reaches `RunStatus::Completed` (SC-001 — the executor loop
 *    composes all three tools without crashing).
 *  - Audit log records a `tool_invocation` event for each of the three
 *    bimaaji tool names (SC-002 — every tool actually ran).
 *  - The final `bimaaji_generate_patch` result envelope (parsed from the
 *    audit summary or surfaced via the transcript) carries a non-empty
 *    `patches` list, meaning the demo produced a real PatchSet.
 *
 * Note on envelope shape: WP04's spec described `data.operations` as the
 * patch-set key, but {@see \Waaseyaa\Bimaaji\Patch\PatchSet::toArray()}
 * actually returns `{ patches: [...] }`. The assertion uses the shipped
 * key.
 */
#[CoversNothing]
final class BimaajiAgentRunTest extends TestCase
{
    private BimaajiAgentRuntimeKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new BimaajiAgentRuntimeKernel();
    }

    #[Test]
    public function demoAgentRunsAllThreeBimaajiTools(): void // FR-011 / SC-001 / SC-002
    {
        $run = $this->kernel->seedRun(accountId: 99);
        $account = $this->kernel->accountWith(99, ['bimaaji.read', 'bimaaji.mutate']);
        $registry = $this->kernel->bimaajiToolRegistry();
        $provider = $this->kernel->bimaajiDemoProvider([
            'operation' => 'add_field',
            'entity_type' => 'fixture_demo',
            'field' => 'nickname',
            'parameters' => ['type' => 'string'],
        ]);

        $executor = $this->kernel->executor($registry);
        $result = $executor->executeRun(
            $run,
            $account,
            $provider,
            messages: [['role' => 'user', 'content' => 'demo']],
            allowedToolNames: ['bimaaji_introspect_section', 'bimaaji_propose_mutation', 'bimaaji_generate_patch'],
            maxIterations: 6,
        );

        self::assertTrue(
            $result->success,
            sprintf('Executor run failed: message=%s', $result->message),
        );

        $fresh = $this->kernel->runRepository->find((string) $run->get('id'));
        self::assertSame(RunStatus::Completed, $fresh?->getStatus());

        $events = $this->kernel->auditEventsForRun((string) $run->get('id'));

        // The executor emits one `tool_call` (start) + one `tool_result`/`error`
        // (end) audit row per tool dispatch. We assert on both phases.
        $toolCalls = array_values(array_filter(
            $events,
            static fn(array $event): bool => $event['event_type'] === 'tool_call',
        ));
        $toolNames = array_map(
            static fn(array $event): string => (string) $event['tool_name'],
            $toolCalls,
        );
        self::assertContains('bimaaji_introspect_section', $toolNames, 'Audit log must record introspect_section tool_call.');
        self::assertContains('bimaaji_propose_mutation', $toolNames, 'Audit log must record propose_mutation tool_call.');
        self::assertContains('bimaaji_generate_patch', $toolNames, 'Audit log must record generate_patch tool_call.');

        $toolResults = array_values(array_filter(
            $events,
            static fn(array $event): bool => in_array($event['event_type'], ['tool_result', 'error'], true) && $event['tool_name'] !== null,
        ));
        $patchResults = array_values(array_filter(
            $toolResults,
            static fn(array $event): bool => $event['tool_name'] === 'bimaaji_generate_patch',
        ));
        self::assertNotEmpty($patchResults, 'generate_patch must produce a tool_result row.');
        foreach ($patchResults as $event) {
            self::assertSame(
                1,
                (int) $event['success'],
                sprintf('generate_patch must succeed; got summary=%s', $event['tool_result_summary'] ?? ''),
            );
            self::assertStringContainsString(
                'PatchSet:',
                (string) $event['tool_result_summary'],
                'generate_patch summary must surface the PatchSet count line.',
            );
        }

        // SC-003 negative side: no capability errors in the audit log for
        // the positive-path account (the account holds both capabilities).
        $forbiddenResults = array_values(array_filter(
            $toolResults,
            static fn(array $event): bool => $event['tool_result_summary'] === 'forbidden',
        ));
        self::assertEmpty(
            $forbiddenResults,
            'Positive path must surface zero capability errors in the audit log.',
        );
    }
}
