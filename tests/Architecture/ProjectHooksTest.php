<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ProjectHooksTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function claude_startup_loads_only_concise_repository_context(): void
    {
        $settings = json_decode((string) file_get_contents($this->root . '/.claude/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('PreToolUse', $settings['hooks']);
        self::assertCount(1, $settings['hooks']['SessionStart']);

        $entry = $settings['hooks']['SessionStart'][0];
        self::assertSame('startup', $entry['matcher']);
        self::assertSame('${CLAUDE_PROJECT_DIR}/bin/project-hooks', $entry['hooks'][0]['command']);
        self::assertSame(['claude-session'], $entry['hooks'][0]['args']);
        self::assertArrayNotHasKey('show_output', $entry['hooks'][0]);
    }

    #[Test]
    public function project_hook_source_has_proportionate_and_explicit_gates(): void
    {
        $script = (string) file_get_contents($this->root . '/bin/project-hooks');
        self::assertStringContainsString('check-composer-policy', $script);
        self::assertStringContainsString('check-symfony-imports', $script);
        self::assertStringContainsString('drift-detector.sh', $script);
        self::assertStringContainsString('composer verify', $script);
        self::assertStringNotContainsString('phpstan analyse', $script);
        self::assertStringNotContainsString('phpunit', $script);
        self::assertStringNotContainsString('parallel:', $script);
    }

    #[Test]
    public function installer_is_idempotent_and_preserves_unknown_hooks(): void
    {
        $fixture = sys_get_temp_dir() . '/waaseyaa-project-hooks-' . bin2hex(random_bytes(6));
        $linked = $fixture . '-linked';
        mkdir($fixture . '/bin', 0o777, true);
        copy($this->root . '/bin/project-hooks', $fixture . '/bin/project-hooks');
        chmod($fixture . '/bin/project-hooks', 0o755);

        try {
            $this->execute($fixture, 'git init --quiet');
            $this->execute($fixture, 'git config user.email test@example.com');
            $this->execute($fixture, 'git config user.name "Project Hooks Test"');
            $this->execute($fixture, 'git add bin/project-hooks');
            $this->execute($fixture, 'git commit --no-verify --quiet -m baseline');
            file_put_contents($fixture . '/.git/hooks/prepare-commit-msg', "#!/bin/sh\ncall_lefthook run prepare-commit-msg\n");
            $this->execute($fixture, 'bash bin/project-hooks install');
            $this->execute($fixture, 'bash bin/project-hooks install');
            $this->execute($fixture, 'bash bin/project-hooks doctor');
            mkdir($fixture . '/nested');
            $this->execute($fixture . '/nested', '../bin/project-hooks doctor');

            $hooks = $fixture . '/.git/hooks';
            self::assertStringContainsString('waaseyaa-project-hooks-v1', (string) file_get_contents($hooks . '/pre-commit'));
            self::assertStringContainsString('waaseyaa-project-hooks-v1', (string) file_get_contents($hooks . '/pre-push'));
            self::assertFileDoesNotExist($hooks . '/prepare-commit-msg');

            $this->execute($fixture, 'git worktree add --quiet -b linked ' . escapeshellarg($linked));
            $this->execute($linked, escapeshellarg($hooks . '/pre-commit'));

            file_put_contents($fixture . '/Example.php', "<?php\n");
            $this->execute($fixture, 'git add Example.php');
            $toolBin = $fixture . '/tool-bin';
            mkdir($toolBin);
            foreach (['dirname', 'git', 'grep'] as $command) {
                [, $path] = $this->execute($fixture, 'command -v ' . $command);
                symlink($path, $toolBin . '/' . $command);
            }
            [$missingCode, $missingOutput] = $this->execute(
                $fixture,
                'PATH=' . escapeshellarg($toolBin) . ' /bin/bash bin/project-hooks pre-commit',
                true,
            );
            self::assertNotSame(0, $missingCode, $missingOutput);
            self::assertStringContainsString("required command 'php' is unavailable", $missingOutput);

            file_put_contents($hooks . '/pre-push', "#!/bin/sh\necho custom\n");
            [$code, $output] = $this->execute($fixture, 'bash bin/project-hooks install', true);
            self::assertNotSame(0, $code, $output);
            self::assertSame("#!/bin/sh\necho custom\n", file_get_contents($hooks . '/pre-push'));
        } finally {
            $this->execute($fixture, 'git worktree remove --force ' . escapeshellarg($linked), true);
            $this->execute(sys_get_temp_dir(), 'rm -rf ' . escapeshellarg($fixture), true);
            $this->execute(sys_get_temp_dir(), 'rm -rf ' . escapeshellarg($linked), true);
        }
    }

    #[Test]
    public function claude_context_is_bounded_and_works_from_a_subdirectory(): void
    {
        [$code, $output] = $this->execute($this->root . '/packages/entity', '../../bin/project-hooks claude-session', true);
        self::assertSame(0, $code, $output);
        self::assertLessThanOrEqual(8, count(explode("\n", trim($output))));
        self::assertLessThan(2000, strlen($output));
        self::assertStringContainsString('Branch:', $output);
        self::assertStringContainsString('Working tree:', $output);
    }

    /** @return array{int, string} */
    private function execute(string $directory, string $command, bool $allowFailure = false): array
    {
        exec('cd ' . escapeshellarg($directory) . ' && ' . $command . ' 2>&1', $lines, $code);
        $output = implode("\n", $lines);
        if (!$allowFailure) {
            self::assertSame(0, $code, $output);
        }

        return [$code, $output];
    }
}
