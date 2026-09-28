<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WaitForGreenCiTest extends TestCase
{
    private string $fixtureRoot;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa-wait-green-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . '/bin', 0o755, true);

        $fakeGh = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            case "$*" in
              *'/actions/workflows/ci.yml/runs?'*)
                printf 'completed\tsuccess\thttps://example.test/run/42\t42\n'
                ;;
              *'/actions/runs/42/jobs?'*)
                case "${FAKE_JOB_STATE:-success}" in
                  missing) printf 'ci/main-feedback\tsuccess\n' ;;
                  *) printf 'ci/main-feedback\tsuccess\nci/full-qualification\t%s\n' "${FAKE_JOB_STATE}" ;;
                esac
                ;;
              *)
                printf 'unexpected fake gh invocation: %s\n' "$*" >&2
                exit 70
                ;;
            esac
            BASH;
        file_put_contents($this->fixtureRoot . '/bin/gh', $fakeGh);
        chmod($this->fixtureRoot . '/bin/gh', 0o755);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixtureRoot . '/bin/gh');
        @rmdir($this->fixtureRoot . '/bin');
        @rmdir($this->fixtureRoot);
    }

    #[Test]
    #[DataProvider('requiredJobOutcomes')]
    public function requiredQualificationJobFailsClosed(string $state, int $expectedExit, string $expectedOutput): void
    {
        $result = $this->runGate($state, true);

        self::assertSame($expectedExit, $result['exit'], $result['output']);
        self::assertStringContainsString($expectedOutput, $result['output']);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function requiredJobOutcomes(): iterable
    {
        yield 'success' => ['success', 0, 'CI green'];
        yield 'failure' => ['failure', 1, "required job 'ci/full-qualification' concluded 'failure'"];
        yield 'missing' => ['missing', 1, "required job 'ci/full-qualification' concluded 'missing'"];
    }

    #[Test]
    public function legacyCallWithoutARequiredJobStillAcceptsWorkflowSuccess(): void
    {
        $result = $this->runGate('missing', false);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('CI green', $result['output']);
    }

    /** @return array{exit: int, output: string} */
    private function runGate(string $state, bool $requireJob): array
    {
        $arguments = [
            $this->bash(),
            $this->repoRoot . '/bin/wait-for-green-ci',
            str_repeat('a', 40),
            '0',
            'ci.yml',
        ];
        if ($requireJob) {
            $arguments[] = 'ci/full-qualification';
        }

        $path = PHP_OS_FAMILY === 'Windows'
            ? $this->fixtureRoot . '/bin;C:\\Program Files\\Git\\usr\\bin'
            : $this->fixtureRoot . '/bin:/usr/bin:/bin';
        $process = new Process($arguments, $this->repoRoot, [
            'PATH' => $path,
            'GITHUB_REPOSITORY' => 'fixture/framework',
            'FAKE_JOB_STATE' => $state,
        ]);
        $exit = $process->run();

        return ['exit' => $exit, 'output' => $process->getOutput() . $process->getErrorOutput()];
    }

    private function bash(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'bash';
        }

        $gitBash = 'C:/Program Files/Git/bin/bash.exe';
        self::assertFileExists($gitBash);

        return $gitBash;
    }
}
