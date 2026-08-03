<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Subscribes to MCP JSON-RPC dispatch events and records OCAP audit entries.
 *
 * The event name `waaseyaa.mcp.dispatch` is the canonical forward-compatible
 * event dispatched by the MCP endpoint on each JSON-RPC method invocation.
 * Attributes include the method name and a SHA-256 hash of the params —
 * NEVER the raw params (privacy / confidentiality constraint).
 *
 * Actor source: the event's `accountUid` (?int, the bearer-auth-resolved
 * account), preserved verbatim — a null or absent account stays null
 * ("no known account"), never coerced to 0 (#1645, contract clause 19).
 *
 * Best-effort: exceptions caught and logged; primary request never disrupted
 * (NFR-001).
 *
 * @api
 */
final class McpDispatchAuditListener implements EventSubscriberInterface
{
    /** Canonical event name dispatched by packages/mcp on each JSON-RPC call. */
    public const EVENT_NAME = 'waaseyaa.mcp.dispatch';

    public function __construct(
        private readonly AuditWriterInterface $writer,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            self::EVENT_NAME => 'onMcpDispatch',
        ];
    }

    /**
     * @param object $event  Expects properties: method (string), params (array), accountUid (?int).
     */
    public function onMcpDispatch(object $event): void
    {
        try {
            $method = property_exists($event, 'method') ? (string) $event->method : 'unknown';
            $params = property_exists($event, 'params') ? $event->params : [];
            // Null-preserving duck-read: an absent or null accountUid stays null
            // ("no known account") — never coerced to 0 (#1645, clause 19).
            $accountUid = property_exists($event, 'accountUid') && $event->accountUid !== null
                ? (int) $event->accountUid
                : null;

            $stage = property_exists($event, 'stage') && is_string($event->stage)
                ? AuditStage::tryFrom($event->stage)
                : null;

            // Outcome is DERIVED from the stage, never hardcoded. Before #2177
            // every row said `allowed`, so a capability refusal and a successful
            // publish were indistinguishable in the log.
            $outcome = $stage?->outcome() ?? 'allowed';
            $severity = $stage?->severity() ?? 'info';

            $toolName = property_exists($event, 'toolName') && is_string($event->toolName)
                ? $event->toolName
                : null;

            $attributes = [
                'method' => $method,
                'stage'  => $stage?->value,
            ];

            if ($toolName !== null) {
                $attributes['tool_name'] = $toolName;
            }
            if (property_exists($event, 'correlationId') && is_string($event->correlationId) && $event->correlationId !== '') {
                $attributes['correlation_id'] = $event->correlationId;
            }
            if (property_exists($event, 'tier') && is_string($event->tier)) {
                $attributes['tier'] = $event->tier;
            }
            // Already redacted upstream by the tool's own argumentsForAudit();
            // this listener never sees, and never hashes, the raw params.
            if (property_exists($event, 'safeArguments') && is_array($event->safeArguments) && $event->safeArguments !== []) {
                $attributes['safe_arguments'] = $event->safeArguments;
            }
            if (property_exists($event, 'metadata') && is_array($event->metadata) && $event->metadata !== []) {
                $attributes['metadata'] = $event->metadata;
            }

            // Legacy shape (no stage): keep the pre-#2177 params hash so an
            // out-of-tree dispatcher that still sends the old event is not
            // silently downgraded to an attribute-less row.
            if ($stage === null) {
                $attributes['params_hash'] = hash('sha256', json_encode($params, JSON_THROW_ON_ERROR));
            }

            $this->writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::McpDispatch,
                accountUid: $accountUid,
                subjectUri: $toolName !== null
                    ? sprintf('/mcp/rpc/%s/%s', $method, $toolName)
                    : sprintf('/mcp/rpc/%s', $method),
                outcome: $outcome,
                severity: $severity,
                attributes: $attributes,
            ));
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => AuditEventKind::McpDispatch->value,
            ]);
        }
    }
}
