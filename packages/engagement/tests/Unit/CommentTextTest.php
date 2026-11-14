<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Engagement\CommentText;
use Waaseyaa\Engagement\EngagementException;

#[CoversClass(CommentText::class)]
final class CommentTextTest extends TestCase
{
    #[Test]
    public function normalizes_newlines_trims_unicode_space_and_preserves_unicode_text(): void
    {
        self::assertSame("Café 😀\nsecond line", CommentText::normalize("\u{00A0}Café 😀\r\nsecond line\u{2003}"));
    }

    #[Test]
    public function enforces_nonblank_and_unicode_character_boundaries(): void
    {
        self::assertSame('é😀', CommentText::normalize('é😀', 2));
        $this->assertInvalid('é😀x', 2);
        foreach (['', " \t\r\n", "\u{00A0}\u{2003}"] as $blank) {
            $this->assertInvalid($blank);
        }
    }

    #[Test]
    public function refuses_invalid_utf8_and_ascii_control_characters(): void
    {
        foreach (["bad\x00text", "bad\x07text", "bad\x7Ftext", "\xC3\x28"] as $invalid) {
            $this->assertInvalid($invalid);
        }
    }

    private function assertInvalid(string $text, int $maximum = 2000): void
    {
        try {
            CommentText::normalize($text, $maximum);
            self::fail('Invalid comment text must be refused.');
        } catch (EngagementException $failure) {
            self::assertSame('VALIDATION_FAILED', $failure->errorCode);
            self::assertSame(422, $failure->httpStatus);
        }
    }
}
