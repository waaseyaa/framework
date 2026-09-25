<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Binds the ci.yml native-host-contract matrix and its ci/native-host-contract
 * gate to tools/native-host-contract.json (#2678,
 * FW-2678-NATIVE-HOST-CONTRACT-01): both leaves run every contract command as
 * its own step with the exact rendered argument array, record each native
 * exit code, hand the collector only the governed step results, upload their
 * evidence even after a failure, and feed the platform merge decision. The
 * contract's expected test methods are exactly what its PHPUnit selections
 * declare in source.
 */
#[CoversNothing]
final class NativeHostContractWorkflowTest extends TestCase
{
    private static string $root;

    /** @var array<string, mixed> */
    private static array $jobs;

    /** @var array<string, mixed> */
    private static array $contract;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/bin/lib/native-host-evidence.php';
        $workflow = Yaml::parseFile(self::$root . '/.github/workflows/ci.yml');
        self::$jobs = $workflow['jobs'];
        self::$contract = \nhe_load_contract(self::$root . '/tools/native-host-contract.json');
    }

    #[Test]
    public function the_matrix_runs_exactly_the_contract_hosts_on_their_pinned_runners(): void
    {
        $job = self::$jobs['native-host-contract'];

        self::assertSame('ci/native-host-contract-${{ matrix.host }}', $job['name']);
        self::assertSame('${{ matrix.runner }}', $job['runs-on']);
        self::assertSame(20, $job['timeout-minutes']);
        self::assertArrayNotHasKey('if', $job);
        self::assertArrayNotHasKey('needs', $job);
        self::assertArrayNotHasKey('continue-on-error', $job);
        self::assertFalse($job['strategy']['fail-fast']);
        self::assertSame(array_keys(self::$contract['hosts']), $job['strategy']['matrix']['host']);
        self::assertSame(
            array_map(static fn(array $host): string => $host['runner'], self::$contract['hosts']),
            array_column($job['strategy']['matrix']['include'], 'runner', 'host'),
        );
        self::assertSame(['run' => ['shell' => self::$contract['harness_shell']]], $job['defaults']);
    }

    #[Test]
    public function setup_pins_php_the_contract_extensions_and_the_composer_2_10_line(): void
    {
        $setup = self::step('native-host-contract', 'Set up PHP');

        self::assertSame('8.5', $setup['with']['php-version']);
        self::assertSame(implode(', ', self::$contract['php_extensions']), $setup['with']['extensions']);
        self::assertSame('none', $setup['with']['coverage']);
        self::assertSame('composer:2.10', $setup['with']['tools']);
        self::assertSame('2.10.0', self::$contract['runtime']['composer']['min']);
        self::assertSame('2.11.0', self::$contract['runtime']['composer']['below']);
    }

    #[Test]
    public function every_contract_command_is_one_step_that_records_its_native_exit_code(): void
    {
        $steps = self::$jobs['native-host-contract']['steps'];
        $ids = array_column(self::$contract['commands'], 'id');

        self::assertSame(
            ['harness-shell', ...$ids],
            array_values(array_filter(array_column($steps, 'id'))),
            'The only step ids are the hosted-shell record and the contract steps, in contract order.',
        );
        foreach (self::$contract['commands'] as $command) {
            $step = self::stepById('native-host-contract', $command['id']);
            self::assertSame(
                "\$PSNativeCommandUseErrorActionPreference = \$false\n"
                . \nhe_render_powershell($command['argv']) . "\n"
                . "\$exitCode = \$LASTEXITCODE\n"
                . "\"exit_code=\$exitCode\" >> \$env:GITHUB_OUTPUT\n"
                . "exit \$exitCode\n",
                $step['run'],
                $command['id'],
            );
            self::assertArrayNotHasKey('if', $step, $command['id']);
            self::assertArrayNotHasKey('continue-on-error', $step, $command['id']);
            self::assertArrayNotHasKey('shell', $step, $command['id']);
        }
    }

    #[Test]
    public function the_collector_receives_only_the_governed_step_results_and_hosted_identity(): void
    {
        $collect = self::step('native-host-contract', 'Collect and validate native-host evidence');
        $expected = '';
        foreach (array_column(self::$contract['commands'], 'id') as $id) {
            $expected .= "{$id} \${{ steps.{$id}.outcome }} \${{ steps.{$id}.outputs.exit_code }}\n";
        }

        self::assertSame('always()', $collect['if']);
        self::assertSame($expected, $collect['env']['NATIVE_HOST_STEP_RESULTS']);
        self::assertSame(
            [
                'NATIVE_HOST_SHELL' => 'pwsh',
                'NATIVE_HOST_SHELL_VERSION' => '${{ steps.harness-shell.outputs.version }}',
                'NATIVE_HOST_RUNNER_LABEL' => '${{ matrix.runner }}',
                'NATIVE_HOST_DISPATCH_SHA' => '${{ inputs.sha }}',
                'NATIVE_HOST_PR_HEAD_SHA' => '${{ github.event.pull_request.head.sha }}',
                'NATIVE_HOST_STEP_RESULTS' => $expected,
            ],
            $collect['env'],
        );
        self::assertSame('php bin/native-host-evidence collect --host=${{ matrix.host }} --out=build/native-host/evidence.json', $collect['run']);
        self::assertStringNotContainsString('toJSON(steps', (string) file_get_contents(self::$root . '/.github/workflows/ci.yml'));
        self::assertSame('"version=$($PSVersionTable.PSVersion)" >> $env:GITHUB_OUTPUT', self::stepById('native-host-contract', 'harness-shell')['run']);
    }

    #[Test]
    public function evidence_is_uploaded_per_host_even_after_a_failed_step(): void
    {
        $upload = self::step('native-host-contract', 'Upload native-host evidence');

        self::assertSame('always()', $upload['if']);
        self::assertSame(
            [
                'name' => 'native-host-evidence-${{ matrix.host }}',
                'path' => 'build/native-host/',
                'if-no-files-found' => 'error',
                'retention-days' => 30,
                'overwrite' => true,
            ],
            $upload['with'],
        );
        self::assertSame('Upload native-host evidence', end(self::$jobs['native-host-contract']['steps'])['name']);
    }

    #[Test]
    public function the_gate_verifies_one_record_per_host_and_feeds_the_platform_decision(): void
    {
        $gate = self::$jobs['native-host-contract-evidence'];

        self::assertSame('ci/native-host-contract', $gate['name']);
        self::assertSame('ubuntu-24.04', $gate['runs-on']);
        self::assertSame(['native-host-contract'], $gate['needs']);
        self::assertSame('always()', $gate['if']);
        self::assertSame('${{ needs.native-host-contract.result }}', $gate['steps'][0]['env']['NATIVE_HOST_LEAVES']);
        self::assertSame('test "$NATIVE_HOST_LEAVES" = success', $gate['steps'][0]['run']);
        self::assertSame('native-host-evidence-*', self::step('native-host-contract-evidence', 'Download the per-host evidence')['with']['pattern']);
        self::assertSame(
            'php bin/native-host-evidence verify-set --dir=build/native-host-evidence --hosts=' . implode(',', array_keys(self::$contract['hosts'])),
            self::step('native-host-contract-evidence', 'Verify exactly one passing record per host')['run'],
        );

        $platform = self::$jobs['merge-platform-runtime-acceptance'];
        self::assertSame(['frankenphp-worker', 'skeleton-create-project-windows', 'native-host-contract-evidence', 'native-host-consumer-cli-evidence'], $platform['needs']);
        self::assertStringContainsString('"native-host-contract-evidence=$NATIVE_HOST_CONTRACT"', $platform['steps'][0]['run']);
        self::assertArrayNotHasKey('local-operator-windows', self::$jobs, 'The contract matrix replaces ci/local-operator-windows.');
    }

    #[Test]
    public function the_contract_selection_is_exactly_what_the_listed_test_files_declare(): void
    {
        foreach (self::$contract['commands'] as $command) {
            if ($command['kind'] !== 'phpunit') {
                continue;
            }
            $paths = self::selectionPaths($command['argv']);
            $declared = self::declaredTestMethods($paths, \nhe_option_value($command['argv'], '--exclude-filter'));
            $expected = $command['expected_methods'];
            sort($declared);
            sort($expected);

            self::assertNotSame([], $paths, $command['id']);
            self::assertSame($expected, $declared, "{$command['id']}: expected_methods must equal the #[Test] methods its paths select.");
        }
    }

    /**
     * The selection paths of a contract PHPUnit command: every token after
     * the options, i.e. every positional argument.
     *
     * @param list<string> $argv
     *
     * @return list<string>
     */
    private static function selectionPaths(array $argv): array
    {
        $paths = [];
        $valued = ['--log-junit', '--log-otr', '--exclude-filter'];
        for ($position = 2, $count = count($argv); $position < $count; $position++) {
            if (in_array($argv[$position], $valued, true)) {
                $position++;
                continue;
            }
            if (!str_starts_with($argv[$position], '--')) {
                $paths[] = $argv[$position];
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function declaredTestMethods(array $paths, ?string $exclude): array
    {
        $files = [];
        foreach ($paths as $path) {
            $absolute = self::$root . '/' . $path;
            if (is_dir($absolute)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (str_ends_with($file->getFilename(), 'Test.php')) {
                        $files[] = $file->getPathname();
                    }
                }
            } else {
                self::assertFileExists($absolute);
                $files[] = $absolute;
            }
        }

        $methods = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertSame(1, preg_match('/^namespace\s+([^;]+);/m', $source, $namespace), $file);
            self::assertSame(1, preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class), $file);
            preg_match_all('/#\[Test\][^\n]*\n(?:\s*#\[[^\n]*\n)*\s*public function (\w+)\(/', $source, $declared);
            foreach ($declared[1] as $method) {
                $name = $namespace[1] . '\\' . $class[1] . '::' . $method;
                if ($exclude === null || preg_match($exclude, $name) !== 1) {
                    $methods[] = $name;
                }
            }
        }

        return $methods;
    }

    /** @return array<string, mixed> */
    private static function step(string $job, string $name): array
    {
        foreach (self::$jobs[$job]['steps'] as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }
        self::fail("{$job} has no step named {$name}.");
    }

    /** @return array<string, mixed> */
    private static function stepById(string $job, string $id): array
    {
        foreach (self::$jobs[$job]['steps'] as $step) {
            if (($step['id'] ?? null) === $id) {
                return $step;
            }
        }
        self::fail("{$job} has no step with id {$id}.");
    }
}
