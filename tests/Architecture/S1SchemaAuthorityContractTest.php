<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class S1SchemaAuthorityContractTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_executable_schema_boundary_has_one_reviewed_authority(): void
    {
        self::assertFileExists($this->root . '/support/s1-schema-authority-roster.json');
        self::assertFileExists($this->root . '/bin/check-s1-schema-authority');
        self::assertTrue(is_executable($this->root . '/bin/check-s1-schema-authority'));

        exec(
            escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($this->root . '/bin/check-s1-schema-authority') . ' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
    }
}
