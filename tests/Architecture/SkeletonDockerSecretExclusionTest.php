<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * #2647: no secret generated into a skeleton application's `.env` may enter the
 * Docker build context, the final image filesystem, or a saved image layer.
 *
 * The substantive proof is `bin/check-skeleton-docker-secret-exclusion`, which
 * builds a real image from a real create-project context and inspects all three
 * surfaces plus a positive control. This class is its suite-side binding: it
 * keeps the gate wired into hosted CI, and runs it locally whenever a Docker
 * daemon is present.
 *
 * Six of the seven tests never skip: the two wiring assertions and the four
 * that cover the gate's own subprocess runner and its Docker classification,
 * which need no Docker and no daemon. Only the execution test skips, and only
 * on the one observable
 * predicate `docker info` fails — see the governed entry in
 * `tools/phpunit-skip-policy.json`. `ci/skeleton-create-project` runs the gate
 * without `--allow-missing-docker`, so on the supported hosted lane an absent
 * daemon is a hard failure, never a silent pass.
 */
#[CoversNothing]
final class SkeletonDockerSecretExclusionTest extends TestCase
{
    private const GATE = 'bin/check-skeleton-docker-secret-exclusion';

    /** `bin/check-skeleton-docker-secret-exclusion` exit code for "Docker unavailable". */
    private const EXIT_NO_DOCKER = 3;

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_generated_dotenv_is_excluded_from_the_build_context_the_image_and_every_layer(): void
    {
        $gate = new Process(
            [PHP_BINARY, self::GATE, '--allow-missing-docker'],
            $this->repoRoot,
        );
        $gate->setTimeout(1800.0);
        $gate->run();

        $output = $gate->getOutput() . $gate->getErrorOutput();

        if ($gate->getExitCode() === self::EXIT_NO_DOCKER) {
            self::markTestSkipped(
                'Requires a reachable Docker daemon; `docker info` did not answer. '
                . 'The proof is not optional on the supported lane: ci/skeleton-create-project runs '
                . self::GATE . ' with no --allow-missing-docker, so an absent daemon fails there. '
                . 'Gate output: ' . trim($output),
            );
        }

        self::assertSame(
            0,
            $gate->getExitCode(),
            "A generated skeleton .env secret can still reach a distributable image.\n" . $output,
        );
        self::assertStringContainsString('leak observed on all three surfaces', $output);
        self::assertStringContainsString(
            'PASS — no generated secret reached the build context, the image filesystem, or any saved layer.',
            $output,
        );
        self::assertStringContainsString('PASS — .env.example survived the exclusion, as intended.', $output);
    }

    /**
     * The runner, not the Docker proof. Requires no daemon, so it runs on every
     * host including the Windows one that has no Docker at all.
     */
    #[Test]
    public function the_subprocess_runner_is_platform_correct_on_every_host(): void
    {
        $selfTest = new Process([PHP_BINARY, self::GATE, '--self-test'], $this->repoRoot);
        $selfTest->setTimeout(120.0);
        $selfTest->run();

        $output = $selfTest->getOutput() . $selfTest->getErrorOutput();

        self::assertSame(
            0,
            $selfTest->getExitCode(),
            "The gate's subprocess runner is not platform-correct.\n" . $output,
        );
        self::assertStringContainsString('SELF-TEST PASS', $output);
        self::assertStringContainsString('null device is platform-derived', $output);
    }

    #[Test]
    public function the_null_device_is_derived_from_the_runtime_not_hard_coded(): void
    {
        $gate = (string) file_get_contents($this->repoRoot . '/' . self::GATE);

        self::assertStringContainsString(
            "=== 'Windows' ? 'NUL' : '/dev/null'",
            $gate,
            'The null device must map Windows to NUL. Hard-coding /dev/null makes proc_open() return '
            . 'false on native Windows, and this gate reads a false return as a missing binary.',
        );
        self::assertStringNotContainsString(
            "0 => ['file', '/dev/null', 'r']",
            $gate,
            'The proc_open() descriptor spec must take its null device from nullDevice(), never a literal.',
        );
    }

    /**
     * The P1 this test exists for: a broken subprocess launcher must never be
     * reported as a missing Docker daemon, because that verdict is what makes a
     * run eligible for the --allow-missing-docker skip path. A developer would
     * then watch the proof silently skip while Docker was in fact running.
     *
     * Windows is reproduced here without a Windows machine. On native Windows a
     * hard-coded '/dev/null' is unopenable and proc_open() returns false; naming
     * the foreign device on this host produces that identical false return. So
     * forcing nullDevice() to the foreign value exercises the exact Windows code
     * path. Docker is irrelevant to this test in both directions: the launcher
     * is checked before any Docker probe.
     */
    #[Test]
    public function a_broken_launcher_is_never_reported_as_a_missing_docker_daemon(): void
    {
        $work = sys_get_temp_dir() . '/waaseyaa-2647-winsim-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($work . '/bin', 0o777, true), 'Unable to create the simulation tree.');
        // The gate only checks that skeleton/ is a directory before reaching the
        // launcher probe, so an empty one keeps this test free of a tree copy.
        self::assertTrue(mkdir($work . '/skeleton', 0o777, true));

        try {
            $source = (string) file_get_contents($this->repoRoot . '/' . self::GATE);
            $simulated = str_replace(
                "return (\$osFamily ?? PHP_OS_FAMILY) === 'Windows' ? 'NUL' : '/dev/null';",
                "return PHP_OS_FAMILY === 'Windows' ? '/dev/null' : 'NUL';",
                $source,
            );
            self::assertNotSame(
                $source,
                $simulated,
                'nullDevice() no longer has the expected shape; the Windows simulation cannot be applied.',
            );
            file_put_contents($work . '/bin/gate', $simulated);

            // With the flag that WOULD authorise a skip, if the gate were fooled.
            $skippable = new Process([PHP_BINARY, $work . '/bin/gate', '--allow-missing-docker'], $work);
            $skippable->setTimeout(120.0);
            $skippable->run();
            $output = $skippable->getOutput() . $skippable->getErrorOutput();

            self::assertNotSame(
                self::EXIT_NO_DOCKER,
                $skippable->getExitCode(),
                'A launcher fault took the Docker-unavailable skip path. On Windows this reports a working '
                . "Docker install as absent and the proof silently skips.\n" . $output,
            );
            self::assertSame(
                2,
                $skippable->getExitCode(),
                "A launcher fault must be a loud harness error (exit 2).\n" . $output,
            );
            self::assertStringContainsString('subprocess launcher is broken', $output);
            self::assertStringContainsString('NOT a statement about Docker', $output);
            self::assertStringNotContainsString('not on PATH', $output);
        } finally {
            @unlink($work . '/bin/gate');
            @rmdir($work . '/bin');
            @rmdir($work . '/skeleton');
            @rmdir($work);
        }
    }

    /**
     * The residual half of the same P1, one call site later. By the time
     * `docker info` runs, two facts are already established: the launcher works,
     * and this exact `docker` binary launched moments earlier from this exact
     * PATH. So an `info` that fails to START cannot be an unavailable daemon --
     * an absent daemon still lets the CLI start, it just answers with an error.
     * Classifying that as daemon-unavailable routes a harness fault into the
     * skippable exit-3 path, and --allow-missing-docker then converts a broken
     * harness into a pass-shaped skip.
     *
     * The interleaving is injected rather than waited for, and needs no Docker
     * in either direction: `--version` is replaced with a command that launches
     * and exits 0, `info` with one that cannot launch at all. That is exactly
     * the state the reasoning above is about.
     */
    #[Test]
    public function a_docker_probe_that_cannot_start_is_never_classified_as_an_unavailable_daemon(): void
    {
        $work = sys_get_temp_dir() . '/waaseyaa-2647-infosim-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($work . '/bin', 0o777, true), 'Unable to create the simulation tree.');
        self::assertTrue(mkdir($work . '/skeleton', 0o777, true));

        try {
            $source = (string) file_get_contents($this->repoRoot . '/' . self::GATE);

            // `docker --version` launches and succeeds...
            $simulated = str_replace(
                "run(['docker', '--version'])",
                "run([PHP_BINARY, '-r', 'echo \"Docker version 0.0.0-simulated\";'])",
                $source,
            );
            self::assertNotSame($source, $simulated, 'The `docker --version` call site could not be substituted.');

            // ...and `docker info` then fails to launch at all.
            $withFailingInfo = str_replace(
                "run(['docker', 'info', '--format', '{{.ServerVersion}}'])",
                "run(['waaseyaa-2647-no-such-docker-binary'])",
                $simulated,
            );
            self::assertNotSame(
                $simulated,
                $withFailingInfo,
                'The `docker info` call site could not be substituted.',
            );
            file_put_contents($work . '/bin/gate', $withFailingInfo);

            // With the flag that WOULD authorise a skip, if the gate were fooled.
            $skippable = new Process([PHP_BINARY, $work . '/bin/gate', '--allow-missing-docker'], $work);
            $skippable->setTimeout(120.0);
            $skippable->run();
            $output = $skippable->getOutput() . $skippable->getErrorOutput();

            self::assertNotSame(
                self::EXIT_NO_DOCKER,
                $skippable->getExitCode(),
                'A `docker info` that could not start took the daemon-unavailable skip path. '
                . "--allow-missing-docker then turns a harness fault into a pass-shaped skip.\n" . $output,
            );
            self::assertSame(
                2,
                $skippable->getExitCode(),
                "A Docker probe that cannot start must be a loud harness error (exit 2).\n" . $output,
            );
            self::assertStringContainsString('could not be started', $output);
            self::assertStringContainsString('NOT a verdict that Docker is unavailable', $output);
            self::assertStringNotContainsString('no daemon answered', $output);
        } finally {
            @unlink($work . '/bin/gate');
            @rmdir($work . '/bin');
            @rmdir($work . '/skeleton');
            @rmdir($work);
        }
    }

    #[Test]
    public function the_gate_is_executable_and_wired_into_hosted_ci(): void
    {
        $gatePath = $this->repoRoot . '/' . self::GATE;
        self::assertFileExists($gatePath);
        self::assertFileIsReadable($gatePath);

        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');
        self::assertStringContainsString(
            'php ' . self::GATE,
            $workflow,
            self::GATE . ' must run in hosted CI, where a Docker daemon is available. '
            . 'Without the workflow step the suite-side test skips on daemonless runners and the '
            . 'regression is proven nowhere.',
        );
        self::assertStringNotContainsString(
            self::GATE . ' --allow-missing-docker',
            $workflow,
            'Hosted CI must never pass --allow-missing-docker: that would convert the only real proof '
            . 'of #2647 into an unconditional pass.',
        );
    }

    #[Test]
    public function the_skeleton_dockerignore_excludes_dotenv_while_keeping_the_example(): void
    {
        // A cheap, always-running companion to the Docker gate above. It cannot
        // replace it — .dockerignore semantics are the daemon's, not this file's
        // — but it names the regression in the failure message on runners where
        // the daemon is absent.
        $patterns = array_values(array_filter(
            array_map(
                'trim',
                preg_split('/\R/', (string) file_get_contents($this->repoRoot . '/skeleton/.dockerignore')) ?: [],
            ),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));

        foreach (['.env', '.env.*', '**/.env', '**/.env.*'] as $required) {
            self::assertContains(
                $required,
                $patterns,
                "skeleton/.dockerignore must exclude {$required}; bin/post-create-setup.php writes "
                . 'generated secrets there and the production stage runs `COPY . /app`.',
            );
        }

        self::assertContains('!.env.example', $patterns);

        $firstNegation = min(array_keys($patterns, '!.env.example', true));
        foreach (['.env', '.env.*', '**/.env', '**/.env.*'] as $exclusion) {
            self::assertLessThan(
                $firstNegation,
                max(array_keys($patterns, $exclusion, true)),
                'In .dockerignore the last matching pattern wins, so `!.env.example` must come after '
                . "`{$exclusion}`, which also matches it.",
            );
        }
    }
}
