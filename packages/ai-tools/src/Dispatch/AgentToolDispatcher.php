<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Dispatch;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Error\SanitizedToolError;
use Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * The transport-neutral tool-dispatch path: schema enforcement, exception
 * sanitization, and audit-stage classification, bound to one principal.
 *
 * This is the behaviour extracted verbatim from
 * `Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge` (ADR-022 D-9.3), which is now a
 * thin façade over it so the HTTP tiers keep their exact envelopes. The
 * extraction moved no logic downward into a lower layer and changed no output;
 * it only removed the requirement that a caller install a package that
 * registers routes in order to call a tool.
 *
 * The supplied principal is forwarded to every invocation so per-tool
 * capability enforcement runs as the initiator (ADR-019). Registry narrowing
 * (`CapabilityScopedToolRegistry`, `ToolIdAllowlistRegistry`) composes *around*
 * that, never instead of it: an allowlist decides what is visible, and
 * `AbstractAgentTool::requireCapability()` still decides what the principal may
 * run.
 *
 * This class is also the sanitization boundary for unhandled tool exceptions:
 * nothing derived from a {@see \Throwable} ever reaches the caller — see
 * {@see SanitizedToolError}.
 *
 * @api
 */
final class AgentToolDispatcher implements ToolDispatcherInterface
{
    /**
     * Log-event prefix for a host that does not name itself.
     *
     * It is deliberately NOT `agent_tool`: that prefix is already taken by a
     * tool's *own* catch (`agent_tool.execution_failed`), and collapsing the
     * two would make "the tool handled it" and "the tool escaped and the
     * dispatcher sanitized it" indistinguishable in the log. The HTTP adapter
     * passes `mcp`, preserving `mcp.tool_execution_failed` verbatim.
     */
    public const string DEFAULT_LOG_PREFIX = 'tool_dispatch';

    private readonly LoggerInterface $logger;

    /**
     * @param ToolRegistryInterface $registry The catalogue, already narrowed to
     *        whatever this caller may see.
     * @param AuthorizationPrincipalInterface $principal Forwarded to every
     *        invocation; per-tool capability checks run against it.
     * @param ?LoggerInterface $logger Destination for the detail of an unhandled
     *        tool exception. Optional so bare construction (unit tests, hosts with
     *        no logging) keeps working — it defaults to {@see NullLogger}, which
     *        discards the detail. Sanitization of the RESPONSE does not depend on
     *        a logger being present: with or without one, the caller receives the
     *        same fixed envelope.
     */
    public function __construct(
        private readonly ToolRegistryInterface $registry,
        private readonly AuthorizationPrincipalInterface $principal,
        ?LoggerInterface $logger = null,
        private readonly string $logPrefix = self::DEFAULT_LOG_PREFIX,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function tools(): array
    {
        $out = [];
        foreach ($this->registry->all() as $tool) {
            $out[] = $tool;
        }
        usort(
            $out,
            static fn(AgentTool $left, AgentTool $right): int => strcmp($left->name, $right->name),
        );

        return $out;
    }

    public function tool(string $name): ?AgentTool
    {
        try {
            return $this->registry->get($name);
        } catch (ToolNotFoundException) {
            return null;
        }
    }

    /**
     * Validate the call against the tool's declared schema, then dispatch.
     *
     * The schema enforced is `AgentTool::$inputSchema` — the exact object
     * `tools/list` advertises — so what a caller is told is what the server
     * holds it to, with no second source of truth. A violation short-circuits
     * before `$tool->impl->execute()`: handlers never see malformed input, and
     * their own domain validation (Content Publishing's field-level rules, per-
     * tool capability checks) is unchanged for input that does satisfy the
     * schema.
     */
    public function dispatch(string $toolName, array $arguments): ToolDispatchOutcome
    {
        try {
            $tool = $this->registry->get($toolName);
        } catch (ToolNotFoundException) {
            // The message is built from the caller's OWN tool name rather than
            // echoed from the exception, so this arm cannot become a leak if the
            // exception's text ever changes. An off-tier tool is hidden behind
            // this same response by the narrowing registries — "not registered"
            // and "not yours" are deliberately indistinguishable.
            return new ToolDispatchOutcome(
                self::errorEnvelope([
                    'code' => 'TOOL_NOT_FOUND',
                    'message' => \sprintf('No tool is registered under the name "%s".', $toolName),
                ]),
                AuditStage::ToolLookupRefused,
            );
        }

        $violations = ToolInputSchemaValidator::validate($tool->inputSchema, $arguments);
        if ($violations !== []) {
            return new ToolDispatchOutcome(
                self::validationFailedEnvelope($toolName, $violations),
                AuditStage::InputValidationRefused,
            );
        }

        try {
            $result = $tool->impl->execute($arguments, $this->principal);
        } catch (\Throwable $e) {
            // An exception that escapes a tool is an infrastructure failure, and
            // its message is operator-facing (DSNs, credentials, absolute paths,
            // internal class names). The caller gets a fixed envelope plus a
            // correlation id; the detail goes to the log under the same id.
            // Deliberate domain failures never reach here — tools return them as
            // AgentToolResult values, which pass through untouched below.
            $correlationId = SanitizedToolError::correlationId();
            $this->logger->error(
                $this->logPrefix . '.tool_execution_failed',
                SanitizedToolError::logContext($e, $correlationId, $toolName),
            );

            return new ToolDispatchOutcome(
                self::errorEnvelope(SanitizedToolError::body($correlationId)),
                AuditStage::ExecutionFailed,
            );
        }

        if (!$result->isError && $tool->outputSchema !== null) {
            $outputViolations = $result->structuredContent === null
                ? [['field' => '$', 'message' => 'structuredContent is required by the advertised outputSchema.']]
                : ToolInputSchemaValidator::validate($tool->outputSchema, $result->structuredContent);
            if ($outputViolations !== []) {
                $correlationId = SanitizedToolError::correlationId();
                $this->logger->error($this->logPrefix . '.tool_output_schema_violation', [
                    'correlation_id' => $correlationId,
                    'tool' => $toolName,
                    'violation_count' => \count($outputViolations),
                ]);

                return new ToolDispatchOutcome(
                    self::errorEnvelope(SanitizedToolError::body($correlationId)),
                    AuditStage::ExecutionFailed,
                );
            }
        }

        return new ToolDispatchOutcome(
            self::toolResultToEnvelope($result),
            self::classify($result),
        );
    }

    /**
     * The established structured error envelope, reusing the machine code and
     * `{field, message}` shape Content Publishing already emits — an agent
     * parses a schema rejection exactly like a domain rejection.
     *
     * @param list<array{field: string, message: string}> $violations
     *
     * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
     */
    private static function validationFailedEnvelope(string $toolName, array $violations): array
    {
        return self::errorEnvelope([
            'code' => 'VALIDATION_FAILED',
            'message' => \sprintf(
                'Arguments do not satisfy the declared input schema for "%s".',
                $toolName,
            ),
            'errors' => $violations,
        ]);
    }

    /**
     * Wrap a structured body in the `isError` result envelope. Every
     * dispatcher-authored failure goes through here, so they all share one
     * `{code, message, ...}` shape an agent can branch on.
     *
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

    /**
     * Classify a returned {@see AgentToolResult}.
     *
     * `forbidden` is the summary every `AbstractAgentTool` guard emits for a
     * capability, per-entity, or per-field denial — see
     * `AbstractAgentTool::requireCapability()` and its siblings. Treating that
     * as an authorization refusal rather than a generic execution failure is
     * what lets the audit trail answer "was this refused, or did it break?".
     */
    private static function classify(AgentToolResult $result): AuditStage
    {
        if (!$result->isError) {
            return AuditStage::ExecutionSucceeded;
        }

        return $result->summary === 'forbidden'
            ? AuditStage::AuthorizationRefused
            : AuditStage::ExecutionFailed;
    }

    /**
     * Convert an {@see AgentToolResult} into the tool-call result envelope.
     *
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    private static function toolResultToEnvelope(AgentToolResult $result): array
    {
        $envelope = ['content' => $result->content];
        if ($result->structuredContent !== null) {
            $envelope['structuredContent'] = $result->structuredContent;
        }
        if ($result->isError) {
            $envelope['isError'] = true;
        }

        return $envelope;
    }
}
