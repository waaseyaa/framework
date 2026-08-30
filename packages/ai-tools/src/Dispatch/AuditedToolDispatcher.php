<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Dispatch;

use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\NullStrictAuditLedger;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Reserve-before-side-effect audit enforcement around a
 * {@see ToolDispatcherInterface} (ADR-022 D-5.A and D-5.B).
 *
 * ## D-5.A — the ledger must be real
 *
 * A `NullStrictAuditLedger` implements {@see StrictAuditLedgerInterface} and
 * records nothing, so "dispatch is recorded through the strict ledger" is
 * otherwise satisfiable by a no-op. Construction refuses when the resolved
 * ledger is absent **or** is that record-nothing implementation. The precedent
 * is exact and already in this codebase: `McpEndpoint` refuses construction on
 * the same two grounds (`packages/mcp/src/McpEndpoint.php:130-146`), on the
 * stated reasoning that such a surface "LOOKS durably audited and records
 * nothing" and that the construction *is* the wiring error. Discovering it at
 * dispatch time would mean discovering it after a caller already believed the
 * session was audited.
 *
 * ## D-5.B — reserve, execute, finalize
 *
 * `reserve()` is durable **before** the tool runs, so no tool can act without a
 * durable record of the attempt; the outcome is finalized after. A terminal
 * refusal that never reaches execution uses the single-shot `record()` rather
 * than leaving a dangling reservation — an unknown tool and a schema violation
 * both refuse before anything is reserved.
 *
 * `safeArguments` is the **tool's own** `argumentsForAudit()` projection, never
 * the raw caller-supplied arguments (D-5.B.4). A tool that throws while
 * redacting does not take the request down and does not cause raw arguments to
 * be substituted: the fallback is structural metadata only.
 *
 * ## An unrecordable request is refused, never quietly answered
 *
 * Every path out of {@see dispatch()} is either durably recorded or answered
 * with `AUDIT_TRAIL_UNAVAILABLE`. There is no third state in which a caller is
 * told what happened while the ledger holds nothing about it.
 *
 * This is a correction, and the defect it fixes is worth stating plainly. The
 * terminal-record path used to return `void` and swallow every failure, so
 * "recorded" and "lost" looked identical from outside. `dispatch('')` reached
 * it — the name is caller-controlled and the MCP `tools/call` envelope check
 * only asserts that `name` is a string — `StrictAuditReservation` rejected the
 * empty `operation`, the catch absorbed it, and the caller received an ordinary
 * `TOOL_NOT_FOUND` against an empty ledger. Two things were wrong: a value the
 * class fed to a value object that would not accept it (fixed by
 * {@see auditOperation()}, which makes a non-empty bounded operation a total
 * invariant while the raw name survives in `metadata`), and a swallow that hid
 * the consequence (fixed by reporting it). The second is the one that mattered
 * — the empty name was one route to it, not the whole of it.
 *
 * ## D-5.C — the transport owns its identity, and this class enforces that
 *
 * ADR-022 D-5.C assigns the `surface` constant and the per-request correlation
 * id to the transport (#2659), not to this dispatcher, so both are constructor
 * arguments. What this class owns is the *invariant* attached to them: a
 * surface that is `mcp` or begins `mcp.` is refused, because those are the
 * identifiers the HTTP tiers already write (`McpEndpoint::auditSurface()`
 * returns `'mcp.' . $tier`, i.e. `mcp.public` and `mcp.write`). Reusing one
 * would make a local developer session and a network caller indistinguishable
 * on inspection of the ledger, which is the exact property D-5.C exists to
 * preserve. Naming is the transport's; not colliding with HTTP is not
 * negotiable, so it is checked here rather than trusted there.
 *
 * ## What this class deliberately does not do
 *
 * It does not authorize. Capability enforcement stays inside each tool
 * (`AbstractAgentTool::requireCapability()`) and visibility stays in the
 * narrowing registries. Wrapping the dispatcher in audit changes what is
 * *recorded*, never what is *permitted*.
 *
 * @api
 */
final class AuditedToolDispatcher implements ToolDispatcherInterface
{
    /**
     * Audit surfaces already owned by the HTTP MCP tiers.
     *
     * `McpEndpoint::auditSurface()` composes `'mcp.' . $rateLimitTier`, so the
     * live values are `mcp.public` and `mcp.write`. The bare `mcp` is reserved
     * alongside them so a near-miss cannot slip through.
     */
    private const string RESERVED_SURFACE_PREFIX = 'mcp.';

    private const string RESERVED_SURFACE = 'mcp';

    /** Machine code an agent can branch on when the ledger refuses the attempt. */
    public const string AUDIT_UNAVAILABLE_CODE = 'AUDIT_TRAIL_UNAVAILABLE';

    /**
     * The audit operation recorded when the requested tool name cannot serve as
     * one in its own right.
     *
     * `StrictAuditReservation` rejects an empty `operation` outright, and this
     * class accepts `dispatch('')` from caller input — the MCP `tools/call`
     * envelope check only asserts that `name` is a string. Passing the empty
     * name straight through therefore made the value object throw *inside* the
     * record path, where a broad catch swallowed it: the caller got an ordinary
     * `TOOL_NOT_FOUND` and the ledger recorded nothing, which is exactly the
     * guarantee this class exists to provide. The name is evidence and must
     * survive into the record, but it cannot be the field a value object
     * refuses, so it moves to `metadata` and a fixed operation takes its place.
     */
    public const string UNUSABLE_OPERATION = 'tool_name_unusable';

    /**
     * Longest caller-supplied tool name used verbatim as an audit operation.
     *
     * The name is caller-controlled and unbounded; an audit operation is a
     * durable column. Bounding it here — rather than only rejecting the empty
     * case — is what makes "every reservation this class builds carries an
     * acceptable operation" a total invariant instead of a patch over the one
     * input someone happened to find. The full length always travels in
     * `metadata`, so truncation loses no evidence.
     */
    private const int MAX_OPERATION_LENGTH = 200;

    private readonly StrictAuditLedgerInterface $ledger;

    /**
     * @param ToolDispatcherInterface $inner The dispatch path being audited.
     * @param ?StrictAuditLedgerInterface $ledger The resolved ledger. Nullable so
     *        that "no ledger could be resolved" is representable and therefore
     *        refusable — a non-nullable parameter would move that failure to the
     *        caller's wiring, where it is silent.
     * @param string $surface The calling surface, owned by the transport (D-5.C).
     *        MUST NOT be an HTTP MCP identifier.
     * @param string $correlationId Per-request, joining every record produced by
     *        one tool call (D-5.C.6). The transport mints it.
     * @param ?int $actorUid Three-state actor: id N, `0` only when the actor IS
     *        anonymous, `null` for no known persisted principal. The local
     *        operator has no persisted account, so it passes `null` rather than a
     *        coerced `0`, which would attribute the session to `AnonymousUser`
     *        (D-5.D.7). Never coerce.
     * @param array<string, mixed> $metadata Safe structural metadata joined to
     *        every record this dispatcher writes.
     * @param ?LoggerInterface $logger Where an audit gap is reported. A failure to
     *        finalize is logged at critical and never rethrown — the side effect
     *        has already happened.
     *
     * @throws \LogicException D-5.A when the ledger is absent or records nothing;
     *         D-5.C when the surface collides with an HTTP MCP identifier.
     */
    public function __construct(
        private readonly ToolDispatcherInterface $inner,
        ?StrictAuditLedgerInterface $ledger,
        private readonly string $surface,
        private readonly string $correlationId,
        private readonly ?int $actorUid = null,
        private readonly array $metadata = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        // ADR-022 D-5.A. Mirrors McpEndpoint's construction-time refusal rather
        // than reinventing it: a dispatcher that LOOKS durably audited and
        // records nothing is worse than one that admits it is not audited,
        // because an operator reading an empty ledger cannot tell the two apart.
        if (!$ledger instanceof StrictAuditLedgerInterface) {
            throw new \LogicException(
                'AuditedToolDispatcher requires a real StrictAuditLedgerInterface. '
                . 'Refusing to construct an audited dispatcher with no ledger — every dispatch '
                . 'would run with no durable evidence that it was attempted. Resolve a ledger '
                . '(waaseyaa/audit ships DatabaseStrictAuditLedger), or dispatch through the '
                . 'unaudited AgentToolDispatcher and accept that nothing is recorded.',
            );
        }
        if ($ledger instanceof NullStrictAuditLedger) {
            throw new \LogicException(
                'AuditedToolDispatcher requires a real StrictAuditLedgerInterface. '
                . 'NullStrictAuditLedger implements the interface and records nothing, so it '
                . 'satisfies the type and not the guarantee: the dispatcher would look durably '
                . 'audited and produce an empty trail. Supply a ledger that persists, or '
                . 'dispatch through the unaudited AgentToolDispatcher.',
            );
        }
        $this->ledger = $ledger;

        if ($this->correlationId === '') {
            throw new \LogicException(
                'AuditedToolDispatcher requires a per-request correlation id: it is what joins '
                . 'the reservation, the finalization, and any terminal record of one tool call.',
            );
        }
        if ($this->surface === '') {
            throw new \LogicException(
                'AuditedToolDispatcher requires a non-empty audit surface naming this transport.',
            );
        }
        if ($this->surface === self::RESERVED_SURFACE || str_starts_with($this->surface, self::RESERVED_SURFACE_PREFIX)) {
            throw new \LogicException(\sprintf(
                'AuditedToolDispatcher refuses the audit surface "%s": "%s" and the "%s" prefix '
                . 'are the HTTP MCP tiers\' own identifiers (McpEndpoint::auditSurface() writes '
                . '"mcp.public" and "mcp.write"). ADR-022 D-5.C requires a dedicated surface so '
                . 'the ledger distinguishes a local session from a network caller on inspection. '
                . 'Choose a surface of this transport\'s own.',
                $this->surface,
                self::RESERVED_SURFACE,
                self::RESERVED_SURFACE_PREFIX,
            ));
        }
    }

    public function tools(): array
    {
        return $this->inner->tools();
    }

    public function tool(string $name): ?AgentTool
    {
        return $this->inner->tool($name);
    }

    /**
     * Reserve, dispatch, finalize (ADR-022 D-5.B).
     *
     * The order is the whole guarantee. Anything that refuses *before* the tool
     * could run is recorded with the single-shot `record()`, because there is no
     * outcome coming and a reservation with no finalization is the documented
     * crash signature — minting one deliberately would forge that signal.
     *
     * **Every exit from this method is either durably recorded or visibly
     * refused.** There is no third state in which the caller is told what
     * happened while the ledger holds nothing. That is not a stylistic
     * preference: a dispatcher whose audit failures are indistinguishable from
     * its audit successes is an unaudited dispatcher that reports otherwise —
     * the same defect D-5.A refuses at construction, arriving at runtime.
     */
    public function dispatch(string $toolName, array $arguments): ToolDispatchOutcome
    {
        $tool = $this->inner->tool($toolName);
        if ($tool === null) {
            $outcome = $this->inner->dispatch($toolName, $arguments);

            // An unknown tool cannot supply argumentsForAudit(), so only the
            // requested name and safe structural metadata are recorded — never
            // the argument values, whose shape is entirely caller-controlled.
            if (!$this->recordTerminal(
                $outcome->stage,
                $toolName,
                [],
                ['argument_count' => \count($arguments)],
            )) {
                return $this->auditUnavailable();
            }

            return $outcome;
        }

        // Schema validation runs BEFORE the reservation. A reservation means
        // "the tool is about to be invoked"; malformed input never gets that
        // far, so reserving first would both misdescribe the request and let a
        // caller write two durable rows per garbage payload — an amplification
        // path the audit trail must not open. The inner dispatcher validates
        // again: defence in depth, and unaudited callers keep their guarantee.
        $violations = ToolInputSchemaValidator::validate($tool->inputSchema, $arguments);
        if ($violations !== []) {
            $outcome = $this->inner->dispatch($toolName, $arguments);
            if (!$this->recordTerminal(
                $outcome->stage,
                $toolName,
                [],
                ['violation_count' => \count($violations)],
            )) {
                return $this->auditUnavailable();
            }

            return $outcome;
        }

        // Redaction is the TOOL's own transform, never the raw arguments.
        $safeArguments = $this->safeArguments($tool, $arguments);

        [$operation, $operationMetadata] = $this->auditOperation($toolName);

        $receipt = null;
        try {
            $receipt = $this->ledger->reserve(new StrictAuditReservation(
                correlationId: $this->correlationId,
                surface: $this->surface,
                operation: $operation,
                actorUid: $this->actorUid,
                safeArguments: $safeArguments,
                metadata: $this->metadata + $operationMetadata,
            ));
        } catch (\Throwable $e) {
            // Deliberately `\Throwable`, not only the ledger exception type.
            // The reservation is CONSTRUCTED inside this try, and
            // StrictAuditReservation throws \InvalidArgumentException on a value
            // it will not accept. A narrower catch would let that escape
            // dispatch() entirely, breaking ToolDispatcherInterface's contract
            // that a caller-caused failure never throws — and the answer to
            // "the attempt could not be made durable" is the same whichever
            // half failed: refuse, loudly.
            $this->logger?->critical('agent_tool.audit_reservation_failed', [
                'correlation_id' => $this->correlationId,
                'surface' => $this->surface,
                'tool' => $operation,
                'exception' => $e::class,
            ]);

            // Fail closed: the tool is never invoked, so no side effect can
            // occur without evidence that it was attempted. The caller learns
            // only that it was refused, plus the correlation id to hand to an
            // operator — no exception detail leaves.
            return $this->auditUnavailable();
        }

        $outcome = $this->inner->dispatch($toolName, $arguments);

        $this->finalizeQuietly($receipt, $outcome->stage, $operation);

        return $outcome;
    }

    /**
     * The refusal a caller receives when an attempt could not be made durable.
     *
     * Shared by the reservation path and the terminal-record path on purpose:
     * "this dispatcher could not record what you asked it to do" is one
     * condition with one answer, and giving it two shapes would invite a caller
     * to treat one of them as ordinary.
     */
    private function auditUnavailable(): ToolDispatchOutcome
    {
        return new ToolDispatchOutcome(
            self::errorEnvelope([
                'code' => self::AUDIT_UNAVAILABLE_CODE,
                'message' => 'Request refused: the audit trail is unavailable.',
                'correlation_id' => $this->correlationId,
            ]),
            AuditStage::AuditUnavailableRefused,
        );
    }

    /**
     * A caller-supplied tool name projected into an operation the strict ledger
     * will accept, plus whatever evidence that projection displaced.
     *
     * The invariant is total: every {@see StrictAuditReservation} this class
     * builds carries a non-empty, bounded operation, and the raw name is never
     * lost — it travels in `metadata` whenever it could not be the operation
     * verbatim.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function auditOperation(string $toolName): array
    {
        $length = \strlen($toolName);

        if (trim($toolName) === '') {
            // An empty operation is rejected by the value object; a
            // whitespace-only one is accepted and tells an operator nothing.
            // Both mean "there is no usable name here", so both take the fixed
            // operation and keep the raw value as evidence beside it.
            return [self::UNUSABLE_OPERATION, [
                'requested_tool' => $toolName,
                'requested_tool_rejected' => 'blank',
                'requested_tool_length' => $length,
            ]];
        }

        if ($length > self::MAX_OPERATION_LENGTH) {
            return [substr($toolName, 0, self::MAX_OPERATION_LENGTH), [
                'requested_tool_truncated' => true,
                'requested_tool_length' => $length,
            ]];
        }

        return [$toolName, []];
    }

    /**
     * The tool's own redaction transform, defensively guarded.
     *
     * A tool that throws while redacting must not take down the request, but it
     * also must not cause raw arguments to be substituted — the fallback is
     * structural metadata only.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function safeArguments(AgentTool $tool, array $arguments): array
    {
        try {
            return $tool->impl->argumentsForAudit($arguments);
        } catch (\Throwable) {
            return ['_redaction_unavailable' => true, 'argument_count' => \count($arguments)];
        }
    }

    /**
     * A terminal stage that never reaches execution: one durable record rather
     * than a reserve/finalize pair.
     *
     * **Returns whether the record is durable, and the caller must act on it.**
     * An earlier version returned `void` and swallowed every failure, which
     * made "the ledger recorded this refusal" and "the ledger rejected it"
     * indistinguishable to the caller — the deeper defect that an empty tool
     * name merely exposed, since `StrictAuditReservation` rejects an empty
     * `operation` and the swallow turned that into an ordinary-looking
     * `TOOL_NOT_FOUND` with nothing written. The old rationale was that a
     * refusal performs no side effect, so failing closed would trade a missing
     * log line for a denial of service. That reasoning does not survive
     * contact with the reservation path: when the ledger is down, every
     * *executable* call is already refused with the same
     * `AUDIT_TRAIL_UNAVAILABLE` envelope, so refusing terminal records too adds
     * no outage that is not already happening — it only stops this class
     * reporting a complete trail it does not have.
     *
     * The catch stays `\Throwable` deliberately, because the reservation is
     * constructed inside it and a value-object rejection must not escape
     * `dispatch()`; what changed is that the failure is now *reported* instead
     * of absorbed. Breadth was never the problem — silence was.
     *
     * @param string $toolName The raw caller-supplied name; projected through
     *        {@see auditOperation()} so the ledger always receives an operation
     *        it accepts and the name survives as evidence either way.
     * @param array<string, mixed> $safeArguments
     * @param array<string, mixed> $metadata
     *
     * @return bool True when the refusal is durably recorded.
     */
    private function recordTerminal(
        AuditStage $stage,
        string $toolName,
        array $safeArguments,
        array $metadata,
    ): bool {
        [$operation, $operationMetadata] = $this->auditOperation($toolName);

        try {
            $this->ledger->record(
                new StrictAuditReservation(
                    correlationId: $this->correlationId,
                    surface: $this->surface,
                    operation: $operation,
                    actorUid: $this->actorUid,
                    safeArguments: $safeArguments,
                    metadata: $this->metadata + $operationMetadata + $metadata,
                ),
                $stage,
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger?->critical('agent_tool.audit_terminal_record_failed', [
                'correlation_id' => $this->correlationId,
                'surface' => $this->surface,
                'operation' => $operation,
                'stage' => $stage->value,
                'exception' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Finalize, never rethrowing.
     *
     * The side effect has ALREADY happened. Retrying or rolling it back would
     * duplicate or silently undo it, so neither is attempted. The reservation
     * stays unfinalized, which is queryable ("reserved" with no "finalized")
     * and is the documented crash-window signature. Loud, and never silent.
     *
     * **This is the one place the swallow is correct, and the asymmetry with
     * {@see recordTerminal()} is deliberate.** There, a failure means the
     * ledger holds *nothing* about a request, so the caller must be told. Here
     * the ledger already holds the reservation: the failure is visible as a
     * dangling record rather than as an absence, and the tool has already run,
     * so there is nothing left to fail closed about. Converting this into a
     * refusal would report a committed action as rejected — a worse lie than
     * the missing outcome row it would be trying to avoid.
     */
    private function finalizeQuietly(StrictAuditReceipt $receipt, AuditStage $stage, string $toolName): void
    {
        try {
            $this->ledger->finalize($receipt, $stage, $this->metadata);
        } catch (\Throwable $e) {
            $this->logger?->critical('agent_tool.audit_finalize_failed', [
                'correlation_id' => $this->correlationId,
                'surface' => $this->surface,
                'receipt_id' => $receipt->id,
                'tool' => $toolName,
                'stage' => $stage->value,
                'exception' => $e::class,
                'note' => 'Dangling reservation: outcome unknown, side effect may have committed.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
     */
    private static function errorEnvelope(array $body): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => \json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            ]],
            'isError' => true,
        ];
    }
}
