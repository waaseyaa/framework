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
 * Subscribes to agent tool-call events and records OCAP audit entries.
 *
 * Subscribes by class-name string to avoid a layer-1→layer-5 import violation.
 * The event class `Waaseyaa\AI\Observability\Event\AgentRunToolCallObserved` lives
 * in ai-observability (layer 5). Symfony EventDispatcher dispatches by FQCN string
 * so string-based subscription is equivalent to class-typed subscription.
 *
 * Per the spec package decision: "listeners live here so M-A5 only needs to
 * dispatch its own canonical event." Layer-discipline is maintained via string constant.
 *
 * Best-effort: exceptions caught and logged; primary request never disrupted
 * (NFR-001).
 *
 * @api
 */
final class AgentToolAuditListener implements EventSubscriberInterface
{
    /**
     * FQCN of the ai-observability event — referenced as a string constant to avoid
     * a cross-layer import from packages/audit (layer 1) into ai-observability (layer 5).
     */
    public const AGENT_TOOL_CALL_EVENT = 'Waaseyaa\\AI\\Observability\\Event\\AgentRunToolCallObserved';

    public function __construct(
        private readonly AuditWriterInterface $writer,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            self::AGENT_TOOL_CALL_EVENT => 'onToolCallObserved',
        ];
    }

    /**
     * @param object $event  Expects public properties: runId (string), toolName (string), succeeded (bool).
     */
    public function onToolCallObserved(object $event): void
    {
        try {
            $runId     = property_exists($event, 'runId') ? (string) $event->runId : 'unknown';
            $toolName  = property_exists($event, 'toolName') ? (string) $event->toolName : 'unknown';
            $succeeded = property_exists($event, 'succeeded') ? (bool) $event->succeeded : true;

            $this->writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::AgentToolExecute,
                accountUid: 0, // Agent runs are system-level; account bound by agent run context.
                subjectUri: sprintf('/agent/runs/%s/tools/%s', $runId, $toolName),
                outcome: $succeeded ? 'allowed' : 'error',
                severity: $succeeded ? 'info' : 'warning',
                attributes: [
                    'run_id'    => $runId,
                    'tool_name' => $toolName,
                    'succeeded' => $succeeded,
                ],
            ));
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => AuditEventKind::AgentToolExecute->value,
            ]);
        }
    }
}
