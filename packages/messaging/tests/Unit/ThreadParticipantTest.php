<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Messaging\ThreadParticipant;

#[CoversClass(ThreadParticipant::class)]
final class ThreadParticipantTest extends TestCase
{
    #[Test]
    public function creates_with_required_fields(): void
    {
        $p = new ThreadParticipant(['thread_id' => 1, 'user_id' => 2, 'thread_creator_id' => 1]);
        $this->assertSame('member', $p->get('role'));
        $this->assertNotNull($p->get('joined_at'));
        $this->assertSame(0, (int) $p->get('last_read_at'));
    }

    #[Test]
    public function accepts_owner_role(): void
    {
        $p = new ThreadParticipant(['thread_id' => 1, 'user_id' => 2, 'thread_creator_id' => 1, 'role' => 'owner']);
        $this->assertSame('owner', $p->get('role'));
    }

    #[Test]
    public function rejects_invalid_role(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid role');
        new ThreadParticipant(['thread_id' => 1, 'user_id' => 2, 'thread_creator_id' => 1, 'role' => 'admin']);
    }

    #[Test]
    public function requires_thread_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('thread_id');
        new ThreadParticipant(['user_id' => 2, 'thread_creator_id' => 1]);
    }
}
