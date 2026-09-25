<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Binds the two consumer CLI lanes and their ci/native-host-consumer-cli gate
 * to the consumer_cli section of tools/native-host-contract.json (#2678,
 * FW-2678-NATIVE-HOST-SKELETON-CLI-02): site-reference-consumer (Linux) and
 * ci/skeleton-create-project-windows run the same literal CLI step after the
 * lifecycle, record both steps' exit codes, collect and upload one evidence
 * artifact each even after a failure, and the gate pairs them behind
 * merge/platform-runtime-acceptance. ci/skeleton-create-project keeps proving
 * the published release line.
 */
#[CoversNothing]
final class NativeHostConsumerCliWorkflowTest extends TestCase
{
    private static string $root;

    /** @var array<string, mixed> */
    private static array $jobs;

    /** @var array<string, mixed> */
    private static array $contract;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/bin/lib/native-host-consumer-evidence.php';
        self::$jobs = Yaml::parseFile(self::$root . '/.github/workflows/ci.yml')['jobs'];
        self::$contract = \nhc_load_contract(self::$root . '/tools/native-host-contract.json');
    }

    /** @return iterable<string, array{string}> */
    public static function lanes(): iterable
    {
        yield 'linux' => ['linux'];
        yield 'windows' => ['windows'];
    }

    #[Test]
    #[DataProvider('lanes')]
    public function each_lane_runs_on_its_contract_runner_with_composer_2_10(string $host): void
    {
        $job = self::$jobs[self::job($host)];
        $setup = self::step($host, 'Set up PHP');

        self::assertSame(self::$contract['hosts'][$host]['runner'], $job['runs-on']);
        self::assertArrayNotHasKey('if', $job);
        self::assertArrayNotHasKey('continue-on-error', $job);
        self::assertSame('8.5', $setup['with']['php-version']);
        self::assertSame('composer:2.10', $setup['with']['tools']);
        self::assertSame('"version=$($PSVersionTable.PSVersion)" >> $env:GITHUB_OUTPUT', self::stepById($host, 'harness-shell')['run']);
    }

    #[Test]
    #[DataProvider('lanes')]
    public function the_cli_step_follows_the_lifecycle_and_records_its_native_exit_code(string $host): void
    {
        $ids = array_values(array_filter(array_column(self::$jobs[self::job($host)]['steps'], 'id')));
        $cli = self::stepById($host, 'consumer-cli');

        self::assertSame(['harness-shell', 'lifecycle', 'consumer-cli'], $ids);
        self::assertSame(
            "\$PSNativeCommandUseErrorActionPreference = \$false\n"
            . "New-Item -ItemType Directory -Force -Path (Split-Path -Parent \$env:NATIVE_HOST_CLI_STDOUT) | Out-Null\n"
            . "Set-Location -LiteralPath \$env:WAASEYAA_CONSUMER_ROOT\n"
            . \nhe_render_powershell(self::$contract['consumer_cli']['argv']) . " > \$env:NATIVE_HOST_CLI_STDOUT\n"
            . "\$exitCode = \$LASTEXITCODE\n"
            . "\"exit_code=\$exitCode\" >> \$env:GITHUB_OUTPUT\n"
            . "exit \$exitCode\n",
            $cli['run'],
        );
        self::assertSame(['NATIVE_HOST_CLI_STDOUT' => '${{ github.workspace }}/build/native-host-consumer/list-raw.stdout'], $cli['env']);
        self::assertArrayNotHasKey('if', $cli);
        self::assertArrayNotHasKey('continue-on-error', $cli);
        self::assertSame('pwsh', $cli['shell'] ?? self::$jobs[self::job($host)]['defaults']['run']['shell']);
        self::assertSame(
            self::position($host, 'lifecycle') + 1,
            self::position($host, 'consumer-cli'),
            'The CLI runs immediately after site:init and install:init complete.',
        );
    }

    #[Test]
    #[DataProvider('lanes')]
    public function the_collector_receives_both_step_results_and_the_hosted_identity(string $host): void
    {
        $collect = self::step($host, 'Collect and validate native-host consumer evidence');
        $upload = self::step($host, 'Upload native-host consumer evidence');
        $results = "lifecycle \${{ steps.lifecycle.outcome }} \${{ steps.lifecycle.outputs.exit_code }}\n"
            . "consumer-cli \${{ steps.consumer-cli.outcome }} \${{ steps.consumer-cli.outputs.exit_code }}\n";

        self::assertSame('always()', $collect['if']);
        self::assertSame(
            [
                'NATIVE_HOST_SHELL' => 'pwsh',
                'NATIVE_HOST_SHELL_VERSION' => '${{ steps.harness-shell.outputs.version }}',
                'NATIVE_HOST_RUNNER_LABEL' => self::$contract['hosts'][$host]['runner'],
                'NATIVE_HOST_DISPATCH_SHA' => '${{ inputs.sha }}',
                'NATIVE_HOST_PR_HEAD_SHA' => '${{ github.event.pull_request.head.sha }}',
                'NATIVE_HOST_CLI_STDOUT' => '${{ github.workspace }}/build/native-host-consumer/list-raw.stdout',
                'NATIVE_HOST_STEP_RESULTS' => $results,
            ],
            $collect['env'],
        );
        self::assertSame("php bin/native-host-evidence consumer-collect --host={$host} --out=build/native-host-consumer/evidence.json", $collect['run']);
        self::assertSame('always()', $upload['if']);
        self::assertSame(
            [
                'name' => \NHC_ARTIFACT_PREFIX . $host,
                'path' => 'build/native-host-consumer/',
                'if-no-files-found' => 'error',
                'retention-days' => 30,
                'overwrite' => true,
            ],
            $upload['with'],
        );
        self::assertSame(self::position($host, 'Collect and validate native-host consumer evidence') + 1, self::position($host, 'Upload native-host consumer evidence'));
    }

    #[Test]
    public function the_linux_lifecycle_hands_over_its_consumer_and_exit_code(): void
    {
        $lifecycle = self::stepById('linux', 'lifecycle');
        $harness = (string) file_get_contents(self::$root . '/tests/ReferenceConsumer/check-reference-consumer');
        $pass = strpos($harness, 'echo "reference-consumer: PASS');
        $handoff = strpos($harness, "printf 'WAASEYAA_CONSUMER_ROOT=%s\\n'");

        self::assertSame(
            "exit_status=0\n"
            . "WAASEYAA_REFERENCE_HANDOFF=\"\$GITHUB_ENV\" tests/ReferenceConsumer/check-reference-consumer || exit_status=\$?\n"
            . "echo \"exit_code=\$exit_status\" >> \"\$GITHUB_OUTPUT\"\n"
            . "exit \"\$exit_status\"\n",
            $lifecycle['run'],
        );
        self::assertIsInt($pass);
        self::assertIsInt($handoff);
        self::assertGreaterThan($pass, $handoff, 'The harness hands over its consumer only after its final PASS.');
        foreach (['WAASEYAA_CONSUMER_CANDIDATE_REVISION=%s\n\' "$candidate_revision"', 'WAASEYAA_CONSUMER_PROJECT_SOURCE=%s\n\' "$project_source"', 'WAASEYAA_CONSUMER_PROJECT_REVISION=%s\n\' "$project_revision"', 'APP_ENV=%s\n\' "$APP_ENV"'] as $line) {
            self::assertStringContainsString($line, $harness);
        }
        self::assertStringContainsString('candidate_revision=$(git -C "$framework_root" rev-parse HEAD)', $harness, 'The archived candidate is the checkout the harness runs in.');
    }

    #[Test]
    public function the_windows_lifecycle_hands_over_its_consumer_and_exit_code(): void
    {
        $run = self::stepById('windows', 'lifecycle')['run'];

        self::assertStringEndsWith(
            "\"WAASEYAA_CONSUMER_ROOT=\$work\" | Out-File -FilePath \$env:GITHUB_ENV -Append -Encoding utf8\n\"exit_code=0\" >> \$env:GITHUB_OUTPUT\n",
            $run,
        );
        self::assertStringContainsString("php (Join-Path \$repo 'tests/ReferenceConsumer/prepare.php') configure \$repo \$work", $run, 'The Windows consumer installs this checkout.');
        self::assertLessThan(
            self::position('windows', 'bimaaji:install containment holds across a Windows junction'),
            self::position('windows', 'Upload native-host consumer evidence'),
            'The consumer evidence is collected before the junction proof moves .waaseyaa aside.',
        );
        self::assertSame('Clean up the Windows consumer', end(self::$jobs['skeleton-create-project-windows']['steps'])['name']);
    }

    #[Test]
    public function the_gate_pairs_both_lanes_and_feeds_the_platform_decision(): void
    {
        $gate = self::$jobs['native-host-consumer-cli-evidence'];
        $lanes = array_map(static fn(array $lane): string => $lane['job'], self::$contract['consumer_cli']['lanes']);

        self::assertSame('ci/native-host-consumer-cli', $gate['name']);
        self::assertSame('ubuntu-24.04', $gate['runs-on']);
        self::assertSame(array_values($lanes), $gate['needs']);
        self::assertSame('always()', $gate['if']);
        self::assertSame(
            ['LINUX_CONSUMER' => '${{ needs.site-reference-consumer.result }}', 'WINDOWS_CONSUMER' => '${{ needs.skeleton-create-project-windows.result }}'],
            $gate['steps'][0]['env'],
        );
        self::assertSame('test "$LINUX_CONSUMER" = success && test "$WINDOWS_CONSUMER" = success', $gate['steps'][0]['run']);
        $downloads = [];
        $verify = null;
        foreach ($gate['steps'] as $step) {
            if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/download-artifact@')) {
                $downloads[] = $step['with'];
            }
            $verify = ($step['name'] ?? null) === 'Verify exactly one passing record per consumer lane' ? $step : $verify;
        }
        self::assertSame(
            array_map(
                static fn(string $host): array => ['name' => \NHC_ARTIFACT_PREFIX . $host, 'path' => 'build/native-host-consumer-evidence/' . \NHC_ARTIFACT_PREFIX . $host],
                array_keys($lanes),
            ),
            $downloads,
            'Each lane artifact is downloaded by its single name into its own directory.',
        );
        self::assertSame(
            'php bin/native-host-evidence consumer-verify-set --dir=build/native-host-consumer-evidence --hosts=' . implode(',', array_keys($lanes)),
            $verify['run'] ?? null,
        );

        $platform = self::$jobs['merge-platform-runtime-acceptance'];
        self::assertContains('native-host-consumer-cli-evidence', $platform['needs']);
        self::assertStringContainsString('"native-host-consumer-cli-evidence=$NATIVE_HOST_CONSUMER_CLI"', $platform['steps'][0]['run']);
    }

    #[Test]
    public function the_published_release_line_lane_is_not_a_consumer_cli_lane(): void
    {
        $job = self::$jobs['skeleton-create-project'];

        self::assertNotContains('skeleton-create-project', array_column(self::$contract['consumer_cli']['lanes'], 'job'));
        self::assertNotContains('consumer-cli', array_column($job['steps'], 'id'));
        self::assertStringNotContainsString('native-host-evidence', Yaml::dump($job, 10));
    }

    private static function job(string $host): string
    {
        return self::$contract['consumer_cli']['lanes'][$host]['job'];
    }

    /** @return array<string, mixed> */
    private static function step(string $host, string $name): array
    {
        foreach (self::$jobs[self::job($host)]['steps'] as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }
        self::fail(self::job($host) . " has no step named {$name}.");
    }

    /** @return array<string, mixed> */
    private static function stepById(string $host, string $id): array
    {
        foreach (self::$jobs[self::job($host)]['steps'] as $step) {
            if (($step['id'] ?? null) === $id) {
                return $step;
            }
        }
        self::fail(self::job($host) . " has no step with id {$id}.");
    }

    /** The index of the step whose id or name is $key. */
    private static function position(string $host, string $key): int
    {
        foreach (self::$jobs[self::job($host)]['steps'] as $index => $step) {
            if (($step['id'] ?? null) === $key || ($step['name'] ?? null) === $key) {
                return $index;
            }
        }
        self::fail(self::job($host) . " has no step {$key}.");
    }
}
