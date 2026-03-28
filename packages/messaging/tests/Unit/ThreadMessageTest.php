<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Messaging\ThreadMessage;

#[CoversClass(ThreadMessage::class)]
final class ThreadMessageTest extends TestCase
{
    #[Test]
    public function creates_with_required_fields(): void
    {
        $msg = new ThreadMessage(['thread_id' => 1, 'sender_id' => 2, 'body' => 'Hello']);
        $this->assertSame('Hello', $msg->get('body'));
        $this->assertSame(1, (int) $msg->get('status'));
        $this->assertNull($msg->get('edited_at'));
    }

    #[Test]
    public function trims_body(): void
    {
        $msg = new ThreadMessage(['thread_id' => 1, 'sender_id' => 2, 'body' => '  Hello  ']);
        $this->assertSame('Hello', $msg->get('body'));
    }

    #[Test]
    public function rejects_empty_body(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('1-2000');
        new ThreadMessage(['thread_id' => 1, 'sender_id' => 2, 'body' => '   ']);
    }

    #[Test]
    public function rejects_body_over_2000_chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThreadMessage(['thread_id' => 1, 'sender_id' => 2, 'body' => str_repeat('a', 2001)]);
    }

    #[Test]
    public function requires_thread_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('thread_id');
        new ThreadMessage(['sender_id' => 1, 'body' => 'test']);
    }
}
