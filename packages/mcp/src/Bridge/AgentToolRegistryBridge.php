<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Bridge;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Error\SanitizedToolError;
use Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Per-request adapter binding the framework-wide
 * {@see AgentToolRegistryInterface} to MCP descriptors and calls.
 *
 * Construction is cheap; tool resolution is lazy through the underlying
 * `AttributeToolRegistry`. The supplied {@see AccountInterface} is forwarded
 * to every tool invocation so per-tool capability enforcement runs as the
 * initiator (see ADR-019).
 *
 * Every `tools/call` is validated against the tool's own advertised
 * `inputSchema` before the handler runs (#2145) — see {@see self::execute()}.
 *
 * This class is also the sanitization boundary for unhandled tool exceptions:
 * nothing derived from a {@see \Throwable} ever reaches the caller — see
 * {@see SanitizedToolError}.
 *
 * @api
 */
final class AgentToolRegistryBridge
{
    private readonly LoggerInterface $logger;

    /**
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account
     * @param ?LoggerInterface $logger Destination for the detail of an unhandled
     *        tool exception. Optional so bare construction (unit tests, hosts with
     *        no logging) keeps working — it defaults to {@see NullLogger}, which
     *        discards the detail. Sanitization of the RESPONSE does not depend on
     *        a logger being present: with or without one, the caller receives the
     *        same fixed envelope.
     */
    public function __construct(
        private readonly AgentToolRegistryInterface $registry,
        private readonly AuthorizationPrincipalInterface $account,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getTools(): array
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

    public function getTool(string $name): ?AgentTool
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
    public function execute(string $toolName, array $arguments): array
    {
        return $this->executeClassified($toolName, $arguments)->envelope;
    }

    /**
     * Execute and classify the result into an audit stage.
     *
     * The stage cannot be recovered from the envelope afterwards — a capability
     * refusal and an infrastructure failure both surface as `isError: true` — so
     * it is decided here, where the `AgentToolResult` and its `summary` are still
     * in hand. {@see execute()} is the unchanged envelope-only façade.
     */
    public function executeClassified(string $toolName, array $arguments): ToolExecutionOutcome
    {
        try {
            $tool = $this->registry->get($toolName);
        } catch (ToolNotFoundException) {
            // The message is built from the caller's OWN tool name rather than
            // echoed from the exception, so this arm cannot become a leak if the
            // exception's text ever changes. An off-tier tool is hidden behind
            // this same response by the tier registries — "not registered" and
            // "not yours" are deliberately indistinguishable.
            return new ToolExecutionOutcome(
                self::errorEnvelope([
                    'code' => 'TOOL_NOT_FOUND',
                    'message' => \sprintf('No tool is registered under the name "%s".', $toolName),
                ]),
                AuditStage::ToolLookupRefused,
            );
        }

        $violations = ToolInputSchemaValidator::validate($tool->inputSchema, $arguments);
        if ($violations !== []) {
            return new ToolExecutionOutcome(
                self::validationFailedEnvelope($toolName, $violations),
                AuditStage::InputValidationRefused,
            );
        }

        try {
            $result = $tool->impl->execute($arguments, $this->account);
        } catch (\Throwable $e) {
            // An exception that escapes a tool is an infrastructure failure, and
            // its message is operator-facing (DSNs, credentials, absolute paths,
            // internal class names). The caller gets a fixed envelope plus a
            // correlation id; the detail goes to the log under the same id.
            // Deliberate domain failures never reach here — tools return them as
            // AgentToolResult values, which pass through untouched below.
            $correlationId = SanitizedToolError::correlationId();
            $this->logger->error(
                'mcp.tool_execution_failed',
                SanitizedToolError::logContext($e, $correlationId, $toolName),
            );

            return new ToolExecutionOutcome(
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
                $this->logger->error('mcp.tool_output_schema_violation', [
                    'correlation_id' => $correlationId,
                    'tool' => $toolName,
                    'violation_count' => \count($outputViolations),
                ]);

                return new ToolExecutionOutcome(
                    self::errorEnvelope(SanitizedToolError::body($correlationId)),
                    AuditStage::ExecutionFailed,
                );
            }
        }

        return new ToolExecutionOutcome(
            self::toolResultToMcpEnvelope($result),
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
     * Wrap a structured body in the MCP `isError` result envelope. Every
     * bridge-authored failure goes through here, so they all share one
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
     * Convert an {@see AgentToolResult} into the MCP `tools/call` envelope.
     *
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    private static function toolResultToMcpEnvelope(AgentToolResult $result): array
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
