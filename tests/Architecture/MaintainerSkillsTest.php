<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

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
        $scratch = dirname($this->source);
        if (PHP_OS_FAMILY === 'Windows' && is_dir($scratch)) {
            // Git for Windows marks loose objects read-only, which unlink() refuses.
            exec(sprintf('attrib -R %s /S /D', escapeshellarg(str_replace('/', '\\', $scratch) . '\\*')));
        }
        new Filesystem()->remove($scratch);
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

    #[Test]
    public function an_untrustworthy_manifest_is_refused_and_nothing_outside_the_skill_is_touched(): void
    {
        $this->commitSource();
        $this->install();
        $manifestPath = $this->target . '/demo-skill/.waaseyaa-skill.json';
        $valid = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
        $outside = dirname($this->target) . '/outside.txt';
        file_put_contents($outside, "not the installer's\n");
        $digest = str_repeat('a', 64);

        $cases = [
            'wrong skill' => [['skill' => 'other-skill'], 'skill is not "demo-skill"'],
            'wrong source' => [['source' => 'someone/else:.agents/skills/demo-skill'], 'source is not "waaseyaa/framework:.agents/skills/demo-skill"'],
            'parent traversal' => [['files' => $valid['files'] + ['../../outside.txt' => $digest]], 'unsafe path "../../outside.txt"'],
            'absolute path' => [['files' => $valid['files'] + ['/etc/passwd' => $digest]], 'unsafe path "/etc/passwd"'],
            'drive path' => [['files' => $valid['files'] + ['C:/outside.txt' => $digest]], 'unsafe path "C:/outside.txt"'],
            'manifest as owned file' => [['files' => $valid['files'] + ['.waaseyaa-skill.json' => $digest]], 'unsafe path ".waaseyaa-skill.json"'],
            'bad digest' => [['files' => ['SKILL.md' => 'not-a-digest'] + $valid['files']], 'files["SKILL.md"] is not a SHA-256 digest'],
            'bad commit' => [['source_commit' => 'main'], 'source_commit is not a full commit id'],
        ];
        // Make the source drop a file, so a trusted manifest would lead to deletions.
        unlink($this->source . '/.agents/skills/demo-skill/agents/openai.yaml');
        $this->commitSource('drop file');

        foreach ($cases as $label => [$override, $expected]) {
            file_put_contents($manifestPath, json_encode(array_replace($valid, $override), JSON_THROW_ON_ERROR));

            [$verify, $verifyExit] = $this->runCommand('verify', '--root=' . $this->source, '--target=' . $this->target);
            self::assertSame(1, $verifyExit, $label);
            self::assertStringContainsString('invalid-manifest ' . $this->target . '/demo-skill', implode("\n", $verify), $label);
            self::assertStringContainsString($expected, implode("\n", $verify), $label);

            [$output, $exitCode] = $this->install();
            self::assertSame(1, $exitCode, $label);
            self::assertStringContainsString('its manifest cannot be trusted', implode("\n", $output), $label);
            self::assertStringContainsString('No files were written.', implode("\n", $output), $label);
            self::assertFileExists($this->target . '/demo-skill/agents/openai.yaml', $label);
            self::assertSame("not the installer's\n", file_get_contents($outside), $label);
        }

        file_put_contents($manifestPath, 'not json');
        [$verify] = $this->runCommand('verify', '--root=' . $this->source, '--target=' . $this->target);
        self::assertStringContainsString('manifest is not valid JSON', implode("\n", $verify));
    }

    #[Test]
    public function malformed_frontmatter_and_unfinished_markers_are_refused(): void
    {
        $cases = [
            'nested value' => ["---\nname: demo-skill\ndescription: Demo.\nmetadata:\n  owner: someone\n---\n", 'frontmatter line 4 is not a flat "key: value" pair'],
            'unbalanced quote' => ["---\nname: demo-skill\ndescription: \"Demo.\n---\n", 'frontmatter key "description" must have a plain single-line value'],
            'flow sequence' => ["---\nname: demo-skill\ndescription: [a, b]\n---\n", 'frontmatter key "description" must have a plain single-line value'],
            'block scalar' => ["---\nname: demo-skill\ndescription: >\n  Demo.\n---\n", 'frontmatter key "description" must have a plain single-line value'],
            'duplicate key' => ["---\nname: demo-skill\nname: demo-skill\ndescription: Demo.\n---\n", 'frontmatter key "name" appears more than once'],
            'blank line' => ["---\nname: demo-skill\n\ndescription: Demo.\n---\n", 'frontmatter line 2 is not a flat "key: value" pair'],
            'todo marker' => ["---\nname: demo-skill\ndescription: Demo.\n---\n\nTODO: write this section.\n", 'SKILL.md contains an unfinished TODO marker'],
        ];

        foreach ($cases as $label => [$skill, $expected]) {
            $directory = $this->source . '/.agents/skills/demo-skill';
            new Filesystem()->remove($directory);
            $this->writeSource('demo-skill', $skill);

            [$output, $exitCode] = $this->runCommand('validate', '--root=' . $this->source);

            self::assertSame(1, $exitCode, $label);
            self::assertStringContainsString($expected, implode("\n", $output), $label);
        }

        new Filesystem()->remove($this->source . '/.agents/skills/demo-skill');
        $this->writeSource('demo-skill', "---\nname: demo-skill\ndescription: \"Quoted: with a colon.\"\nlicense: MIT\n---\n\n# Demo\n");
        [$output, $exitCode] = $this->runCommand('validate', '--root=' . $this->source);
        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    #[Test]
    public function a_filesystem_failure_rolls_back_the_new_directory_and_never_reports_success(): void
    {
        $this->commitSource();
        $blocked = dirname($this->target) . '/blocked';
        file_put_contents($blocked, "a file where a directory is needed\n");

        [$output, $exitCode] = $this->runCommand('install', '--root=' . $this->source, '--target=' . $this->target, '--target=' . $blocked);
        $report = implode("\n", $output);

        self::assertSame(1, $exitCode, $report);
        self::assertStringContainsString('failed ' . $blocked . '/demo-skill: maintainer-skills: cannot create', $report);
        self::assertStringContainsString('INCOMPLETE: 1 of 2 skill directories completed before the failure.', $report);
        self::assertStringNotContainsString('source ', $report);
        self::assertSame("a file where a directory is needed\n", file_get_contents($blocked));
        [, $verifyExit] = $this->runCommand('verify', '--root=' . $this->source, '--target=' . $this->target);
        self::assertSame(0, $verifyExit);
        self::assertSame([], glob($this->target . '/demo-skill/{,*/}*.tmp-*', GLOB_BRACE) ?: []);
    }

    #[Test]
    public function a_failure_part_way_through_a_first_install_removes_what_it_created(): void
    {
        $this->commitSource();

        [$output, $exitCode] = $this->withFault('write-after:2', fn(): array => $this->install());
        $report = implode("\n", $output);

        self::assertSame(1, $exitCode, $report);
        self::assertStringContainsString('injected fault after 2 written files.', $report);
        self::assertStringContainsString('Rolled back: removed 2 files and 2 directories.', $report);
        self::assertStringContainsString('state now: missing', $report);
        self::assertStringContainsString('INCOMPLETE: 0 of 1 skill directories', $report);
        self::assertDirectoryDoesNotExist($this->target . '/demo-skill');

        [$retry, $retryExit] = $this->install();
        self::assertSame(0, $retryExit, implode("\n", $retry));
        self::assertContains('installed ' . $this->target . '/demo-skill', $retry);
    }

    #[Test]
    public function a_rollback_that_cannot_finish_says_so_and_lists_what_it_left(): void
    {
        $this->commitSource();

        [$output, $exitCode] = $this->withFault('write-after:2,rollback', fn(): array => $this->install());
        $report = implode("\n", $output);

        self::assertSame(1, $exitCode, $report);
        self::assertStringContainsString('ROLLBACK INCOMPLETE, left behind: ' . $this->target . '/demo-skill/agents/openai.yaml', $report);
        self::assertStringNotContainsString('Rolled back', $report);
        self::assertStringContainsString('state now: unmanaged', $report);
        self::assertStringContainsString('not adoptable, move it aside', $report);
        self::assertFileExists($this->target . '/demo-skill/SKILL.md');
        self::assertFileDoesNotExist($this->target . '/demo-skill/.waaseyaa-skill.json');
    }

    #[Test]
    public function a_failed_adoption_is_reported_as_still_unmanaged_and_can_be_retried(): void
    {
        $this->commitSource();
        $this->writeCrlfCopy();

        [$output, $exitCode] = $this->withFault('write-after:1', fn(): array => $this->install('--adopt'));
        $report = implode("\n", $output);

        self::assertSame(1, $exitCode, $report);
        self::assertStringContainsString('No rollback for an existing directory.', $report);
        self::assertStringContainsString('state now: unmanaged; still adoptable with --adopt', $report);
        self::assertStringNotContainsString('adopted ', $report);
        self::assertFileDoesNotExist($this->target . '/demo-skill/.waaseyaa-skill.json');

        [$retry, $retryExit] = $this->install('--adopt');
        self::assertSame(0, $retryExit, implode("\n", $retry));
        self::assertContains('adopted ' . $this->target . '/demo-skill', $retry);
    }

    #[Test]
    public function an_unreadable_source_file_stops_every_command_before_anything_is_written(): void
    {
        $this->commitSource();

        foreach (['validate', 'verify', 'install'] as $command) {
            [$output, $exitCode] = $this->withFault(
                'read:demo-skill/references/checklist.md',
                fn(): array => $this->runCommand($command, '--root=' . $this->source, '--target=' . $this->target),
            );

            self::assertSame(1, $exitCode, $command);
            self::assertStringContainsString('cannot read skill source .agents/skills/demo-skill/references/checklist.md', implode("\n", $output), $command);
            self::assertDirectoryDoesNotExist($this->target . '/demo-skill', $command);
        }
    }

    #[Test]
    public function identical_bytes_from_a_new_commit_refresh_only_the_manifest(): void
    {
        $first = $this->commitSource();
        $this->install();
        $skillPath = $this->target . '/demo-skill/SKILL.md';
        touch($skillPath, 1_000_000_000);
        file_put_contents($this->source . '/unrelated.txt', "squash merge stand-in\n");
        $second = $this->commitSource('unrelated change');
        self::assertNotSame($first, $second);

        [$verify, $verifyExit] = $this->runCommand('verify', '--root=' . $this->source, '--target=' . $this->target);
        self::assertSame(1, $verifyExit);
        self::assertContains(sprintf('provenance-stale %s/demo-skill: installed from %s, source is %s', $this->target, substr($first, 0, 12), substr($second, 0, 12)), $verify);

        [$output, $exitCode] = $this->install();

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertContains('refreshed ' . $this->target . '/demo-skill', $output);
        $manifest = json_decode((string) file_get_contents($this->target . '/demo-skill/.waaseyaa-skill.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($second, $manifest['source_commit']);
        clearstatcache();
        self::assertSame(1_000_000_000, filemtime($skillPath));
        [, $currentExit] = $this->runCommand('verify', '--root=' . $this->source, '--target=' . $this->target);
        self::assertSame(0, $currentExit);
    }

    /**
     * @param callable(): array{0: list<string>, 1: int} $run
     * @return array{0: list<string>, 1: int}
     */
    private function withFault(string $faults, callable $run): array
    {
        putenv('WAASEYAA_MAINTAINER_SKILLS_TEST_FAULT=' . $faults);
        try {
            return $run();
        } finally {
            putenv('WAASEYAA_MAINTAINER_SKILLS_TEST_FAULT');
        }
    }

    private function writeCrlfCopy(): void
    {
        mkdir($this->target . '/demo-skill/references', 0o755, true);
        mkdir($this->target . '/demo-skill/agents', 0o755, true);
        file_put_contents($this->target . '/demo-skill/SKILL.md', str_replace("\n", "\r\n", self::VALID_SKILL));
        file_put_contents($this->target . '/demo-skill/references/checklist.md', "# Checklist\r\n");
        file_put_contents($this->target . '/demo-skill/agents/openai.yaml', "interface:\r\n  display_name: \"Demo\"\r\n");
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
}
