<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Envelope;

/**
 * Versioned queue authority envelope. The message payload is serialized
 * separately and must contain identifiers or explicit projections, not entity
 * objects.
 *
 * @api
 */
final readonly class QueueEnvelopeV1
{
    public const int VERSION = 1;

    private function __construct(
        public string $serializedMessage,
        public ?int $actorId,
        public ?string $claimsGeneration,
        public ?QueueSystemReason $systemReason,
        public ?string $serviceIdentity,
        public ?string $tenantId,
        public ?string $communityId,
        public string $correlationId,
        public int $version = self::VERSION,
    ) {
        if ($serializedMessage === '' || $correlationId === '' || $version !== self::VERSION) {
            throw new \InvalidArgumentException('QueueEnvelopeV1 requires a payload, correlation id, and version 1.');
        }
        $actorAuthority = $actorId !== null && $claimsGeneration !== null && $claimsGeneration !== '';
        $systemAuthority = $systemReason !== null && $serviceIdentity !== null && $serviceIdentity !== '';
        if ($actorAuthority === $systemAuthority) {
            throw new \InvalidArgumentException('QueueEnvelopeV1 requires exactly one actor or system authority.');
        }
    }

    public static function forSystem(
        string $serializedMessage,
        QueueSystemReason $reason,
        string $serviceIdentity,
        ?string $tenantId,
        ?string $communityId,
        string $correlationId,
    ): self {
        return new self(
            serializedMessage: $serializedMessage,
            actorId: null,
            claimsGeneration: null,
            systemReason: $reason,
            serviceIdentity: $serviceIdentity,
            tenantId: $tenantId,
            communityId: $communityId,
            correlationId: $correlationId,
        );
    }

    public static function forActor(
        string $serializedMessage,
        int $actorId,
        string $claimsGeneration,
        ?string $tenantId,
        ?string $communityId,
        string $correlationId,
    ): self {
        return new self(
            serializedMessage: $serializedMessage,
            actorId: $actorId,
            claimsGeneration: $claimsGeneration,
            systemReason: null,
            serviceIdentity: null,
            tenantId: $tenantId,
            communityId: $communityId,
            correlationId: $correlationId,
        );
    }
}
