<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
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
     * @param object $event  Expects properties: method (string), params (array), accountUid (int).
     */
    public function onMcpDispatch(object $event): void
    {
        try {
            $method = property_exists($event, 'method') ? (string) $event->method : 'unknown';
            $params = property_exists($event, 'params') ? $event->params : [];
            $accountUid = property_exists($event, 'accountUid') ? (int) $event->accountUid : 0;

            $paramsHash = hash('sha256', json_encode($params, JSON_THROW_ON_ERROR));

            $this->writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::McpDispatch,
                accountUid: $accountUid,
                subjectUri: sprintf('/mcp/rpc/%s', $method),
                outcome: 'allowed',
                severity: 'info',
                attributes: [
                    'method'      => $method,
                    'params_hash' => $paramsHash,
                ],
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
