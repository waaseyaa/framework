<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Dispatch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher;
use Waaseyaa\AI\Tools\Dispatch\ToolDispatchOutcome;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\ArrayToolRegistry;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\FixedPrincipal;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\RecordingLogger;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\ScriptedTool;
use Waaseyaa\Foundation\Audit\AuditStage;

/**
 * The transport-neutral dispatch path extracted from
 * `Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge` by #2657 (ADR-022 D-9.3).
 *
 * These assertions restate the guarantees the bridge already carried, now at
 * the layer that owns them: the advertised `inputSchema` is the enforced one,
 * an escaping exception never reaches the caller, an advertised `outputSchema`
 * is enforced on the way back, and the audit stage is decided while the
 * `AgentToolResult` and its `summary` are still in hand — because the envelope
 * alone cannot distinguish "refused" from "broke".
 */
#[CoversClass(AgentToolDispatcher::class)]
#[CoversClass(ToolDispatchOutcome::class)]
final class AgentToolDispatcherTest extends TestCase
{
    #[Test]
    public function tools_are_listed_in_name_order_regardless_of_registration_order(): void
    {
        // Wire output must be independent of Composer manifest, classmap, and
        // provider discovery order so a client can diff two catalogues.
        $dispatcher = $this->dispatcher(new ArrayToolRegistry([
            $this->tool('zulu'),
            $this->tool('alpha'),
            $this->tool('mike'),
        ]));

        self::assertSame(
            ['alpha', 'mike', 'zulu'],
            array_map(static fn(AgentTool $t): string => $t->name, $dispatcher->tools()),
        );
    }

    #[Test]
    public function an_unknown_tool_yields_a_structured_refusal_built_from_the_callers_own_name(): void
    {
        $outcome = $this->dispatcher()->dispatch('does_not_exist', []);

        self::assertSame(AuditStage::ToolLookupRefused, $outcome->stage);
        self::assertTrue($outcome->isError());
        $body = $this->body($outcome);
        self::assertSame('TOOL_NOT_FOUND', $body['code']);
        self::assertStringContainsString('does_not_exist', $body['message']);
        self::assertNull($this->dispatcher()->tool('does_not_exist'));
    }

    #[Test]
    public function schema_violations_short_circuit_before_the_handler_runs(): void
    {
        /** @var \ArrayObject<int, string> $order */
        $order = new \ArrayObject();
        $registry = new ArrayToolRegistry([
            $this->tool(
                'probe',
                schema: ['type' => 'object', 'required' => ['q'], 'properties' => ['q' => ['type' => 'string']]],
                order: $order,
            ),
        ]);

        $outcome = $this->dispatcher($registry)->dispatch('probe', []);

        self::assertSame([], $order->getArrayCopy(), 'The handler must never see malformed input.');
        self::assertSame(AuditStage::InputValidationRefused, $outcome->stage);
        $body = $this->body($outcome);
        self::assertSame('VALIDATION_FAILED', $body['code']);
        self::assertNotSame([], $body['errors']);
    }

    #[Test]
    public function an_escaping_exception_is_sanitized_and_the_detail_goes_to_the_log(): void
    {
        // An exception's message is operator-facing: DSNs, credentials,
        // absolute paths, internal class names. It never reaches the caller.
        $logger = new RecordingLogger();
        $registry = new ArrayToolRegistry([
            $this->tool('probe', handler: static fn(array $a): AgentToolResult => throw new \RuntimeException(
                'Connection failed: pgsql://user:hunter2@db.internal/app',
            )),
        ]);

        $outcome = $this->dispatcher($registry, $logger)->dispatch('probe', []);

        self::assertSame(AuditStage::ExecutionFailed, $outcome->stage);
        $text = $outcome->envelope['content'][0]['text'];
        self::assertStringNotContainsString('hunter2', $text);
        self::assertStringNotContainsString('db.internal', $text);

        $errors = $logger->withLevel('error');
        self::assertCount(1, $errors);
        self::assertSame('tool_dispatch.tool_execution_failed', $errors[0]['message']);
    }

    #[Test]
    public function the_log_prefix_is_the_hosts_to_choose(): void
    {
        // `waaseyaa/mcp` passes `mcp`, preserving `mcp.tool_execution_failed`.
        // The default is deliberately NOT `agent_tool`: that prefix already
        // belongs to a tool's own catch, and sharing it would collapse "the
        // tool handled it" into "the tool escaped and was sanitized".
        $logger = new RecordingLogger();
        $registry = new ArrayToolRegistry([
            $this->tool('probe', handler: static fn(array $a): AgentToolResult => throw new \RuntimeException('boom')),
        ]);

        new AgentToolDispatcher($registry, new FixedPrincipal(), $logger, 'mcp')->dispatch('probe', []);

        self::assertSame('mcp.tool_execution_failed', $logger->withLevel('error')[0]['message']);
    }

    #[Test]
    public function an_advertised_output_schema_is_enforced_on_the_way_back(): void
    {
        $logger = new RecordingLogger();
        $registry = new ArrayToolRegistry([
            $this->tool(
                'probe',
                handler: static fn(array $a): AgentToolResult => AgentToolResult::success(
                    [['type' => 'text', 'text' => 'ok']],
                    structuredContent: ['wrong' => true],
                ),
                outputSchema: ['type' => 'object', 'required' => ['count'], 'properties' => ['count' => ['type' => 'integer']]],
            ),
        ]);

        $outcome = $this->dispatcher($registry, $logger)->dispatch('probe', []);

        self::assertSame(AuditStage::ExecutionFailed, $outcome->stage);
        self::assertSame('tool_dispatch.tool_output_schema_violation', $logger->withLevel('error')[0]['message']);
    }

    #[Test]
    public function a_missing_structured_content_violates_an_advertised_output_schema(): void
    {
        $registry = new ArrayToolRegistry([
            $this->tool(
                'probe',
                handler: static fn(array $a): AgentToolResult => AgentToolResult::success([['type' => 'text', 'text' => 'ok']]),
                outputSchema: ['type' => 'object'],
            ),
        ]);

        self::assertSame(AuditStage::ExecutionFailed, $this->dispatcher($registry)->dispatch('probe', [])->stage);
    }

    #[Test]
    public function a_forbidden_summary_is_an_authorization_refusal_not_a_failure(): void
    {
        // `forbidden` is the summary every AbstractAgentTool guard emits for a
        // capability, per-entity, or per-field denial. Treating it as a generic
        // failure would leave the audit trail unable to answer "was this
        // refused, or did it break?".
        $refused = new ArrayToolRegistry([
            $this->tool('probe', handler: static fn(array $a): AgentToolResult => AgentToolResult::error('no', 'forbidden')),
        ]);
        $broke = new ArrayToolRegistry([
            $this->tool('probe', handler: static fn(array $a): AgentToolResult => AgentToolResult::error('no', 'exploded')),
        ]);

        self::assertSame(AuditStage::AuthorizationRefused, $this->dispatcher($refused)->dispatch('probe', [])->stage);
        self::assertSame(AuditStage::ExecutionFailed, $this->dispatcher($broke)->dispatch('probe', [])->stage);
    }

    #[Test]
    public function a_success_carries_its_structured_content_and_no_error_flag(): void
    {
        $registry = new ArrayToolRegistry([
            $this->tool('probe', handler: static fn(array $a): AgentToolResult => AgentToolResult::success(
                [['type' => 'text', 'text' => 'ok']],
                structuredContent: ['count' => 1],
            )),
        ]);

        $outcome = $this->dispatcher($registry)->dispatch('probe', []);

        self::assertSame(AuditStage::ExecutionSucceeded, $outcome->stage);
        self::assertFalse($outcome->isError());
        self::assertArrayNotHasKey('isError', $outcome->envelope);
        self::assertSame(['count' => 1], $outcome->envelope['structuredContent']);
    }

    #[Test]
    public function the_acting_principal_is_forwarded_to_the_tool(): void
    {
        // ADR-019: per-tool capability enforcement runs as the initiator, so
        // the dispatcher must hand the tool the principal it was bound to and
        // not a placeholder.
        $seen = new \ArrayObject();
        $registry = new ArrayToolRegistry([
            new AgentTool(
                name: 'probe',
                capability: 'probe.read',
                destructive: false,
                dryRunSupported: false,
                category: 'test',
                inputSchema: ['type' => 'object'],
                impl: new class ($seen) implements \Waaseyaa\AI\Tools\AgentToolInterface {
                    /** @param \ArrayObject<int, bool> $seen */
                    public function __construct(private readonly \ArrayObject $seen) {}

                    public function execute(array $arguments, \Waaseyaa\Access\AuthorizationPrincipalInterface $account): AgentToolResult
                    {
                        $this->seen->append($account->hasPermission('probe.read'));

                        return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
                    }

                    public function dryRun(array $arguments, \Waaseyaa\Access\AuthorizationPrincipalInterface $account): AgentToolResult
                    {
                        return $this->execute($arguments, $account);
                    }

                    public function argumentsForAudit(array $arguments): array
                    {
                        return $arguments;
                    }

                    public function inputSchema(): array
                    {
                        return ['type' => 'object'];
                    }

                    public function description(): string
                    {
                        return 'Probe.';
                    }
                },
            ),
        ]);

        new AgentToolDispatcher($registry, new FixedPrincipal(['probe.read']))->dispatch('probe', []);
        new AgentToolDispatcher($registry, new FixedPrincipal([]))->dispatch('probe', []);

        self::assertSame([true, false], $seen->getArrayCopy());
    }

    // ------------------------------------------------------------- fixtures

    private function dispatcher(?ArrayToolRegistry $registry = null, ?RecordingLogger $logger = null): AgentToolDispatcher
    {
        return new AgentToolDispatcher(
            $registry ?? new ArrayToolRegistry(),
            new FixedPrincipal(['probe.read']),
            $logger,
        );
    }

    /** @return array<string, mixed> */
    private function body(\Waaseyaa\AI\Tools\Dispatch\ToolDispatchOutcome $outcome): array
    {
        return json_decode($outcome->envelope['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $schema
     * @param ?\Closure(array<string, mixed>): AgentToolResult $handler
     * @param ?array<string, mixed> $outputSchema
     * @param ?\ArrayObject<int, string> $order
     */
    private function tool(
        string $name,
        array $schema = ['type' => 'object'],
        ?\Closure $handler = null,
        ?array $outputSchema = null,
        ?\ArrayObject $order = null,
    ): AgentTool {
        return new AgentTool(
            name: $name,
            capability: 'probe.read',
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: $schema,
            impl: new ScriptedTool(
                $handler ?? static fn(array $a): AgentToolResult => AgentToolResult::success([['type' => 'text', 'text' => 'ok']]),
                null,
                $order,
                $schema,
            ),
            outputSchema: $outputSchema,
        );
    }
}
