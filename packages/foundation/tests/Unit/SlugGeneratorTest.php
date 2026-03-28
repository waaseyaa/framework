<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\SlugGenerator;

#[CoversClass(SlugGenerator::class)]
final class SlugGeneratorTest extends TestCase
{
    #[Test]
    public function generates_slug_from_simple_string(): void
    {
        $this->assertSame('hello-world', SlugGenerator::generate('Hello World'));
    }

    #[Test]
    public function strips_special_characters(): void
    {
        $this->assertSame('test-123', SlugGenerator::generate('Test @#$ 123'));
    }

    #[Test]
    public function trims_leading_and_trailing_hyphens(): void
    {
        $this->assertSame('hello', SlugGenerator::generate('---hello---'));
    }

    #[Test]
    public function collapses_multiple_hyphens(): void
    {
        $this->assertSame('a-b', SlugGenerator::generate('a   b'));
    }

    #[Test]
    public function handles_empty_string(): void
    {
        $this->assertSame('', SlugGenerator::generate(''));
    }
}
