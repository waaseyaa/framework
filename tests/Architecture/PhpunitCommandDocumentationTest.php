<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpunitCommandDocumentationTest extends TestCase
{
    #[Test]
    public function active_split_suite_commands_disable_configured_coverage(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['AGENTS.md', 'CLAUDE.md', 'docs/REPO_ADMIN_SETUP.md', 'docs/ci/README.md'] as $path) {
            $contents = file_get_contents($root . '/' . $path);
            self::assertIsString($contents);
            self::assertDoesNotMatchRegularExpression(
                '/phpunit --testsuite (?:Unit|Integration|Architecture)(?![^\n]*--no-coverage)/',
                $contents,
                "$path documents a split-suite command that can execute zero tests when no coverage driver is installed.",
            );
        }
    }
}
