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
 * Subscribes to SSE broadcast publication events and records OCAP audit entries.
 *
 * The event name `waaseyaa.broadcast.publish` is the canonical forward-compatible
 * event dispatched by packages/foundation on each SSE message publication.
 * Attributes include channel and message id — NOT the payload (privacy constraint).
 *
 * Best-effort: exceptions caught and logged; primary request never disrupted
 * (NFR-001).
 *
 * @api
 */
final class BroadcastAuditListener implements EventSubscriberInterface
{
    /** Canonical event name dispatched by packages/foundation on each SSE publication. */
    public const EVENT_NAME = 'waaseyaa.broadcast.publish';

    public function __construct(
        private readonly AuditWriterInterface $writer,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            self::EVENT_NAME => 'onBroadcastPublish',
        ];
    }

    /**
     * @param object $event  Expects properties: channel (string), messageId (string|int), accountUid (int).
     */
    public function onBroadcastPublish(object $event): void
    {
        try {
            $channel = property_exists($event, 'channel') ? (string) $event->channel : 'unknown';
            $messageId = property_exists($event, 'messageId') ? (string) $event->messageId : '';
            $accountUid = property_exists($event, 'accountUid') ? (int) $event->accountUid : 0;

            $this->writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::BroadcastPublish,
                accountUid: $accountUid,
                subjectUri: sprintf('/broadcast/%s', $channel),
                outcome: 'allowed',
                severity: 'notice',
                attributes: [
                    'channel'    => $channel,
                    'message_id' => $messageId,
                ],
            ));
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => AuditEventKind::BroadcastPublish->value,
            ]);
        }
    }
}
