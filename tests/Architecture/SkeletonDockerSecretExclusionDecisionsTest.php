<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The host-neutral decisions of the #2647 skeleton Docker secret gate
 * (FW-2678-PORTABLE-DOCKER-RELEASE-04, #2678).
 *
 * bin/check-skeleton-docker-secret-exclusion builds real images, so its proof
 * stays Linux-owned in ci/skeleton-create-project. What it decides from what
 * it observed lives in bin/lib/skeleton-docker-secret-exclusion.php, and this
 * class proves those decisions without Docker, a daemon or a POSIX shell. The
 * native-host contract runs it on native Windows and Linux.
 *
 * It also fails closed on the helper itself: the gate cannot pass or skip
 * without its library, and it must actually delegate to the library this
 * class tests rather than keep a private copy of any decision.
 */
#[CoversNothing]
final class SkeletonDockerSecretExclusionDecisionsTest extends TestCase
{
    private const GATE = 'bin/check-skeleton-docker-secret-exclusion';

    private const LIBRARY = 'bin/lib/skeleton-docker-secret-exclusion.php';

    private static string $root;

    /** @var list<string> */
    private array $temporary = [];

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/' . self::LIBRARY;
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporary);
        $this->temporary = [];
    }

    #[Test]
    public function the_docker_probes_classify_into_exactly_three_states(): void
    {
        $started = false;
        $info = static function () use (&$started): array {
            $started = true;

            return ['launched' => true, 'code' => 0];
        };

        $missing = \sdse_classify_docker(['launched' => false, 'code' => -1], $info);
        self::assertSame('unavailable', $missing['kind']);
        self::assertStringContainsString('not on PATH', $missing['reason']);
        self::assertFalse($started, '`docker info` must not start when `docker --version` did not.');

        $silent = \sdse_classify_docker(['launched' => true, 'code' => 1], $info);
        self::assertSame('unavailable', $silent['kind']);
        self::assertStringContainsString('did not answer --version', $silent['reason']);
        self::assertFalse($started, '`docker info` must not start when `docker --version` failed.');

        $unstartable = \sdse_classify_docker(
            ['launched' => true, 'code' => 0],
            static fn(): array => ['launched' => false, 'code' => -1],
        );
        self::assertSame('harness', $unstartable['kind'], 'An info probe that cannot start is never an absent daemon.');
        self::assertStringContainsString('could not be started', $unstartable['reason']);

        $daemonless = \sdse_classify_docker(
            ['launched' => true, 'code' => 0],
            static fn(): array => ['launched' => true, 'code' => 1],
        );
        self::assertSame('unavailable', $daemonless['kind']);
        self::assertStringContainsString('no daemon answered', $daemonless['reason']);

        self::assertSame(['kind' => 'ok', 'reason' => ''], \sdse_classify_docker(['launched' => true, 'code' => 0], $info));
        self::assertTrue($started);
    }

    #[Test]
    public function only_an_unavailable_daemon_with_the_flag_may_exit_3(): void
    {
        self::assertSame(
            [0, 1, 2, 3],
            [\SDSE_EXIT_PASS, \SDSE_EXIT_LEAK, \SDSE_EXIT_HARNESS, \SDSE_EXIT_NO_DOCKER],
            'The library exit codes are the gate\'s documented contract.',
        );

        foreach ([true, false] as $flag) {
            self::assertNull(\sdse_docker_state_outcome(['kind' => 'ok', 'reason' => ''], $flag));
        }

        $skip = \sdse_docker_state_outcome(['kind' => 'unavailable', 'reason' => 'no daemon here'], true);
        self::assertSame(3, $skip['exit'] ?? null);
        self::assertStringContainsString('SKIPPED', $skip['message']);
        self::assertStringContainsString('no daemon here', $skip['message']);

        $required = \sdse_docker_state_outcome(['kind' => 'unavailable', 'reason' => 'no daemon here'], false);
        self::assertSame(2, $required['exit'] ?? null);
        self::assertStringContainsString('Docker is REQUIRED and unavailable', $required['message']);
        self::assertStringContainsString('no daemon here', $required['message']);
        self::assertStringContainsString('Install or start Docker', $required['message']);
        self::assertStringContainsString('Hosted CI must never pass that flag', $required['message']);

        foreach (['harness', 'unknown', ''] as $kind) {
            foreach ([true, false] as $flag) {
                $outcome = \sdse_docker_state_outcome(['kind' => $kind, 'reason' => 'probe fault'], $flag);
                self::assertSame(2, $outcome['exit'] ?? null, "kind '{$kind}' must fail closed");
                self::assertStringContainsString('NOT a verdict that Docker is unavailable', $outcome['message']);
                self::assertStringContainsString('--allow-missing-docker', $outcome['message']);
                self::assertStringNotContainsString('SKIPPED', $outcome['message']);
            }
        }
    }

    /**
     * The composed invariant over every probe shape: the flag can turn an
     * unavailable daemon into exit 3, and nothing else; no shape but two
     * answering probes lets the inspection proceed.
     */
    #[Test]
    public function no_probe_shape_turns_a_harness_fault_into_a_pass_or_a_skip(): void
    {
        $shapes = [
            ['launched' => false, 'code' => -1],
            ['launched' => true, 'code' => 0],
            ['launched' => true, 'code' => 1],
            ['launched' => true, 'code' => 125],
        ];

        foreach ($shapes as $version) {
            foreach ($shapes as $info) {
                $docker = \sdse_classify_docker($version, static fn(): array => $info);
                $answered = $version === $shapes[1];
                foreach ([true, false] as $flag) {
                    $outcome = \sdse_docker_state_outcome($docker, $flag);
                    $label = json_encode([$version, $info, $flag], JSON_THROW_ON_ERROR);

                    if ($answered && $info === $shapes[1]) {
                        self::assertNull($outcome, $label);
                        continue;
                    }
                    self::assertNotNull($outcome, $label);
                    $skippable = $flag && (!$answered || $info['launched']);
                    self::assertSame($skippable ? 3 : 2, $outcome['exit'], $label);
                }
            }
        }
    }

    #[Test]
    public function the_dockerfile_parse_rejects_every_context_escape(): void
    {
        self::assertSame(
            [],
            \sdse_dockerfile_context_escapes((string) file_get_contents(self::$root . '/skeleton/Dockerfile')),
            'The tracked skeleton/Dockerfile must stay inside its build context.',
        );

        $dockerfile = implode("\n", [
            'FROM php:8.5-cli-alpine AS base',
            '# COPY .env /app/.env is only a comment',
            'COPY .env.example /app/.env',
            'COPY --from=deps /app/.env /app/.env',
            'ADD https://example.test/installer.sh /usr/local/bin/',
            'COPY .env /app/',
            'copy --chown=app:app config/.env.production /app/config/',
            'COPY a.txt .env.local/ b.txt /app/',
            'COPY \\',
            '    .env.staging \\',
            '    /app/',
            'ADD --chown=1:1 git://example.test/repo.git /src',
            'RUN echo COPY .env /nowhere',
        ]);

        $expected = [
            'skeleton/Dockerfile line 5: ADD fetches https://example.test/installer.sh from outside the build context, '
                . 'so the context inventory no longer bounds what can land in a layer.',
            'skeleton/Dockerfile line 6: COPY names .env explicitly, which would defeat the .dockerignore exclusion.',
            'skeleton/Dockerfile line 7: COPY names config/.env.production explicitly, which would defeat the '
                . '.dockerignore exclusion.',
            'skeleton/Dockerfile line 8: COPY names .env.local/ explicitly, which would defeat the .dockerignore '
                . 'exclusion.',
            'skeleton/Dockerfile line 9: COPY names .env.staging explicitly, which would defeat the .dockerignore '
                . 'exclusion.',
            'skeleton/Dockerfile line 12: ADD fetches git://example.test/repo.git from outside the build context, '
                . 'so the context inventory no longer bounds what can land in a layer.',
        ];

        self::assertSame($expected, \sdse_dockerfile_context_escapes($dockerfile));
        self::assertSame(
            $expected,
            \sdse_dockerfile_context_escapes(str_replace("\n", "\r\n", $dockerfile)),
            'A CRLF checkout must parse exactly like LF.',
        );
    }

    #[Test]
    public function the_context_inventory_counts_only_what_the_probe_copied(): void
    {
        $inventory = \sdse_context_inventory([
            '.dockerenv',
            'dev/',
            'dev/console',
            'etc/hosts',
            'context/',
            'context/.env',
            'context/.env.example',
            'context/.envrc',
            'context/.environment/',
            'context/app/',
            'context/app/Kernel.php',
            'context/config/',
            'context/config/.env.staging',
            'context/env',
            'contextual/.env',
        ]);

        self::assertSame(
            ['.env', '.env.example', '.environment', '.envrc', 'app', 'app/Kernel.php', 'config', 'config/.env.staging', 'env'],
            $inventory['inventoryPaths'],
        );
        self::assertSame(['.env', '.env.example', 'config/.env.staging'], $inventory['dotenvPaths']);
        self::assertSame(['inventoryPaths' => [], 'dotenvPaths' => []], \sdse_context_inventory(['.dockerenv', 'context/']));
    }

    #[Test]
    public function the_sentinel_scan_reads_raw_and_gzip_blobs_across_chunk_boundaries(): void
    {
        $directory = $this->temporaryDirectory();
        $raw = 'WAASEYAA_SENTINEL_RAW_0123456789abcdef';
        $compressed = 'WAASEYAA_SENTINEL_GZIP_0123456789abcdef';
        $straddling = 'WAASEYAA_SENTINEL_STRADDLE_0123456789abcdef';
        $windowed = 'WAASEYAA_SENTINEL_WINDOW_0123456789abcdef';
        $absent = 'WAASEYAA_SENTINEL_ABSENT_0123456789abcdef';

        file_put_contents($directory . '/layer.tar', "header\0{$raw}\0trailer");
        self::assertSame([$raw], \sdse_scan_file($directory . '/layer.tar', [$absent, $raw]));

        // A gzip member whose header names $raw in plain bytes and whose
        // compressed stream holds $compressed: the scan reports the union.
        $payload = str_repeat("padding\n", 64) . $compressed . "\n";
        file_put_contents(
            $directory . '/layer.tar.gz',
            "\x1f\x8b\x08\x08" . pack('V', 0) . "\x00\x03" . $raw . "\x00"
                . gzdeflate($payload) . pack('V', crc32($payload)) . pack('V', strlen($payload)),
        );
        self::assertSame(
            [$raw],
            \sdse_stream_contains($directory . '/layer.tar.gz', [$compressed, $raw]),
            'The compressed bytes must not contain the sentinel, or the gzip branch proves nothing.',
        );
        self::assertSame([$raw, $compressed], \sdse_scan_file($directory . '/layer.tar.gz', [$compressed, $raw, $absent]));

        // $windowed ends inside the last 512 bytes of the scan's first 1 MiB
        // read, so the next read sees it again in the overlap and must not
        // report it twice. $straddling starts ten bytes before the end of that
        // read, so only the overlap window can find it.
        file_put_contents(
            $directory . '/large.tar',
            str_repeat('x', (1 << 20) - 200) . $windowed . str_repeat('x', 200 - strlen($windowed) - 10)
                . $straddling . str_repeat('y', 100) . $raw,
        );
        self::assertSame(
            [$windowed, $straddling, $raw],
            \sdse_scan_file($directory . '/large.tar', [$windowed, $straddling, $raw, $absent]),
        );

        self::assertSame([], \sdse_scan_file($directory . '/layer.tar', []));
    }

    #[Test]
    public function the_generated_secrets_are_read_exactly_and_refused_when_absent_or_short(): void
    {
        $secret = str_repeat('ab', 32);
        $env = "APP_NAME=Waaseyaa\nOLD_WAASEYAA_JWT_SECRET=not-this-one-0123456789\n"
            . "WAASEYAA_JWT_SECRET_PREVIOUS=not-this-one-either-0123\nWAASEYAA_JWT_SECRET={$secret}\n";

        self::assertSame($secret, \sdse_generated_value($env, 'WAASEYAA_JWT_SECRET'));
        self::assertSame(
            $secret,
            \sdse_generated_value(str_replace("\n", "\r\n", $env), 'WAASEYAA_JWT_SECRET'),
            'A CRLF .env must yield the same sentinel, without the carriage return.',
        );
        self::assertSame('0123456789abcdef', \sdse_generated_value("WAASEYAA_APP_SECRET=0123456789abcdef\n", 'WAASEYAA_APP_SECRET'));

        foreach (
            [
                'did not populate WAASEYAA_APP_SECRET' => [$env, 'WAASEYAA_APP_SECRET'],
                'does not look like generated material' => ["WAASEYAA_APP_SECRET=0123456789abcde\n", 'WAASEYAA_APP_SECRET'],
            ] as $reason => [$input, $key]
        ) {
            try {
                \sdse_generated_value($input, $key);
                self::fail("Expected a harness error: {$reason}.");
            } catch (\RuntimeException $error) {
                self::assertStringContainsString($reason, $error->getMessage());
            }
        }
    }

    /**
     * The sentinels come from the real create-project generator, so it and the
     * reader must agree on this host, whatever line endings its checkout of
     * skeleton/.env.example has.
     */
    #[Test]
    public function the_real_generator_output_yields_both_sentinels_on_this_host(): void
    {
        $project = $this->temporaryDirectory();
        mkdir($project . '/bin');
        copy(self::$root . '/skeleton/.env.example', $project . '/.env.example');
        copy(self::$root . '/skeleton/bin/post-create-setup.php', $project . '/bin/post-create-setup.php');

        $setup = new Process([PHP_BINARY, $project . '/bin/post-create-setup.php'], $project);
        $setup->setTimeout(60.0);
        $setup->run();
        self::assertSame(0, $setup->getExitCode(), $setup->getOutput() . $setup->getErrorOutput());

        $env = (string) file_get_contents($project . '/.env');
        $jwt = \sdse_generated_value($env, 'WAASEYAA_JWT_SECRET');
        $app = \sdse_generated_value($env, 'WAASEYAA_APP_SECRET');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $jwt);
        self::assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $app);
    }

    #[Test]
    public function the_positive_control_refuses_a_scan_that_cannot_see_the_leak(): void
    {
        $sentinels = ['.env WAASEYAA_JWT_SECRET' => 'SENTINEL-A', '.env.local' => 'SENTINEL-B'];
        $complete = [
            'dotenvPaths' => ['.env', '.env.local'],
            'rootfsHits' => ['SENTINEL-A', 'SENTINEL-B'],
            'layerHits' => ['SENTINEL-A' => ['1a2b'], 'SENTINEL-B' => ['3c4d']],
        ];
        self::assertSame([], \sdse_control_failures($complete, $sentinels));

        self::assertSame(
            [
                'positive control: no dotenv file appeared in the build-context inventory even with .dockerignore '
                    . 'removed. The inventory reader is broken and a PASS would be meaningless.',
                'positive control: .env WAASEYAA_JWT_SECRET was not observed in any saved layer with .dockerignore '
                    . 'removed; the layer scan cannot detect a leak.',
                'positive control: .env.local was not observed in the image filesystem with .dockerignore removed; '
                    . 'the filesystem scan cannot detect a leak.',
                'positive control: .env.local was not observed in any saved layer with .dockerignore removed; '
                    . 'the layer scan cannot detect a leak.',
            ],
            \sdse_control_failures(
                ['dotenvPaths' => [], 'rootfsHits' => ['SENTINEL-A'], 'layerHits' => ['SENTINEL-A' => []]],
                $sentinels,
            ),
        );
    }

    #[Test]
    public function the_subject_verdict_names_every_leak_and_a_lost_example(): void
    {
        $sentinels = ['.env WAASEYAA_APP_SECRET' => 'SENTINEL-A', 'config/.env' => 'SENTINEL-B'];
        $clean = [
            'inventoryPaths' => ['.env.example', 'app', 'config', 'config/.env.example'],
            'dotenvPaths' => ['.env.example', 'config/.env.example'],
            'rootfsHits' => [],
            'layerHits' => ['SENTINEL-A' => [], 'SENTINEL-B' => []],
        ];
        self::assertSame([], \sdse_subject_failures($clean, $sentinels));

        self::assertSame(
            [
                'build-context inventory: dotenv file reached the build context (.env).',
                'build-context inventory: dotenv file reached the build context (config/.env).',
                'image filesystem: .env WAASEYAA_APP_SECRET is readable in the image rootfs.',
                'saved layers: config/.env is readable in layer blob 5e6f.',
                'saved layers: config/.env is readable in layer blob 7a8b.',
                '.env.example did not reach the build context; the intentional `!.env.example` negation in '
                    . 'skeleton/.dockerignore has regressed.',
            ],
            \sdse_subject_failures(
                [
                    'inventoryPaths' => ['.env', 'config', 'config/.env'],
                    'dotenvPaths' => ['.env', 'config/.env'],
                    'rootfsHits' => ['SENTINEL-A'],
                    'layerHits' => ['SENTINEL-A' => [], 'SENTINEL-B' => ['5e6f', '7a8b']],
                ],
                $sentinels,
            ),
        );
    }

    /**
     * The false-green discriminator for the helper itself. Without its
     * library the gate can reach no verdict, so every mode, including the one
     * the native-host contract runs and the flag that authorises a skip, must
     * be a harness error: never 0, never 3. The control run with the library
     * in place proves the copy is otherwise runnable.
     */
    #[Test]
    public function a_missing_decision_library_fails_closed_in_every_mode(): void
    {
        $work = $this->temporaryDirectory();
        mkdir($work . '/bin/lib', 0o777, true);
        mkdir($work . '/skeleton');
        copy(self::$root . '/' . self::GATE, $work . '/bin/gate');

        foreach ([[], ['--self-test'], ['--allow-missing-docker'], ['--allow-missing-docker', '--keep']] as $flags) {
            $gate = new Process([PHP_BINARY, $work . '/bin/gate', ...$flags], $work);
            $gate->setTimeout(120.0);
            $gate->run();
            $label = 'flags ' . json_encode($flags, JSON_THROW_ON_ERROR);

            self::assertSame(2, $gate->getExitCode(), $label . "\n" . $gate->getOutput() . $gate->getErrorOutput());
            self::assertStringContainsString('decision library', $gate->getErrorOutput(), $label);
            self::assertStringContainsString('never a pass or a skip', $gate->getErrorOutput(), $label);
            self::assertSame('', $gate->getOutput(), $label);
        }

        copy(self::$root . '/' . self::LIBRARY, $work . '/' . self::LIBRARY);
        $control = new Process([PHP_BINARY, $work . '/bin/gate', '--self-test'], $work);
        $control->setTimeout(120.0);
        $control->run();
        self::assertSame(0, $control->getExitCode(), $control->getOutput() . $control->getErrorOutput());
        self::assertStringContainsString('SELF-TEST PASS', $control->getOutput());
    }

    /**
     * The flag reaches the decision through the real gate: with Docker
     * unavailable (here a `docker --version` that answers non-zero, simulated
     * with this PHP binary), the gate fails without --allow-missing-docker and
     * skips with exit 3 only when the flag is passed.
     */
    #[Test]
    public function the_gate_skips_an_unavailable_daemon_only_with_the_flag(): void
    {
        $work = $this->temporaryDirectory();
        mkdir($work . '/bin/lib', 0o777, true);
        mkdir($work . '/skeleton');
        copy(self::$root . '/' . self::LIBRARY, $work . '/' . self::LIBRARY);
        $source = (string) file_get_contents(self::$root . '/' . self::GATE);
        $simulated = str_replace("run(['docker', '--version'])", "run([PHP_BINARY, '-r', 'exit(1);'])", $source);
        self::assertNotSame($source, $simulated, 'The `docker --version` call site could not be substituted.');
        file_put_contents($work . '/bin/gate', $simulated);

        foreach (
            [
                'without the flag' => [[], 2, 'Docker is REQUIRED and unavailable'],
                'with the flag' => [['--allow-missing-docker'], 3, 'SKIPPED'],
            ] as $case => [$flags, $exit, $message]
        ) {
            $gate = new Process([PHP_BINARY, $work . '/bin/gate', ...$flags], $work);
            $gate->setTimeout(120.0);
            $gate->run();

            self::assertSame($exit, $gate->getExitCode(), $case . "\n" . $gate->getOutput() . $gate->getErrorOutput());
            self::assertStringContainsString($message, $gate->getErrorOutput(), $case);
            self::assertStringContainsString('did not answer --version', $gate->getErrorOutput(), $case);
            self::assertSame('', $gate->getOutput(), $case);
        }
    }

    /**
     * The tested library is the one the gate runs: the gate calls every
     * decision entry point, keeps no private copy of a moved decision, and
     * every library function is reachable from the gate.
     */
    #[Test]
    public function the_gate_delegates_every_decision_to_the_tested_library(): void
    {
        $gate = (string) file_get_contents(self::$root . '/' . self::GATE);
        $library = (string) file_get_contents(self::$root . '/' . self::LIBRARY);

        [$gateDefines, $gateCalls] = self::functionsIn($gate);
        [$libraryDefines, $libraryCalls] = self::functionsIn($library);

        foreach (
            [
                'sdse_classify_docker', 'sdse_docker_state_outcome', 'sdse_generated_value', 'sdse_context_inventory',
                'sdse_scan_file', 'sdse_control_failures', 'sdse_subject_failures', 'sdse_dockerfile_context_escapes',
            ] as $entry
        ) {
            self::assertContains($entry, $gateCalls, "The gate must delegate to {$entry}().");
        }
        self::assertSame(
            [],
            array_values(array_diff($libraryDefines, $gateCalls, $libraryCalls)),
            'Library functions the gate cannot reach.',
        );
        self::assertSame(
            [],
            array_values(array_intersect($gateDefines, ['scanFile', 'streamContains', 'requireGeneratedValue'])),
            'The gate must not keep a private copy of a decision the library owns.',
        );
        self::assertSame(
            1,
            preg_match('/^require \$library;\r?$/m', $gate),
            'The gate must load its library unconditionally, before any mode runs.',
        );
    }

    /**
     * Functions a PHP source defines, and the ones it calls or names as a
     * string callable. Comments and docblocks are not calls.
     *
     * @return array{list<string>, list<string>}
     */
    private static function functionsIn(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn(array|string $token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $defines = [];
        $calls = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;
            if ($token[0] === T_STRING && is_array($previous) && $previous[0] === T_FUNCTION) {
                $defines[] = $token[1];
            } elseif (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && $next === '(') {
                $calls[] = ltrim($token[1], '\\');
            } elseif ($token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"](sdse_\w+)[\'"]$/', $token[1], $name) === 1) {
                $calls[] = $name[1];
            }
        }

        return [array_values(array_unique($defines)), array_values(array_unique($calls))];
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/waaseyaa-sdse-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o777, true), "Unable to create {$directory}.");
        $this->temporary[] = $directory;

        return $directory;
    }
}
