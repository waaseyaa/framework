<?php

declare(strict_types=1);

namespace Aurora\Queue\Tests\Unit;

use Aurora\Queue\FailedJobRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailedJobRepository::class)]
final class FailedJobRepositoryTest extends TestCase
{
    #[Test]
    public function allReturnsEmptyArrayInitially(): void
    {
        $repo = new FailedJobRepository();

        $this->assertSame([], $repo->all());
    }

    #[Test]
    public function recordStoresAFailedJob(): void
    {
        $repo = new FailedJobRepository();

        $id = $repo->record(
            queue: 'default',
            payload: '{"class":"SendEmail"}',
            e: new \RuntimeException('Connection refused'),
        );

        $this->assertSame('1', $id);

        $records = $repo->all();
        $this->assertCount(1, $records);
        $this->assertArrayHasKey('1', $records);

        $record = $records['1'];
        $this->assertSame('1', $record['id']);
        $this->assertSame('default', $record['queue']);
        $this->assertSame('{"class":"SendEmail"}', $record['payload']);
        $this->assertSame('RuntimeException: Connection refused', $record['exception']);
        $this->assertArrayHasKey('failed_at', $record);
    }

    #[Test]
    public function recordReturnsIncrementingIds(): void
    {
        $repo = new FailedJobRepository();
        $exception = new \RuntimeException('fail');

        $id1 = $repo->record('q', 'p1', $exception);
        $id2 = $repo->record('q', 'p2', $exception);
        $id3 = $repo->record('q', 'p3', $exception);

        $this->assertSame('1', $id1);
        $this->assertSame('2', $id2);
        $this->assertSame('3', $id3);
    }

    #[Test]
    public function findReturnsRecordById(): void
    {
        $repo = new FailedJobRepository();
        $id = $repo->record('emails', '{"to":"a@b.com"}', new \LogicException('Bad state'));

        $record = $repo->find($id);

        $this->assertNotNull($record);
        $this->assertSame($id, $record['id']);
        $this->assertSame('emails', $record['queue']);
        $this->assertSame('{"to":"a@b.com"}', $record['payload']);
        $this->assertSame('LogicException: Bad state', $record['exception']);
    }

    #[Test]
    public function findReturnsNullForNonexistentId(): void
    {
        $repo = new FailedJobRepository();

        $this->assertNull($repo->find('999'));
    }

    #[Test]
    public function forgetRemovesASingleRecord(): void
    {
        $repo = new FailedJobRepository();
        $exception = new \RuntimeException('fail');

        $id1 = $repo->record('q', 'p1', $exception);
        $id2 = $repo->record('q', 'p2', $exception);

        $repo->forget($id1);

        $this->assertNull($repo->find($id1));
        $this->assertNotNull($repo->find($id2));
        $this->assertCount(1, $repo->all());
    }

    #[Test]
    public function forgetOnNonexistentIdDoesNothing(): void
    {
        $repo = new FailedJobRepository();
        $repo->record('q', 'p', new \RuntimeException('fail'));

        $repo->forget('999');

        $this->assertCount(1, $repo->all());
    }

    #[Test]
    public function flushRemovesAllRecords(): void
    {
        $repo = new FailedJobRepository();
        $exception = new \RuntimeException('fail');

        $repo->record('q1', 'p1', $exception);
        $repo->record('q2', 'p2', $exception);
        $repo->record('q3', 'p3', $exception);

        $repo->flush();

        $this->assertSame([], $repo->all());
    }

    #[Test]
    public function flushOnEmptyRepositoryDoesNothing(): void
    {
        $repo = new FailedJobRepository();

        $repo->flush();

        $this->assertSame([], $repo->all());
    }

    #[Test]
    public function flushResetsIdSequence(): void
    {
        $repo = new FailedJobRepository();
        $exception = new \RuntimeException('fail');

        $repo->record('q', 'p', $exception);
        $repo->record('q', 'p', $exception);

        $repo->flush();

        $newId = $repo->record('q', 'p', $exception);

        $this->assertSame('1', $newId);
    }

    #[Test]
    public function recordPreservesExceptionClassName(): void
    {
        $repo = new FailedJobRepository();

        $repo->record('q', 'p', new \InvalidArgumentException('bad arg'));

        $record = $repo->find('1');
        $this->assertNotNull($record);
        $this->assertSame('InvalidArgumentException: bad arg', $record['exception']);
    }

    #[Test]
    public function failedAtContainsValidDateString(): void
    {
        $repo = new FailedJobRepository();
        $repo->record('q', 'p', new \RuntimeException('fail'));

        $record = $repo->find('1');
        $this->assertNotNull($record);

        // Verify it's a valid ISO 8601 date.
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $record['failed_at']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
    }
}
