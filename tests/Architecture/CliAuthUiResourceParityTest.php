<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Scaffold\AuthUiScaffoldManager;

/**
 * packages/cli/resources/auth-ui/ is a generated mirror of the
 * AuthUiScaffoldManager::FILE_MAP sources under packages/admin/app/ (#2833
 * repair design, decision 2). packages/admin/app remains the only authored
 * source; the mirror must be byte-identical and contain nothing else, or the
 * package-owned candidate ((c)/(d) in AuthUiScaffoldManager::sourceCandidates())
 * silently serves stale or extraneous content to consumers who never see
 * packages/admin/app at all.
 */
#[CoversNothing]
final class CliAuthUiResourceParityTest extends TestCase
{
    private const string REGENERATE_COMMAND = 'bin/sync-cli-auth-ui-resources';

    #[Test]
    public function every_file_map_source_is_mirrored_byte_for_byte(): void
    {
        $root = dirname(__DIR__, 2);
        $authoredBase = $root . '/packages/admin/app';
        $mirrorBase = $root . '/packages/cli/resources/auth-ui';

        foreach (AuthUiScaffoldManager::FILE_MAP as $relativePath => $destination) {
            $authoredPath = $authoredBase . '/' . $relativePath;
            $mirrorPath = $mirrorBase . '/' . $relativePath;

            self::assertFileExists(
                $authoredPath,
                sprintf('Authored auth UI source is missing: %s', $authoredPath),
            );
            self::assertFileExists(
                $mirrorPath,
                sprintf(
                    'packages/cli/resources/auth-ui is missing %s. Run %s and commit the result.',
                    $relativePath,
                    self::REGENERATE_COMMAND,
                ),
            );
            self::assertSame(
                hash_file('sha256', $authoredPath),
                hash_file('sha256', $mirrorPath),
                sprintf(
                    'packages/cli/resources/auth-ui/%s has drifted from packages/admin/app/%1$s. Run %s and commit the result.',
                    $relativePath,
                    self::REGENERATE_COMMAND,
                ),
            );
        }
    }

    #[Test]
    public function the_mirror_directory_contains_nothing_else(): void
    {
        $root = dirname(__DIR__, 2);
        $mirrorBase = $root . '/packages/cli/resources/auth-ui';

        self::assertDirectoryExists($mirrorBase);

        $expected = [];
        foreach (array_keys(AuthUiScaffoldManager::FILE_MAP) as $relativePath) {
            $expected[] = $relativePath;
        }
        sort($expected, SORT_STRING);

        $actual = $this->listFilesRelativeTo($mirrorBase);

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                'packages/cli/resources/auth-ui must contain exactly the FILE_MAP sources and nothing else. Run %s and commit the result.',
                self::REGENERATE_COMMAND,
            ),
        );
    }

    /** @return list<string> */
    private function listFilesRelativeTo(string $directory): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $fileInfo) {
            assert($fileInfo instanceof \SplFileInfo);
            if (!$fileInfo->isFile()) {
                continue;
            }
            $relative = substr($fileInfo->getPathname(), strlen($directory) + 1);
            $found[] = str_replace('\\', '/', $relative);
        }
        sort($found, SORT_STRING);

        return $found;
    }
}
