<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;
use Waaseyaa\Queue\Envelope\QueueSystemReason;

final class QueueEnvelopeV1Test extends TestCase
{
    #[Test]
    public function system_envelope_round_trips_identifiers_without_entity_authority(): void
    {
        $message = new \stdClass();
        $message->entityType = 'node';
        $message->entityId = '7';
        $envelope = QueueEnvelopeV1::forSystem(
            serializedMessage: serialize($message),
            reason: QueueSystemReason::SystemJob,
            serviceIdentity: 'search-indexer',
            tenantId: 'tenant-a',
            communityId: 'community-a',
            correlationId: 'job-1',
        );

        $restored = unserialize(serialize($envelope));
        self::assertInstanceOf(QueueEnvelopeV1::class, $restored);
        self::assertSame(QueueEnvelopeV1::VERSION, $restored->version);
        self::assertSame('7', unserialize($restored->serializedMessage)->entityId);
        self::assertFalse(property_exists($restored, 'entity'));
    }

    #[Test]
    public function occurrence_identity_survives_envelope_round_trip(): void
    {
        $occurrence = new QueueOccurrenceV1(str_repeat('a', 64), 'retention', str_repeat('b', 64), 300_000);
        $envelope = QueueEnvelopeV1::forSystem(
            serialize(new \stdClass()),
            QueueSystemReason::Scheduler,
            'scheduler',
            null,
            null,
            'occurrence-1',
        )->withOccurrence($occurrence);

        $restored = unserialize(serialize($envelope));

        self::assertInstanceOf(QueueEnvelopeV1::class, $restored);
        self::assertEquals($occurrence, $restored->occurrence);
    }
}
