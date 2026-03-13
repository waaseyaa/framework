<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Validator\StructuralChecker;

#[CoversClass(StructuralChecker::class)]
final class StructuralCheckerTest extends TestCase
{
    private StructuralChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new StructuralChecker();
    }

    #[Test]
    public function all_valid_references_return_max_score(): void
    {
        $result = $this->checker->check(
            references: ['src/Foo.php', 'src/Bar.php'],
            existingFiles: ['src/Foo.php', 'src/Bar.php'],
        );

        $this->assertSame(20.0, $result['score']);
        $this->assertEmpty($result['issues']);
    }

    #[Test]
    public function no_references_return_max_score(): void
    {
        $result = $this->checker->check(
            references: [],
            existingFiles: ['src/Foo.php'],
        );

        $this->assertSame(20.0, $result['score']);
        $this->assertEmpty($result['issues']);
    }

    #[Test]
    public function missing_reference_is_penalized(): void
    {
        $result = $this->checker->check(
            references: ['src/Missing.php'],
            existingFiles: [],
        );

        $this->assertSame(16.0, $result['score']); // 20 - 4
        $this->assertCount(1, $result['issues']);
        $this->assertStringContainsString('Missing referenced file', $result['issues'][0]);
    }

    #[Test]
    public function multiple_missing_references_accumulate_penalty(): void
    {
        $result = $this->checker->check(
            references: ['src/A.php', 'src/B.php', 'src/C.php'],
            existingFiles: [],
        );

        $this->assertSame(8.0, $result['score']); // 20 - (4 * 3) = 8
        $this->assertCount(3, $result['issues']);
    }

    #[Test]
    public function score_does_not_go_below_zero(): void
    {
        $result = $this->checker->check(
            references: ['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php'],
            existingFiles: [],
        );

        $this->assertSame(0.0, $result['score']); // 20 - 24 = 0 (clamped)
    }

    #[Test]
    public function layer_violations_are_penalized(): void
    {
        $result = $this->checker->check(
            references: [],
            existingFiles: [],
            layerViolations: ['Foundation imports API layer'],
        );

        $this->assertSame(15.0, $result['score']); // 20 - 5
        $this->assertCount(1, $result['issues']);
        $this->assertStringContainsString('Layer violation', $result['issues'][0]);
    }

    #[Test]
    public function combined_missing_files_and_layer_violations(): void
    {
        $result = $this->checker->check(
            references: ['src/Missing.php'],
            existingFiles: [],
            layerViolations: ['Foundation imports API'],
        );

        $this->assertSame(11.0, $result['score']); // 20 - 4 - 5
        $this->assertCount(2, $result['issues']);
    }
}
