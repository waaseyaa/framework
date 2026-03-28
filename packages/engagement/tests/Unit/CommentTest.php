<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Engagement\Comment;

#[CoversClass(Comment::class)]
final class CommentTest extends TestCase
{
    #[Test]
    public function creates_with_required_fields(): void
    {
        $comment = new Comment([
            'user_id' => 1,
            'target_type' => 'post',
            'target_id' => 42,
            'body' => 'Great post!',
        ]);

        $this->assertSame('Great post!', $comment->get('body'));
        $this->assertSame(1, (int) $comment->get('status'));
        $this->assertNotNull($comment->get('created_at'));
    }

    #[Test]
    public function requires_body(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('body');
        new Comment(['user_id' => 1, 'target_type' => 'post', 'target_id' => 1]);
    }

    #[Test]
    public function requires_user_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('user_id');
        new Comment(['target_type' => 'post', 'target_id' => 1, 'body' => 'test']);
    }
}
