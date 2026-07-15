<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\DbalQueue;
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Transport\InMemoryTransport;
use Waaseyaa\Queue\Worker\Worker;
use Waaseyaa\Queue\Worker\WorkerOptions;

final class PersistentPayloadIntegrityTest extends TestCase
{
    #[Test]
    public function persistent_payloads_are_signed_and_verified_before_deserialization(): void
    {
        $key = random_bytes(32);
        $signer = new SignedQueuePayload($key);
        $transport = new InMemoryTransport();
        $queue = new DbalQueue($transport, $signer, 'default');
        $queue->dispatch(new GenericMessage('example', ['value' => 'verified']));

        $raw = $transport->pop('default');
        self::assertNotNull($raw);
        self::assertStringStartsWith('hmac-sha256.hkdf-v1:', $raw['payload']);
        $transport->release($raw['id']);

        $handled = false;
        $handler = new class($handled) implements \Waaseyaa\Queue\Handler\HandlerInterface {
            public function __construct(private bool &$handled) {}
            public function supports(object $message): bool { return true; }
            public function handle(object $message): void { $this->handled = true; }
        };
        $worker = new Worker($transport, new InMemoryFailedJobRepository(), [$handler], payloadSigner: $signer);
        $worker->runNextJob('default', new WorkerOptions(maxJobs: 1, sleep: 0));

        self::assertTrue($handled);
    }

    #[Test]
    public function changed_persistent_payloads_are_rejected_before_deserialization(): void
    {
        $signer = new SignedQueuePayload(random_bytes(32));
        $transport = new InMemoryTransport();
        $failed = new InMemoryFailedJobRepository();
        $transport->push('default', $signer->seal(serialize(new GenericMessage('example'))) . 'changed');

        $worker = new Worker($transport, $failed, [], payloadSigner: $signer);
        $worker->runNextJob('default', new WorkerOptions(maxJobs: 1, sleep: 0));

        self::assertCount(1, $failed->all());
    }
}
