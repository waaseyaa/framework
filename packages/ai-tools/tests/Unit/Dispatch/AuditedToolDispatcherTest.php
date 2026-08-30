<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Dispatch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher;
use Waaseyaa\AI\Tools\Dispatch\AuditedToolDispatcher;
use Waaseyaa\AI\Tools\Dispatch\ToolDispatcherInterface;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\ArrayToolRegistry;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\FinalizeFailingStrictAuditLedger;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\FixedPrincipal;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\NonLedgerExceptionStrictAuditLedger;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\RecordFailingStrictAuditLedger;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\RecordingLogger;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\RecordingStrictAuditLedger;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\ScriptedTool;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\UnavailableStrictAuditLedger;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\NullStrictAuditLedger;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;

/**
 * ADR-022 D-5.A and D-5.B at the bridge.
 *
 * D-5.A: a real `StrictAuditLedgerInterface` is required, and
 * `NullStrictAuditLedger` does not satisfy it — it implements the interface and
 * records nothing, so the type check passes while the guarantee does not. The
 * refusal mirrors `McpEndpoint::__construct()`, on the same stated grounds: a
 * surface that LOOKS durably audited and records nothing is worse than one that
 * admits it is unaudited, because an operator reading an empty ledger cannot
 * tell the two apart.
 *
 * D-5.B: reserve-before-side-effect around dispatch, with `safeArguments`
 * coming from the tool's own `argumentsForAudit()` and never from raw params,
 * and a terminal refusal using single-shot `record()` rather than leaving a
 * dangling reservation.
 */
#[CoversClass(AuditedToolDispatcher::class)]
final class AuditedToolDispatcherTest extends TestCase
{
    // ---------------------------------------------------------------- D-5.A

    #[Test]
    public function construction_refuses_when_no_ledger_was_resolved(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/requires a real StrictAuditLedgerInterface/');

        new AuditedToolDispatcher(
            $this->inner(),
            null,
            'local.stdio',
            'corr-1',
        );
    }

    #[Test]
    public function construction_refuses_the_record_nothing_ledger(): void
    {
        // The whole point of D-5.A: this object satisfies the type and defeats
        // the guarantee. A dispatcher wired to it would report every session as
        // audited and leave no trace of any of them.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/NullStrictAuditLedger implements the interface and records nothing/');

        new AuditedToolDispatcher(
            $this->inner(),
            new NullStrictAuditLedger(),
            'local.stdio',
            'corr-1',
        );
    }

    #[Test]
    public function a_real_ledger_constructs(): void
    {
        // The control for the two refusals above: if this did not construct,
        // the refusals would be proving nothing but that construction is hard.
        self::assertInstanceOf(
            ToolDispatcherInterface::class,
            new AuditedToolDispatcher(
                $this->inner(),
                new RecordingStrictAuditLedger(),
                'local.stdio',
                'corr-1',
            ),
        );
    }

    // ---------------------------------------------------------------- D-5.C

    /**
     * The transport owns the surface constant (D-5.C), but it may not name it
     * after an HTTP tier: `McpEndpoint::auditSurface()` writes `mcp.public` and
     * `mcp.write`, and a ledger in which a local developer session is spelled
     * the same as a network caller cannot answer the question the surface field
     * exists to answer.
     */
    #[Test]
    public function construction_refuses_an_http_mcp_audit_surface(): void
    {
        foreach (['mcp', 'mcp.write', 'mcp.public', 'mcp.stdio'] as $surface) {
            try {
                new AuditedToolDispatcher(
                    $this->inner(),
                    new RecordingStrictAuditLedger(),
                    $surface,
                    'corr-1',
                );
                self::fail(\sprintf('Expected the audit surface "%s" to be refused.', $surface));
            } catch (\LogicException $e) {
                self::assertStringContainsString('HTTP MCP tiers', $e->getMessage());
            }
        }
    }

    #[Test]
    public function construction_requires_a_correlation_id(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/per-request correlation id/');

        new AuditedToolDispatcher(
            $this->inner(),
            new RecordingStrictAuditLedger(),
            'local.stdio',
            '',
        );
    }

    // ---------------------------------------------------------------- D-5.B

    #[Test]
    public function the_reservation_is_durable_before_the_tool_runs_and_finalized_after(): void
    {
        /** @var \ArrayObject<int, string> $order */
        $order = new \ArrayObject();
        $ledger = new RecordingStrictAuditLedger($order);

        $dispatcher = new AuditedToolDispatcher(
            $this->inner(order: $order),
            $ledger,
            'local.stdio',
            'corr-1',
        );

        $outcome = $dispatcher->dispatch('probe', ['q' => 'x']);

        self::assertSame(AuditStage::ExecutionSucceeded, $outcome->stage);
        self::assertSame(['reserve', 'execute', 'finalize'], $order->getArrayCopy());
    }

    #[Test]
    public function safe_arguments_come_from_the_tools_own_redaction_transform(): void
    {
        $ledger = new RecordingStrictAuditLedger();
        $dispatcher = new AuditedToolDispatcher(
            $this->inner(
                redactor: static fn(array $args): array => ['token' => '[redacted]'] + array_diff_key($args, ['token' => null]),
            ),
            $ledger,
            'local.stdio',
            'corr-1',
        );

        $dispatcher->dispatch('probe', ['token' => 'hunter2', 'q' => 'x']);

        $reservations = $ledger->entriesFor('reserve');
        self::assertCount(1, $reservations);
        $reservation = $reservations[0]['reservation'];
        self::assertInstanceOf(StrictAuditReservation::class, $reservation);
        self::assertSame(['token' => '[redacted]', 'q' => 'x'], $reservation->safeArguments);
        self::assertStringNotContainsString('hunter2', var_export($reservation->safeArguments, true));
    }

    #[Test]
    public function a_tool_that_throws_while_redacting_never_causes_raw_arguments_to_be_recorded(): void
    {
        $ledger = new RecordingStrictAuditLedger();
        $dispatcher = new AuditedToolDispatcher(
            $this->inner(
                redactor: static fn(array $args): array => throw new \RuntimeException('redaction broke'),
            ),
            $ledger,
            'local.stdio',
            'corr-1',
        );

        $dispatcher->dispatch('probe', ['token' => 'hunter2']);

        $reservation = $ledger->entriesFor('reserve')[0]['reservation'];
        self::assertInstanceOf(StrictAuditReservation::class, $reservation);
        self::assertSame(
            ['_redaction_unavailable' => true, 'argument_count' => 1],
            $reservation->safeArguments,
        );
    }

    #[Test]
    public function the_reservation_carries_the_surface_correlation_id_and_null_actor(): void
    {
        $ledger = new RecordingStrictAuditLedger();
        new AuditedToolDispatcher($this->inner(), $ledger, 'local.stdio', 'corr-42')
            ->dispatch('probe', []);

        $reservation = $ledger->entriesFor('reserve')[0]['reservation'];
        self::assertInstanceOf(StrictAuditReservation::class, $reservation);
        self::assertSame('local.stdio', $reservation->surface);
        self::assertSame('corr-42', $reservation->correlationId);
        self::assertSame('probe', $reservation->operation);
        // D-5.D: `null` means "no known persisted principal". Coercing a string
        // sentinel to int would yield 0, which IS AnonymousUser.
        self::assertNull($reservation->actorUid);
    }

    #[Test]
    public function an_unknown_tool_is_one_terminal_record_and_never_a_dangling_reservation(): void
    {
        $ledger = new RecordingStrictAuditLedger();
        $outcome = new AuditedToolDispatcher($this->inner(), $ledger, 'local.stdio', 'corr-1')
            ->dispatch('nope', ['a' => 1]);

        self::assertSame(AuditStage::ToolLookupRefused, $outcome->stage);
        self::assertSame(['record'], $ledger->calls());

        $entry = $ledger->entriesFor('record')[0];
        $reservation = $entry['reservation'];
        self::assertInstanceOf(StrictAuditReservation::class, $reservation);
        // An unknown tool cannot supply argumentsForAudit(), so no argument
        // values are recorded — only their count.
        self::assertSame([], $reservation->safeArguments);
        self::assertSame(1, $reservation->metadata['argument_count']);
    }

    #[Test]
    public function a_schema_violation_is_one_terminal_record_taken_before_any_reservation(): void
    {
        // Reserving first would misdescribe the request ("the tool is about to
        // run") and let a caller write two durable rows per garbage payload.
        $ledger = new RecordingStrictAuditLedger();
        $registry = new ArrayToolRegistry([
            $this->tool(schema: ['type' => 'object', 'required' => ['q'], 'properties' => ['q' => ['type' => 'string']]]),
        ]);
        $dispatcher = new AuditedToolDispatcher(
            new AgentToolDispatcher($registry, new FixedPrincipal()),
            $ledger,
            'local.stdio',
            'corr-1',
        );

        $outcome = $dispatcher->dispatch('probe', []);

        self::assertSame(AuditStage::InputValidationRefused, $outcome->stage);
        self::assertSame(['record'], $ledger->calls());
        self::assertSame([], $ledger->entriesFor('record')[0]['reservation']->safeArguments);
    }

    #[Test]
    public function a_ledger_that_cannot_reserve_refuses_the_call_unexecuted(): void
    {
        /** @var \ArrayObject<int, string> $order */
        $order = new \ArrayObject();
        $logger = new RecordingLogger();

        $outcome = new AuditedToolDispatcher(
            $this->inner(order: $order),
            new UnavailableStrictAuditLedger(),
            'local.stdio',
            'corr-1',
            logger: $logger,
        )->dispatch('probe', ['q' => 'x']);

        // Fail closed: no side effect without evidence that it was attempted.
        self::assertSame([], $order->getArrayCopy(), 'The tool must not run when the attempt cannot be recorded.');
        self::assertSame(AuditStage::AuditUnavailableRefused, $outcome->stage);
        self::assertTrue($outcome->isError());

        $body = json_decode($outcome->envelope['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(AuditedToolDispatcher::AUDIT_UNAVAILABLE_CODE, $body['code']);
        self::assertSame('corr-1', $body['correlation_id']);
        // No exception detail leaves; the operator joins the refusal to the log
        // line through the correlation id.
        self::assertStringNotContainsString('ledger offline', $outcome->envelope['content'][0]['text']);
        self::assertNotSame([], $logger->withLevel('critical'));
    }

    #[Test]
    public function a_finalize_failure_is_loud_and_never_rethrown(): void
    {
        // The side effect has already happened. Rethrowing would turn a missing
        // log line into a failed call the caller might retry, duplicating it.
        $logger = new RecordingLogger();
        $outcome = new AuditedToolDispatcher(
            $this->inner(),
            new FinalizeFailingStrictAuditLedger(),
            'local.stdio',
            'corr-1',
            logger: $logger,
        )->dispatch('probe', []);

        self::assertSame(AuditStage::ExecutionSucceeded, $outcome->stage);
        $critical = $logger->withLevel('critical');
        self::assertCount(1, $critical);
        self::assertSame('agent_tool.audit_finalize_failed', $critical[0]['message']);
        self::assertStringContainsString('Dangling reservation', (string) $critical[0]['context']['note']);
    }

    #[Test]
    public function a_refusal_from_the_tool_is_finalized_as_an_authorization_refusal(): void
    {
        $ledger = new RecordingStrictAuditLedger();
        $outcome = new AuditedToolDispatcher(
            $this->inner(result: AgentToolResult::error('nope', 'forbidden')),
            $ledger,
            'local.stdio',
            'corr-1',
        )->dispatch('probe', []);

        self::assertSame(AuditStage::AuthorizationRefused, $outcome->stage);
        self::assertSame(['reserve', 'finalize'], $ledger->calls());
        self::assertSame(AuditStage::AuthorizationRefused, $ledger->entriesFor('finalize')[0]['stage']);
    }

    #[Test]
    public function listing_and_lookup_pass_through_unchanged(): void
    {
        $dispatcher = new AuditedToolDispatcher(
            $this->inner(),
            new RecordingStrictAuditLedger(),
            'local.stdio',
            'corr-1',
        );

        self::assertSame(['probe'], array_map(static fn(AgentTool $t): string => $t->name, $dispatcher->tools()));
        self::assertNotNull($dispatcher->tool('probe'));
        self::assertNull($dispatcher->tool('absent'));
    }

    // ------------------------- unrecordable requests (Codex P2, PR #2718) ---

    /**
     * The reported defect, at its root.
     *
     * `dispatch('')` is reachable from caller input — the MCP `tools/call`
     * envelope check asserts only that `name` is a string — and it lands on the
     * unknown-tool path. Passing the empty name through as the audit
     * `operation` made `StrictAuditReservation` throw inside `recordTerminal()`,
     * where a broad catch absorbed it: the caller received an ordinary
     * `TOOL_NOT_FOUND` and the strict ledger recorded nothing at all. That is
     * the exact guarantee this class exists to provide, defeated by an empty
     * string.
     */
    #[Test]
    public function an_empty_tool_name_is_durably_recorded_rather_than_silently_passed_through(): void
    {
        $ledger = new RecordingStrictAuditLedger();

        $outcome = new AuditedToolDispatcher($this->inner(), $ledger, 'local.stdio', 'corr-1')
            ->dispatch('', ['a' => 1]);

        // The refusal is still an ordinary lookup refusal — because it WAS
        // recorded. The fix must not turn a recordable refusal into an outage.
        self::assertSame(AuditStage::ToolLookupRefused, $outcome->stage);
        self::assertSame(['record'], $ledger->calls());

        $reservation = $ledger->entriesFor('record')[0]['reservation'];
        self::assertInstanceOf(StrictAuditReservation::class, $reservation);
        self::assertSame(AuditedToolDispatcher::UNUSABLE_OPERATION, $reservation->operation);
        // The name is evidence and survives, in the field a value object does
        // not police.
        self::assertSame('', $reservation->metadata['requested_tool']);
        self::assertSame('blank', $reservation->metadata['requested_tool_rejected']);
        self::assertSame(0, $reservation->metadata['requested_tool_length']);
        self::assertSame(1, $reservation->metadata['argument_count']);
    }

    #[Test]
    public function a_whitespace_only_tool_name_is_treated_the_same_way(): void
    {
        // The value object accepts "   ", so this one would not have thrown —
        // but an operation of three spaces tells an operator nothing, and
        // "there is no usable name here" is one condition with one answer.
        $ledger = new RecordingStrictAuditLedger();

        new AuditedToolDispatcher($this->inner(), $ledger, 'local.stdio', 'corr-1')->dispatch('   ', []);

        $reservation = $ledger->entriesFor('record')[0]['reservation'];
        self::assertInstanceOf(StrictAuditReservation::class, $reservation);
        self::assertSame(AuditedToolDispatcher::UNUSABLE_OPERATION, $reservation->operation);
        self::assertSame('   ', $reservation->metadata['requested_tool']);
        self::assertSame(3, $reservation->metadata['requested_tool_length']);
    }

    #[Test]
    public function an_overlong_tool_name_is_bounded_and_its_true_length_recorded(): void
    {
        // The name is caller-controlled and unbounded; the operation is a
        // durable column. Bounding makes "the ledger always receives an
        // operation it accepts" total rather than a patch over the empty case.
        $ledger = new RecordingStrictAuditLedger();
        $name = str_repeat('n', 5000);

        new AuditedToolDispatcher($this->inner(), $ledger, 'local.stdio', 'corr-1')->dispatch($name, []);

        $reservation = $ledger->entriesFor('record')[0]['reservation'];
        self::assertInstanceOf(StrictAuditReservation::class, $reservation);
        self::assertSame(200, \strlen($reservation->operation));
        self::assertTrue($reservation->metadata['requested_tool_truncated']);
        self::assertSame(5000, $reservation->metadata['requested_tool_length']);
    }

    #[Test]
    public function an_ordinary_name_is_still_the_operation_verbatim(): void
    {
        // The projection must not rewrite the common case: existing ledger
        // queries key off the tool name.
        $ledger = new RecordingStrictAuditLedger();

        new AuditedToolDispatcher($this->inner(), $ledger, 'local.stdio', 'corr-1')->dispatch('unknown_tool', []);

        $reservation = $ledger->entriesFor('record')[0]['reservation'];
        self::assertInstanceOf(StrictAuditReservation::class, $reservation);
        self::assertSame('unknown_tool', $reservation->operation);
        self::assertArrayNotHasKey('requested_tool', $reservation->metadata);
    }

    /**
     * The deeper defect the empty name exposed: a ledger that cannot record a
     * terminal refusal must not be indistinguishable from one that did.
     */
    #[Test]
    public function a_terminal_refusal_the_ledger_rejects_is_refused_not_answered(): void
    {
        $logger = new RecordingLogger();

        $outcome = new AuditedToolDispatcher(
            $this->inner(),
            new RecordFailingStrictAuditLedger(),
            'local.stdio',
            'corr-1',
            logger: $logger,
        )->dispatch('nope', []);

        self::assertSame(AuditStage::AuditUnavailableRefused, $outcome->stage);
        self::assertNotSame(AuditStage::ToolLookupRefused, $outcome->stage);

        $body = json_decode($outcome->envelope['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(AuditedToolDispatcher::AUDIT_UNAVAILABLE_CODE, $body['code']);
        self::assertSame('corr-1', $body['correlation_id']);
        // The caller must not be able to read this as a plain unknown tool.
        self::assertArrayNotHasKey('errors', $body);
        self::assertStringNotContainsString('TOOL_NOT_FOUND', $outcome->envelope['content'][0]['text']);
        self::assertStringNotContainsString('terminal record offline', $outcome->envelope['content'][0]['text']);

        $critical = $logger->withLevel('critical');
        self::assertCount(1, $critical);
        self::assertSame('agent_tool.audit_terminal_record_failed', $critical[0]['message']);
    }

    #[Test]
    public function a_schema_violation_the_ledger_cannot_record_is_also_refused(): void
    {
        // Both terminal paths, not just the one the report happened to reach.
        $registry = new ArrayToolRegistry([
            $this->tool(schema: ['type' => 'object', 'required' => ['q'], 'properties' => ['q' => ['type' => 'string']]]),
        ]);

        $outcome = new AuditedToolDispatcher(
            new AgentToolDispatcher($registry, new FixedPrincipal()),
            new RecordFailingStrictAuditLedger(),
            'local.stdio',
            'corr-1',
        )->dispatch('probe', []);

        self::assertSame(AuditStage::AuditUnavailableRefused, $outcome->stage);
        self::assertNotSame(AuditStage::InputValidationRefused, $outcome->stage);
    }

    /**
     * The catch on the reservation path is `\Throwable` on purpose: the
     * reservation is constructed inside it, and a value object rejects with
     * `\InvalidArgumentException`, which is not a ledger exception. A catch
     * narrowed to the ledger's own type would let that escape `dispatch()`,
     * breaking `ToolDispatcherInterface`'s contract that a caller-caused
     * failure never throws.
     */
    #[Test]
    public function a_non_ledger_exception_still_fails_closed_instead_of_escaping(): void
    {
        /** @var \ArrayObject<int, string> $order */
        $order = new \ArrayObject();

        $outcome = new AuditedToolDispatcher(
            $this->inner(order: $order),
            new NonLedgerExceptionStrictAuditLedger(),
            'local.stdio',
            'corr-1',
        )->dispatch('probe', []);

        self::assertSame(AuditStage::AuditUnavailableRefused, $outcome->stage);
        self::assertSame([], $order->getArrayCopy(), 'The tool must not run when the attempt cannot be recorded.');
    }

    #[Test]
    public function a_non_ledger_exception_on_the_terminal_path_also_fails_closed(): void
    {
        $outcome = new AuditedToolDispatcher(
            $this->inner(),
            new NonLedgerExceptionStrictAuditLedger(),
            'local.stdio',
            'corr-1',
        )->dispatch('nope', []);

        self::assertSame(AuditStage::AuditUnavailableRefused, $outcome->stage);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param ?\ArrayObject<int, string> $order
     * @param ?\Closure(array<string, mixed>): array<string, mixed> $redactor
     */
    private function inner(
        ?\ArrayObject $order = null,
        ?\Closure $redactor = null,
        ?AgentToolResult $result = null,
    ): ToolDispatcherInterface {
        return new AgentToolDispatcher(
            new ArrayToolRegistry([$this->tool($order, $redactor, $result)]),
            new FixedPrincipal(),
        );
    }

    /**
     * @param ?\ArrayObject<int, string> $order
     * @param ?\Closure(array<string, mixed>): array<string, mixed> $redactor
     * @param array<string, mixed> $schema
     */
    private function tool(
        ?\ArrayObject $order = null,
        ?\Closure $redactor = null,
        ?AgentToolResult $result = null,
        array $schema = ['type' => 'object'],
    ): AgentTool {
        return new AgentTool(
            name: 'probe',
            capability: 'probe.read',
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: $schema,
            impl: new ScriptedTool(
                static fn(array $args): AgentToolResult => $result
                    ?? AgentToolResult::success([['type' => 'text', 'text' => 'ok']]),
                $redactor,
                $order,
                $schema,
            ),
        );
    }
}
