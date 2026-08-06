<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Testing\Filesystem\TemporaryDirectory;

#[CoversClass(TemporaryDirectory::class)]
final class TemporaryDirectoryTest extends TestCase
{
    #[Test]
    public function it_owns_nested_files_and_removes_its_whole_isolated_tree(): void
    {
        $directory = new TemporaryDirectory('waaseyaa-contract-');
        $root = $directory->path();
        $file = $directory->write('nested/fixture.txt', 'fixture');

        self::assertDirectoryExists($root);
        self::assertSame('fixture', file_get_contents($file));

        $directory->remove();
        self::assertDirectoryDoesNotExist($root);
    }

    #[Test]
    public function it_rejects_paths_that_escape_the_fixture_root(): void
    {
        $directory = new TemporaryDirectory('waaseyaa-contract-');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $directory->path('../escape');
        } finally {
            $directory->remove();
        }
    }
}
