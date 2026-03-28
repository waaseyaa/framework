<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Engagement\Reaction;

#[CoversClass(Reaction::class)]
final class ReactionTest extends TestCase
{
    #[Test]
    public function creates_with_required_fields(): void
    {
        $reaction = new Reaction([
            'user_id' => 1,
            'target_type' => 'post',
            'target_id' => 42,
            'reaction_type' => 'like',
        ]);

        $this->assertSame(1, (int) $reaction->get('user_id'));
        $this->assertSame('like', $reaction->get('reaction_type'));
        $this->assertNotNull($reaction->get('created_at'));
    }

    #[Test]
    public function requires_user_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('user_id');
        new Reaction(['target_type' => 'post', 'target_id' => 1, 'reaction_type' => 'like']);
    }

    #[Test]
    public function requires_reaction_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reaction_type');
        new Reaction(['user_id' => 1, 'target_type' => 'post', 'target_id' => 1]);
    }

    #[Test]
    public function accepts_any_type_when_no_allowed_list(): void
    {
        $reaction = new Reaction([
            'user_id' => 1,
            'target_type' => 'post',
            'target_id' => 1,
            'reaction_type' => 'miigwech',
        ]);

        $this->assertSame('miigwech', $reaction->get('reaction_type'));
    }

    #[Test]
    public function accepts_custom_allowed_types(): void
    {
        $reaction = new Reaction(
            values: [
                'user_id' => 1,
                'target_type' => 'post',
                'target_id' => 1,
                'reaction_type' => 'miigwech',
            ],
            allowedReactionTypes: ['like', 'miigwech'],
        );

        $this->assertSame('miigwech', $reaction->get('reaction_type'));
    }

    #[Test]
    public function rejects_type_not_in_custom_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Reaction(
            values: [
                'user_id' => 1,
                'target_type' => 'post',
                'target_id' => 1,
                'reaction_type' => 'love',
            ],
            allowedReactionTypes: ['like', 'miigwech'],
        );
    }
}
