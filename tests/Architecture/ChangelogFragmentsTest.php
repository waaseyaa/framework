<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Tooling\ChangelogFragments;

require_once __DIR__ . '/../../tools/lib/ChangelogFragments.php';

#[CoversNothing]
final class ChangelogFragmentsTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporaryDirectories);
    }

    #[Test]
    public function rendering_is_byte_stable_and_uses_owned_taxonomy_order(): void
    {
        $files = [
            '2563.zeta.fixed.md' => "- Zeta fix.\n",
            '9.security-boundary.security.md' => "- Security outcome.\n\n  Complete second paragraph.\n",
            '10.alpha.added.md' => "- Added outcome with `RELEASE_TAG_MSG_EOF`.\n",
            '2563.alpha.fixed.md' => "- Alpha fix.\n",
            '11.behaviour.changed.md' => "- Changed outcome.\n",
            '12.old-api.deprecated.md' => "- Deprecated outcome.\n",
            '13.removed-api.removed.md' => "- Removed outcome.\n",
        ];
        $first = $this->fragmentDirectory($files);
        $second = $this->fragmentDirectory(array_reverse($files, true));

        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        $renderedFirst = ChangelogFragments::render(ChangelogFragments::load($first));
        date_default_timezone_set('America/Adak');
        $renderedSecond = ChangelogFragments::render(ChangelogFragments::load($second));
        date_default_timezone_set($originalTimezone);

        self::assertSame($renderedFirst, $renderedSecond);
        self::assertSame(hash('sha256', $renderedFirst), hash('sha256', $renderedSecond));
        $positions = array_map(
            static fn(string $heading): int|false => strpos($renderedFirst, "### {$heading}"),
            ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'],
        );
        self::assertNotContains(false, $positions);
        for ($index = 1; $index < count($positions); ++$index) {
            self::assertGreaterThan($positions[$index - 1], $positions[$index]);
        }
        self::assertLessThan(strpos($renderedFirst, '- Zeta fix.'), strpos($renderedFirst, '- Alpha fix.'));
        self::assertStringContainsString("- Security outcome.\n\n  Complete second paragraph.\n", $renderedFirst);
    }

    #[Test]
    public function malformed_encoding_names_content_and_duplicate_identities_fail_closed(): void
    {
        $invalidCases = [
            ['0.zero.fixed.md', "- invalid issue.\n", 'invalid fragment filename'],
            ['1.unknown.future.md', "- invalid type.\n", 'invalid fragment filename'],
            ['1.empty.fixed.md', '', 'is empty'],
            ['1.empty-bullet.fixed.md', "- \n", 'consumer-facing text'],
            ['1.crlf.fixed.md', "- CRLF.\r\n", 'LF line endings'],
            ['1.no-terminal.fixed.md', '- Missing newline.', 'LF newline'],
            ['1.bom.fixed.md', "\xEF\xBB\xBF- BOM.\n", 'UTF-8 BOM'],
            ['1.heading.fixed.md', "- Entry.\n\n  ### Owned heading\n", 'must not contain headings'],
            ['1.open-fence.fixed.md', "- Entry.\n\n  ```php\n  # comment\n", 'unclosed Markdown code fence'],
            ['1.second.fixed.md', "- First.\n- Second.\n", 'second top-level'],
            ['1.invalid-utf8.fixed.md', "- \xC3\x28\n", 'valid UTF-8'],
        ];

        foreach ($invalidCases as [$filename, $content, $diagnostic]) {
            $directory = $this->fragmentDirectory([$filename => $content]);
            try {
                ChangelogFragments::load($directory);
                self::fail("{$filename} should have been refused.");
            } catch (RuntimeException $exception) {
                self::assertStringContainsString($diagnostic, $exception->getMessage());
            }
        }

        $duplicates = $this->fragmentDirectory([
            '42.same-slice.fixed.md' => "- First.\n",
            '42.same-slice.security.md' => "- Second.\n",
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate fragment identity');
        ChangelogFragments::load($duplicates);
    }

    #[Test]
    public function fenced_code_comments_are_preserved_without_becoming_taxonomy_headings(): void
    {
        $content = "- Operator example.\n\n  ````markdown\n  ```\n  ````~~~\n  # This is code inside a four-backtick fence, not a heading.\n  ````\n";
        $directory = $this->fragmentDirectory(['2563.fenced-example.changed.md' => $content]);

        self::assertSame(
            "### Changed\n\n{$content}",
            ChangelogFragments::render(ChangelogFragments::load($directory)),
        );
    }

    #[Test]
    public function assembly_preserves_every_historical_byte_and_refuses_stale_or_empty_input(): void
    {
        $history = "## [0.1.0-alpha.298] - 2026-08-25\n\n- Historical bytes.\n\n";
        $changelog = "# Changelog\n\n## [Unreleased]\n\n{$history}";
        $rendered = "### Fixed\n\n- New release outcome.\n";

        $assembled = ChangelogFragments::assemble($changelog, '0.1.0-alpha.299', '2026-08-26', $rendered);

        self::assertStringEndsWith($history, $assembled);
        self::assertSame(hash('sha256', $history), hash('sha256', substr($assembled, -strlen($history))));
        self::assertStringContainsString("## [Unreleased]\n\n## [0.1.0-alpha.299] - 2026-08-26\n\n{$rendered}", $assembled);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale prose');
        ChangelogFragments::assemble(
            "# Changelog\n\n## [Unreleased]\n\n- Stale.\n\n{$history}",
            '0.1.0-alpha.299',
            '2026-08-26',
            $rendered,
        );
    }

    #[Test]
    public function assembly_refuses_empty_output_and_impossible_release_dates(): void
    {
        $changelog = "# Changelog\n\n## [Unreleased]\n\n## [0.1.0] - 2026-01-01\n";
        try {
            ChangelogFragments::assemble($changelog, '0.1.1', '2026-02-29', "### Fixed\n\n- Fix.\n");
            self::fail('An impossible date must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('real Gregorian date', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing an empty release');
        ChangelogFragments::assemble($changelog, '0.1.1', '2026-02-28', '');
    }

    #[Test]
    public function release_archives_each_fragment_once_and_a_second_release_is_a_noop_refusal(): void
    {
        $root = $this->newDirectory();
        mkdir($root . '/unreleased', 0o777, true);
        file_put_contents($root . '/unreleased/.gitkeep', '');
        file_put_contents($root . '/unreleased/2563.compiler.fixed.md', "- Compiler fix.\n");
        $history = "## [0.1.0-alpha.298] - 2026-08-25\n\n- History.\n";
        file_put_contents($root . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n{$history}");

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/changelog-fragments')
            . ' release --version=0.1.0-alpha.299 --date=2026-08-26'
            . ' --dir=' . escapeshellarg($root . '/unreleased')
            . ' --archive-root=' . escapeshellarg($root . '/released')
            . ' --changelog=' . escapeshellarg($root . '/CHANGELOG.md')
            . ' --tag-message=' . escapeshellarg($root . '/tag.md');
        exec($command . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertFileExists($root . '/released/0.1.0-alpha.299/2563.compiler.fixed.md');
        self::assertSame(
            "- Compiler fix.\n",
            file_get_contents($root . '/released/0.1.0-alpha.299/2563.compiler.fixed.md'),
        );
        self::assertFileDoesNotExist($root . '/unreleased/2563.compiler.fixed.md');
        self::assertSame("### Fixed\n\n- Compiler fix.\n", file_get_contents($root . '/tag.md'));

        $before = hash_file('sha256', $root . '/CHANGELOG.md');
        exec($command . ' 2>&1', $secondOutput, $secondExit);
        self::assertSame(1, $secondExit);
        self::assertStringContainsString('no pending changelog fragments', implode("\n", $secondOutput));
        self::assertSame($before, hash_file('sha256', $root . '/CHANGELOG.md'));
    }

    #[Test]
    public function a_precommit_output_failure_rolls_back_every_fragment_and_changelog_byte(): void
    {
        $root = $this->newDirectory();
        mkdir($root . '/unreleased', 0o777, true);
        mkdir($root . '/not-a-file', 0o777, true);
        file_put_contents($root . '/unreleased/.gitkeep', '');
        file_put_contents($root . '/unreleased/2563.rollback.fixed.md', "- Rollback proof.\n");
        $original = "# Changelog\n\n## [Unreleased]\n\n## [0.1.0-alpha.298] - 2026-08-25\n\n- History.\n";
        file_put_contents($root . '/CHANGELOG.md', $original);

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/changelog-fragments')
            . ' release --version=0.1.0-alpha.299 --date=2026-08-26'
            . ' --dir=' . escapeshellarg($root . '/unreleased')
            . ' --archive-root=' . escapeshellarg($root . '/released')
            . ' --changelog=' . escapeshellarg($root . '/CHANGELOG.md')
            . ' --tag-message=' . escapeshellarg($root . '/not-a-file');
        exec($command . ' 2>&1', $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertSame($original, file_get_contents($root . '/CHANGELOG.md'));
        self::assertFileExists($root . '/unreleased/2563.rollback.fixed.md');
        self::assertDirectoryDoesNotExist($root . '/released/0.1.0-alpha.299');
    }

    /** @param array<string, string> $files */
    private function fragmentDirectory(array $files): string
    {
        $directory = $this->newDirectory();
        file_put_contents($directory . '/.gitkeep', '');
        foreach ($files as $filename => $contents) {
            file_put_contents($directory . '/' . $filename, $contents);
        }

        return $directory;
    }

    private function newDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/waaseyaa-fragments-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

}
