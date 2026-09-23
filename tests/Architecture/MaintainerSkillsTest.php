<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/lib/repository-files.php';

/**
 * Behavior of bin/maintainer-skills (#3080): `.agents/skills/` is the only
 * authority, local client copies are generated from it with a provenance
 * manifest, and the installer refuses to overwrite bytes it does not own.
 */
#[CoversNothing]
final class MaintainerSkillsTest extends TestCase
{
    private const string VALID_SKILL = "---\nname: demo-skill\ndescription: Demonstrates installation.\n---\n\n# Demo\n\nRead [the checklist](references/checklist.md).\n";

    private string $root = '';
    private string $source = '';
    private string $target = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $scratch = str_replace('\\', '/', sys_get_temp_dir()) . '/waaseyaa_maintainer_skills_' . uniqid('', true);
        $this->source = $scratch . '/source';
        $this->target = $scratch . '/target';
        mkdir($this->source . '/.agents/skills', 0o755, true);
        mkdir($this->target, 0o755, true);
        $this->git('init', '--quiet');
    }

    protected function tearDown(): void
    {
        $this->remove(dirname($this->source));
    }

    #[Test]
    public function the_repository_skills_are_valid(): void
    {
        [$output, $exitCode] = $this->runCommand('validate', '--root=' . $this->root);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertContains('valid waaseyaa-delivery (2 files)', $output);
        self::assertContains('valid waaseyaa-package-convergence (5 files)', $output);
    }

    #[Test]
    public function first_install_copies_every_supporting_file_and_records_provenance(): void
    {
        $commit = $this->commitSource();

        [$output, $exitCode] = $this->install();

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertContains('installed ' . $this->target . '/demo-skill', $output);
        self::assertSame(self::VALID_SKILL, file_get_contents($this->target . '/demo-skill/SKILL.md'));
        self::assertSame("# Checklist\n", file_get_contents($this->target . '/demo-skill/references/checklist.md'));
        self::assertSame("interface:\n  display_name: \"Demo\"\n", file_get_contents($this->target . '/demo-skill/agents/openai.yaml'));

        $manifest = json_decode((string) file_get_contents($this->target . '/demo-skill/.waaseyaa-skill.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($commit, $manifest['source_commit']);
        self::assertTrue($manifest['source_clean']);
        self::assertSame(hash('sha256', self::VALID_SKILL), $manifest['files']['SKILL.md']);
        self::assertSame(['SKILL.md', 'agents/openai.yaml', 'references/checklist.md'], array_keys($manifest['files']));
    }

    #[Test]
    public function replaying_an_unchanged_source_writes_nothing(): void
    {
        $this->commitSource();
        $this->install();
        $manifestPath = $this->target . '/demo-skill/.waaseyaa-skill.json';
        touch($manifestPath, 1_000_000_000);
        clearstatcache();

        [$output, $exitCode] = $this->install();

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertContains('unchanged ' . $this->target . '/demo-skill', $output);
        clearstatcache();
        self::assertSame(1_000_000_000, filemtime($manifestPath));
        [, $verifyExit] = $this->runCommand('verify', '--root=' . $this->source, '--target=' . $this->target);
        self::assertSame(0, $verifyExit);
    }

    #[Test]
    public function a_reviewed_source_update_replaces_owned_files_and_removes_obsolete_ones(): void
    {
        $this->commitSource();
        $this->install();
        unlink($this->source . '/.agents/skills/demo-skill/agents/openai.yaml');
        file_put_contents($this->source . '/.agents/skills/demo-skill/references/checklist.md', "# Checklist v2\n");
        $commit = $this->commitSource('update');

        [$stale] = $this->runCommand('verify', '--root=' . $this->source, '--target=' . $this->target);
        self::assertContains('stale ' . $this->target . '/demo-skill', $stale);

        [$output, $exitCode] = $this->install();

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertContains('updated ' . $this->target . '/demo-skill', $output);
        self::assertSame("# Checklist v2\n", file_get_contents($this->target . '/demo-skill/references/checklist.md'));
        self::assertFileDoesNotExist($this->target . '/demo-skill/agents/openai.yaml');
        $manifest = json_decode((string) file_get_contents($this->target . '/demo-skill/.waaseyaa-skill.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($commit, $manifest['source_commit']);
    }

    #[Test]
    public function local_drift_is_refused_before_any_target_is_written(): void
    {
        $this->commitSource();
        $second = $this->target . '-second';
        $this->install();
        file_put_contents($this->target . '/demo-skill/SKILL.md', "hand edit\n");

        [$verify, $verifyExit] = $this->runCommand('verify', '--root=' . $this->source, '--target=' . $this->target);
        self::assertSame(1, $verifyExit);
        self::assertContains('drifted ' . $this->target . '/demo-skill: SKILL.md (modified)', $verify);

        [$output, $exitCode] = $this->runCommand('install', '--root=' . $this->source, '--target=' . $second, '--target=' . $this->target);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('local drift from its manifest: SKILL.md (modified)', implode("\n", $output));
        self::assertSame("hand edit\n", file_get_contents($this->target . '/demo-skill/SKILL.md'));
        self::assertDirectoryDoesNotExist($second . '/demo-skill');
    }

    #[Test]
    public function an_unmanaged_directory_is_never_overwritten(): void
    {
        $this->commitSource();
        mkdir($this->target . '/demo-skill', 0o755, true);
        file_put_contents($this->target . '/demo-skill/SKILL.md', "someone else's skill\n");

        foreach ([[], ['--adopt']] as $flags) {
            [$output, $exitCode] = $this->install(...$flags);

            self::assertSame(1, $exitCode);
            self::assertStringContainsString('not managed by this installer', implode("\n", $output));
            self::assertSame("someone else's skill\n", file_get_contents($this->target . '/demo-skill/SKILL.md'));
            self::assertFileDoesNotExist($this->target . '/demo-skill/.waaseyaa-skill.json');
        }
    }

    #[Test]
    public function adopt_takes_ownership_of_an_unmodified_copy_including_crlf_checkouts(): void
    {
        $this->commitSource();
        mkdir($this->target . '/demo-skill/references', 0o755, true);
        mkdir($this->target . '/demo-skill/agents', 0o755, true);
        file_put_contents($this->target . '/demo-skill/SKILL.md', str_replace("\n", "\r\n", self::VALID_SKILL));
        file_put_contents($this->target . '/demo-skill/references/checklist.md', "# Checklist\n");
        file_put_contents($this->target . '/demo-skill/agents/openai.yaml', "interface:\n  display_name: \"Demo\"\n");

        [$output, $exitCode] = $this->install('--adopt');

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertContains('adopted ' . $this->target . '/demo-skill', $output);
        self::assertSame(self::VALID_SKILL, file_get_contents($this->target . '/demo-skill/SKILL.md'));
    }

    #[Test]
    public function uncommitted_source_changes_require_an_explicit_flag(): void
    {
        $this->commitSource();
        file_put_contents($this->source . '/.agents/skills/demo-skill/references/checklist.md', "# Draft\n");

        [$output, $exitCode] = $this->install();
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('uncommitted changes', implode("\n", $output));
        self::assertDirectoryDoesNotExist($this->target . '/demo-skill');

        [, $allowedExit] = $this->install('--allow-dirty-source');
        self::assertSame(0, $allowedExit);
        $manifest = json_decode((string) file_get_contents($this->target . '/demo-skill/.waaseyaa-skill.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertFalse($manifest['source_clean']);
    }

    #[Test]
    public function invalid_skills_are_refused_with_every_problem_named(): void
    {
        $this->writeSource('Bad_Skill', "---\nname: other\ndescription: <b>bold</b>\nversion: 2\n---\n\nSee [missing](references/missing.md).\r\n");
        $this->commitSource();

        [$output, $exitCode] = $this->runCommand('validate', '--root=' . $this->source);
        $report = implode("\n", $output);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('directory name must be hyphen-case', $report);
        self::assertStringContainsString('unexpected frontmatter key "version"', $report);
        self::assertStringContainsString('frontmatter name must equal the directory name', $report);
        self::assertStringContainsString('without angle brackets', $report);
        self::assertStringContainsString('links to references/missing.md', $report);
        self::assertStringContainsString('contains CR line endings', $report);

        [, $installExit] = $this->install();
        self::assertSame(1, $installExit);
    }

    private function writeSource(string $name, string $skill): void
    {
        $directory = $this->source . '/.agents/skills/' . $name;
        mkdir($directory, 0o755, true);
        file_put_contents($directory . '/SKILL.md', $skill);
    }

    private function commitSource(string $message = 'initial'): string
    {
        $directory = $this->source . '/.agents/skills/demo-skill';
        if (!is_dir($directory)) {
            $this->writeSource('demo-skill', self::VALID_SKILL);
            mkdir($directory . '/references');
            mkdir($directory . '/agents');
            file_put_contents($directory . '/references/checklist.md', "# Checklist\n");
            file_put_contents($directory . '/agents/openai.yaml', "interface:\n  display_name: \"Demo\"\n");
        }
        $this->git('add', '--all');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '--quiet', '-m', $message);

        return trim($this->git('rev-parse', 'HEAD'));
    }

    /** @return array{0: list<string>, 1: int} */
    private function install(string ...$flags): array
    {
        return $this->runCommand('install', '--root=' . $this->source, '--target=' . $this->target, ...$flags);
    }

    /** @return array{0: list<string>, 1: int} */
    private function runCommand(string ...$arguments): array
    {
        exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/bin/maintainer-skills'),
            implode(' ', array_map('escapeshellarg', $arguments)),
        ), $output, $exitCode);

        return [$output, $exitCode];
    }

    private function git(string ...$arguments): string
    {
        [$exitCode, $stdout, $stderr] = \repositoryGit($this->source, ['-c', 'core.autocrlf=false', ...$arguments]);
        self::assertSame(0, $exitCode, $stderr);

        return $stdout;
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                @chmod($entry->getPathname(), 0o666);
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
