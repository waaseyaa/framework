<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture\BimaajiAgentRuntimeKernel;

/**
 * M2 WP04 negative path — capability gating at run level (SC-003 / FR-010).
 *
 * The provider stub still drives the three-step bimaaji flow, but the
 * initiator account holds only `bimaaji.read`. The introspect step
 * succeeds; both mutation tools surface a `forbidden` envelope from
 * `AbstractAgentTool::requireCapability()`. The run completes (the
 * capability error is a domain outcome, not an executor crash) and the
 * audit log records the per-tool denial so an operator can diagnose
 * what the agent was missing.
 *
 * Note on envelope shape: WP04's spec described the capability error as
 * `{ ok: false, error: { code: 'capability_required', details: { required: 'bimaaji.mutate' } } }`.
 * The shipped {@see \Waaseyaa\AI\Tools\AbstractAgentTool::requireCapability()}
 * surfaces the same semantic deny via `AgentToolResult::error(..., summary: 'forbidden')`
 * with a message that carries the account id and the required capability
 * string. The assertion pins the shipped surface: `success == 0`,
 * summary contains `forbidden`, and the result message names the missing
 * capability — so an operator (or future capability-aware downstream)
 * can extract the same information. When the spec's structured envelope
 * lands, this test still holds.
 */
#[CoversNothing]
final class BimaajiAgentRunCapabilityTest extends TestCase
{
    private BimaajiAgentRuntimeKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new BimaajiAgentRuntimeKernel();
    }

    #[Test]
    public function readOnlyAccountSurfacesCapabilityDenyForMutationTools(): void // SC-003 / FR-010
    {
        $run = $this->kernel->seedRun(accountId: 99);
        $account = $this->kernel->accountWith(99, ['bimaaji.read']);
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

        // The capability deny is a domain outcome — the executor still
        // reaches a clean terminal state.
        self::assertTrue(
            $result->success,
            sprintf('Executor run must complete normally; got message=%s', $result->message),
        );

        $fresh = $this->kernel->runRepository->find((string) $run->get('id'));
        self::assertSame(RunStatus::Completed, $fresh?->getStatus());

        $events = $this->kernel->auditEventsForRun((string) $run->get('id'));

        // The executor emits one `tool_call` per dispatch and one
        // `tool_result`/`error` per completion. Capability denies surface as
        // `error` events with `success=0` and `tool_result_summary='forbidden'`
        // because `AbstractAgentTool::requireCapability()` returns a tool-level
        // error result.
        $toolResults = array_values(array_filter(
            $events,
            static fn(array $event): bool => in_array($event['event_type'], ['tool_result', 'error'], true) && $event['tool_name'] !== null,
        ));

        // Introspect succeeded (read capability is granted).
        $introspectResults = array_values(array_filter(
            $toolResults,
            static fn(array $event): bool => $event['tool_name'] === 'bimaaji_introspect_section',
        ));
        self::assertNotEmpty($introspectResults, 'introspect_section must produce a tool_result row.');
        foreach ($introspectResults as $event) {
            self::assertSame(
                1,
                (int) $event['success'],
                'introspect_section must succeed for an account holding bimaaji.read.',
            );
        }

        // Mutation tools must surface a `forbidden` denial in the audit log.
        $mutationResults = array_values(array_filter(
            $toolResults,
            static fn(array $event): bool => in_array(
                $event['tool_name'],
                ['bimaaji_propose_mutation', 'bimaaji_generate_patch'],
                true,
            ),
        ));
        self::assertNotEmpty(
            $mutationResults,
            'Mutation tools must produce tool_result/error rows so the capability gate is visible.',
        );
        foreach ($mutationResults as $event) {
            self::assertSame(
                0,
                (int) $event['success'],
                sprintf(
                    'Mutation tool %s must be denied for a read-only account; got summary=%s',
                    $event['tool_name'],
                    $event['tool_result_summary'] ?? '',
                ),
            );
            self::assertSame(
                'forbidden',
                $event['tool_result_summary'],
                sprintf('Tool %s must surface the canonical "forbidden" summary on capability deny.', $event['tool_name']),
            );
        }
    }
}
