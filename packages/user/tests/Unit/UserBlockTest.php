<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\User\UserBlock;

#[CoversClass(UserBlock::class)]
final class UserBlockTest extends TestCase
{
    #[Test]
    public function creates_with_required_fields(): void
    {
        $block = new UserBlock(['blocker_id' => 1, 'blocked_id' => 2]);
        $this->assertSame(1, (int) $block->get('blocker_id'));
        $this->assertSame(2, (int) $block->get('blocked_id'));
        $this->assertNotNull($block->get('created_at'));
    }

    #[Test]
    public function uses_provided_created_at(): void
    {
        $block = new UserBlock(['blocker_id' => 1, 'blocked_id' => 2, 'created_at' => 1000]);
        $this->assertSame(1000, (int) $block->get('created_at'));
    }

    #[Test]
    public function requires_blocker_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('blocker_id');
        new UserBlock(['blocked_id' => 2]);
    }

    #[Test]
    public function requires_blocked_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('blocked_id');
        new UserBlock(['blocker_id' => 1]);
    }

    #[Test]
    public function rejects_self_block(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot block yourself');
        new UserBlock(['blocker_id' => 1, 'blocked_id' => 1]);
    }
}
