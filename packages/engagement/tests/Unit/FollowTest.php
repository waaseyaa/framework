<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Engagement\Follow;

#[CoversClass(Follow::class)]
final class FollowTest extends TestCase
{
    #[Test]
    public function creates_with_required_fields(): void
    {
        $follow = new Follow([
            'user_id' => 1,
            'target_type' => 'community',
            'target_id' => 42,
        ]);

        $this->assertSame(1, (int) $follow->get('user_id'));
        $this->assertSame('community', $follow->get('target_type'));
        $this->assertNotNull($follow->get('created_at'));
    }

    #[Test]
    public function requires_user_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('user_id');
        new Follow(['target_type' => 'post', 'target_id' => 1]);
    }

    #[Test]
    public function requires_target_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('target_type');
        new Follow(['user_id' => 1, 'target_id' => 1]);
    }
}
