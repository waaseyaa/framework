<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Envelope;

/** Explicit service authority used for framework-owned persistent dispatch. @api */
final readonly class SystemQueueEnvelopeFactory implements QueueEnvelopeFactoryInterface
{
    public function __construct(
        private QueueSystemReason $reason,
        private string $serviceIdentity,
        private ?string $tenantId = null,
        private ?string $communityId = null,
    ) {
        if ($serviceIdentity === '') {
            throw new \InvalidArgumentException('Queue service identity cannot be empty.');
        }
    }

    public function wrap(object $message, string $serializedMessage): QueueEnvelopeV1
    {
        return QueueEnvelopeV1::forSystem(
            serializedMessage: $serializedMessage,
            reason: $this->reason,
            serviceIdentity: $this->serviceIdentity,
            tenantId: $this->tenantId,
            communityId: $this->communityId,
            correlationId: bin2hex(random_bytes(16)),
        );
    }
}
