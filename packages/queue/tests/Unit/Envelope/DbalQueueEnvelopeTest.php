<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Envelope;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Queue\DbalQueue;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Envelope\QueueSystemReason;
use Waaseyaa\Queue\Envelope\SystemQueueEnvelopeFactory;
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Transport\InMemoryTransport;

final class DbalQueueEnvelopeTest extends TestCase
{
    #[Test]
    public function persistentDispatchStoresTheVersionedAuthorityEnvelope(): void
    {
        $transport = new InMemoryTransport();
        $signer = new SignedQueuePayload(str_repeat('q', 32));
        new DbalQueue(
            $transport,
            $signer,
            envelopeFactory: new SystemQueueEnvelopeFactory(QueueSystemReason::SystemJob, 'search-indexer'),
        )->dispatch(new GenericMessage('refresh', ['entity_id' => 7]));

        $row = $transport->pop('default');
        self::assertNotNull($row);
        $envelope = unserialize($signer->open($row['payload']));

        self::assertInstanceOf(QueueEnvelopeV1::class, $envelope);
        self::assertInstanceOf(GenericMessage::class, unserialize($envelope->serializedMessage));
        self::assertSame('search-indexer', $envelope->serviceIdentity);
    }

    #[Test]
    public function defaultDispatchDoesNotAcquireSystemAuthorityByOmission(): void
    {
        $transport = new InMemoryTransport();
        $signer = new SignedQueuePayload(str_repeat('q', 32));
        new DbalQueue($transport, $signer)->dispatch(new GenericMessage('refresh'));

        $row = $transport->pop('default');
        self::assertNotNull($row);
        $decoded = unserialize($signer->open($row['payload']));

        self::assertInstanceOf(GenericMessage::class, $decoded);
        self::assertNotInstanceOf(QueueEnvelopeV1::class, $decoded);
    }

    #[Test]
    public function callerCreatedAuthorityEnvelopeCannotBeDispatchedWithoutReviewedFactory(): void
    {
        $transport = new InMemoryTransport();
        $queue = new DbalQueue($transport, new SignedQueuePayload(str_repeat('q', 32)));
        $envelope = QueueEnvelopeV1::forSystem(
            serialize(new GenericMessage('refresh')),
            QueueSystemReason::SystemJob,
            'caller-supplied',
            null,
            null,
            'caller-correlation',
        );

        try {
            $queue->dispatch($envelope);
            self::fail('Generic dispatch must reject caller-created authority envelopes.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(
                'Queue authority envelopes can only be created by the configured envelope factory.',
                $error->getMessage(),
            );
        }

        self::assertSame(0, $transport->size('default'));
    }

    #[Test]
    public function callerCreatedAuthorityEnvelopeCannotBeDoubleWrappedByReviewedFactory(): void
    {
        $transport = new InMemoryTransport();
        $queue = new DbalQueue(
            $transport,
            new SignedQueuePayload(str_repeat('q', 32)),
            envelopeFactory: new SystemQueueEnvelopeFactory(QueueSystemReason::SystemJob, 'reviewed-service'),
        );
        $envelope = QueueEnvelopeV1::forActor(
            serialize(new GenericMessage('refresh')),
            7,
            'claims-7',
            null,
            null,
            'caller-correlation',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue authority envelopes can only be created by the configured envelope factory.');

        try {
            $queue->dispatch($envelope);
        } finally {
            self::assertSame(0, $transport->size('default'));
        }
    }

    #[Test]
    public function nestedEntityPayloadEmitsOneDormantDiagnosticWithoutRejectingDispatch(): void
    {
        $warnings = [];
        $logger = new class ($warnings) implements LoggerInterface {
            use LoggerTrait;
            public function __construct(private array &$warnings) {}
            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::NOTICE && (string) $message === 'entity.deprecation' && ($context['reason'] ?? null) === 'serialized_entity_payload') {
                    $this->warnings[] = $context;
                }
            }
        };
        $entity = new SerializableQueueEntityFixture();
        $transport = new InMemoryTransport();
        $queue = new DbalQueue($transport, new SignedQueuePayload(str_repeat('q', 32)), logger: $logger);

        $queue->dispatch(new GenericMessage('one', ['nested' => ['entity' => $entity]]));
        $queue->dispatch(new GenericMessage('two', ['nested' => ['entity' => $entity]]));

        self::assertSame(2, $transport->size('default'));
        self::assertCount(1, $warnings);
        self::assertSame('queue.dispatch', $warnings[0]['boundary']);
    }
}

final class SerializableQueueEntityFixture implements EntityInterface
{
    public function id(): int|string|null
    {
        return 7;
    }
    public function uuid(): string
    {
        return 'fixture';
    }
    public function label(): string
    {
        return 'fixture';
    }
    public function getEntityTypeId(): string
    {
        return 'user';
    }
    public function bundle(): string
    {
        return 'user';
    }
    public function isNew(): bool
    {
        return false;
    }
    public function get(string $name): mixed
    {
        return null;
    }
    public function set(string $name, mixed $value): static
    {
        return $this;
    }
    public function toArray(): array
    {
        return [];
    }
    public function language(): string
    {
        return 'en';
    }
}
