<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Messaging\MessageThread;

#[CoversClass(MessageThread::class)]
final class MessageThreadTest extends TestCase
{
    #[Test]
    public function creates_with_required_fields(): void
    {
        $thread = new MessageThread(['created_by' => 1]);
        $this->assertSame(1, (int) $thread->get('created_by'));
        $this->assertSame('', $thread->get('title'));
        $this->assertSame('direct', $thread->get('thread_type'));
        $this->assertNotNull($thread->get('created_at'));
    }

    #[Test]
    public function requires_created_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('created_by');
        new MessageThread([]);
    }

    #[Test]
    public function rejects_invalid_thread_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('thread_type');
        new MessageThread(['created_by' => 1, 'thread_type' => 'invalid']);
    }

    #[Test]
    public function accepts_group_thread_type(): void
    {
        $thread = new MessageThread(['created_by' => 1, 'thread_type' => 'group']);
        $this->assertSame('group', $thread->get('thread_type'));
    }
}
