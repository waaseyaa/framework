<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Binds tools/preflight-gates.json to the surfaces it mirrors
 * (docs/specs/governed-gates.md §1, invariant 1): the manifest cannot drift
 * from CI, because every fast repo-state gate CI blocks on must appear in the
 * manifest, every manifest command must match its composer-alias definition,
 * and every claimed enforcement surface must actually exist.
 */
#[CoversNothing]
final class PreflightParityTest extends TestCase
{
    private string $root;
    /** @var array<string, mixed> */
    private array $manifest;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->manifest = json_decode(
            (string) file_get_contents($this->root . '/tools/preflight-gates.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, array<string, mixed>> id => gate */
    private function gatesById(): array
    {
        $byId = [];
        foreach ($this->manifest['gates'] as $gate) {
            $byId[$gate['id']] = $gate;
        }

        return $byId;
    }

    #[Test]
    public function manifest_is_well_formed(): void
    {
        $this->assertSame(2, $this->manifest['schema_version'] ?? null);
        $this->assertIsArray($this->manifest['gates']);
        $this->assertNotSame([], $this->manifest['gates']);
        $this->assertIsArray($this->manifest['gate_defaults'] ?? null);

        $ids = array_column($this->manifest['gates'], 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'Gate ids must be unique.');

        foreach ($this->manifest['gates'] as $gate) {
            foreach (['id', 'run', 'repair', 'profile', 'enforced_by'] as $key) {
                $this->assertArrayHasKey($key, $gate, sprintf('Gate %s must declare %s.', $gate['id'] ?? '?', $key));
            }
            $this->assertContains($gate['profile'], ['default', 'full'], $gate['id']);
            $effective = array_replace($this->manifest['gate_defaults'], $gate);
            foreach (['supported_hosts', 'required_capabilities', 'relevant_paths', 'cost', 'evidence_inputs'] as $key) {
                $this->assertArrayHasKey($key, $effective, sprintf('Gate %s must resolve %s metadata.', $gate['id'], $key));
            }
            $this->assertContains($effective['cost'], ['fast', 'medium', 'slow'], $gate['id']);
            $this->assertNotSame([], $effective['supported_hosts'], $gate['id']);
            $this->assertNotSame([], $effective['required_capabilities'], $gate['id']);
            $this->assertNotSame([], $effective['relevant_paths'], $gate['id']);
            $this->assertNotSame([], $effective['evidence_inputs'], $gate['id']);
        }
    }

    #[Test]
    public function narrowed_material_selectors_bind_each_gate_implementation_and_configuration(): void
    {
        $gates = $this->gatesById();
        $requiredSelectors = [
            'check-changelog-shape' => ['CHANGELOG.md', 'bin/check-changelog-shape'],
            'check-changelog-fragments' => ['changes/**', 'bin/changelog-fragments'],
            'check-ci-workflow-inventory' => ['.github/workflows/**', 'bin/generate-ci-workflow-inventory', 'bin/lib/ci-workflow-inventory.php', 'tools/ci-workflow-inventory.json'],
            'check-ci-roster-conformance' => ['bin/check-ci-roster-conformance', 'bin/lib/ci-roster-conformance.php', 'tools/ci-check-roster.json', 'tools/ci-workflow-inventory.json'],
            'cs-check' => ['packages/**', 'tests/**', '.php-cs-fixer.dist.php', 'composer.json', 'composer.lock'],
            'phpstan' => ['packages/**', 'tools/PHPStan/**', 'phpstan.neon', 'phpstan-baseline.neon', 'composer.json', 'composer.lock'],
            'check-dead-code' => ['packages/**', 'tools/PHPStan/**', 'bin/check-dead-code', 'phpstan-dead-code.neon', 'phpstan-dead-code-baseline.neon', 'composer.json', 'composer.lock'],
        ];

        foreach ($requiredSelectors as $id => $selectors) {
            self::assertArrayHasKey($id, $gates);
            foreach ($selectors as $selector) {
                self::assertContains($selector, $gates[$id]['relevant_paths'], "{$id} evidence must bind {$selector}.");
            }
        }
    }

    #[Test]
    public function every_ci_verify_gate_alias_is_in_the_manifest(): void
    {
        $ci = (string) file_get_contents($this->root . '/.github/workflows/ci.yml');
        preg_match_all('/^\s*run_gate\s+(\S+)/m', $ci, $matches);
        $this->assertNotSame([], $matches[1], 'ci.yml must contain run_gate lines.');

        $byId = $this->gatesById();
        foreach ($matches[1] as $alias) {
            $this->assertArrayHasKey(
                $alias,
                $byId,
                sprintf('ci/verify-gates runs "%s" but tools/preflight-gates.json omits it — the preflight would be blind to a CI-blocking gate.', $alias),
            );
        }
    }

    #[Test]
    public function core_blocking_gate_families_are_present(): void
    {
        $byId = $this->gatesById();
        foreach ([
            // composer-policy CI job
            'check-composer-policy', 'check-package-layers', 'check-admin-surface-deptrac',
            'check-repo-root-hygiene',
            // support/s1-contract CI job + the remaining S1 roster gates
            'check-support-contract', 'check-s1-sqlite-contract',
            'check-s1-configuration-activation', 'check-s1-configuration-authority',
            'check-s1-schema-authority',
            'check-delivery-agent-events',
            'check-delivery-agent-projection',
            // dedicated fast workflows / jobs
            'surface-parity', 'spec-drift', 'changelog-discipline',
            'check-ingestion-defaults', 'check-no-secrets', 'check-release-publish-shape',
            // style + static analysis (full profile allowed)
            'cs-check', 'phpstan', 'check-dead-code',
            // verify members enforced via Architecture suite
            'check-governed-secret-access', 'check-runtime-policy-custody',
            'check-changelog-shape', 'check-changelog-fragments',
            'check-contract-suite-coverage', 'check-phpunit-skip-policy',
        ] as $id) {
            $this->assertArrayHasKey($id, $byId, sprintf('Manifest must include gate "%s".', $id));
        }
    }

    #[Test]
    public function manifest_commands_match_their_composer_alias_definitions(): void
    {
        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $scripts = $composer['scripts'];

        foreach ($this->manifest['gates'] as $gate) {
            $alias = $gate['alias'] ?? null;
            if ($alias === null) {
                continue;
            }
            $this->assertArrayHasKey($alias, $scripts, sprintf('Gate %s names missing composer alias %s.', $gate['id'], $alias));
            if ($gate['run'] === 'composer ' . $alias) {
                continue;
            }
            $this->assertSame(
                $scripts[$alias],
                $gate['run'],
                sprintf('Gate %s run command must be byte-identical to composer scripts.%s (or invoke "composer %s").', $gate['id'], $alias, $alias),
            );
        }
    }

    #[Test]
    public function every_enforcement_surface_exists(): void
    {
        foreach ($this->manifest['gates'] as $gate) {
            $surface = $gate['enforced_by'];
            if (str_starts_with($surface, 'workflow:')) {
                [$file, $needle] = explode('#', substr($surface, strlen('workflow:')), 2) + [1 => ''];
                $workflowPath = $this->root . '/.github/workflows/' . $file;
                $this->assertFileExists($workflowPath, $gate['id']);
                if ($needle !== '') {
                    $this->assertStringContainsString(
                        $needle,
                        (string) file_get_contents($workflowPath),
                        sprintf('Gate %s claims %s enforces it, but the workflow does not reference "%s".', $gate['id'], $file, $needle),
                    );
                }
                continue;
            }
            if (str_starts_with($surface, 'architecture-test:')) {
                $this->assertFileExists(
                    $this->root . '/tests/Architecture/' . substr($surface, strlen('architecture-test:')),
                    sprintf('Gate %s claims a nonexistent Architecture test enforces it.', $gate['id']),
                );
                continue;
            }
            if ($surface === 'verify-only') {
                // Gate exists only in composer verify (a known CI coverage gap
                // the manifest makes visible instead of hiding).
                $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertContains(
                    '@' . ($gate['alias'] ?? $gate['id']),
                    $composer['scripts']['verify'],
                    sprintf('Gate %s is marked verify-only but is not in composer verify.', $gate['id']),
                );
                continue;
            }
            $this->fail(sprintf('Gate %s has unknown enforcement surface shape: %s', $gate['id'], $surface));
        }
    }

    #[Test]
    public function base_relative_gates_declare_the_placeholder(): void
    {
        foreach ($this->manifest['gates'] as $gate) {
            if (($gate['base'] ?? false) === true) {
                $this->assertStringContainsString('{base}', $gate['run'], $gate['id']);
            } else {
                $this->assertStringNotContainsString('{base}', $gate['run'], $gate['id']);
            }
        }
    }

    #[Test]
    public function preflight_list_mode_prints_every_default_gate(): void
    {
        exec(
            sprintf('php %s --list 2>&1', escapeshellarg($this->root . '/bin/check-pr-preflight')),
            $output,
            $exitCode,
        );
        $this->assertSame(0, $exitCode, implode("\n", $output));

        $joined = implode("\n", $output);
        foreach ($this->manifest['gates'] as $gate) {
            $this->assertStringContainsString($gate['id'], $joined, 'List mode must print every gate (both profiles).');
        }
    }

    #[Test]
    public function preflight_failure_output_names_the_repair_command(): void
    {
        // A guaranteed-failing synthetic gate proves the accumulator reports
        // the failure, echoes the repair line, and still exits non-zero. The
        // gates are PHP one-liners quoted for the host shell (sh or cmd.exe),
        // because the runner executes manifest commands through that shell.
        $manifestPath = tempnam(sys_get_temp_dir(), 'preflight-manifest-');
        self::assertNotFalse($manifestPath);
        try {
            file_put_contents($manifestPath, json_encode([
                'schema_version' => 1,
                'gates' => [
                    ['id' => 'always-green', 'run' => self::phpCommand('exit(0);'), 'repair' => 'n/a', 'profile' => 'default', 'enforced_by' => 'workflow:ci.yml'],
                    ['id' => 'always-red', 'run' => self::phpCommand("echo 'broken'; exit(1);"), 'repair' => 'run the-exact-repair-command', 'profile' => 'default', 'enforced_by' => 'workflow:ci.yml'],
                ],
            ], JSON_THROW_ON_ERROR));

            exec(sprintf(
                'php %s --manifest=%s 2>&1',
                escapeshellarg($this->root . '/bin/check-pr-preflight'),
                escapeshellarg($manifestPath),
            ), $output, $exitCode);

            $joined = implode("\n", $output);
            $this->assertSame(1, $exitCode, $joined);
            $this->assertStringContainsString('always-red', $joined);
            $this->assertStringContainsString('the-exact-repair-command', $joined);
            $this->assertStringContainsString('always-green', $joined);
            // Each gate's verdict must come from its own exit status.
            $this->assertMatchesRegularExpression('/^ok\s+always-green\s/m', $joined);
            $this->assertMatchesRegularExpression('/^FAIL\s+always-red\s.*exit 1\)$/m', $joined);
            $this->assertMatchesRegularExpression('/^\s+broken$/m', $joined);
            $this->assertMatchesRegularExpression('/^failed: always-red$/m', $joined);
        } finally {
            @unlink($manifestPath);
        }
    }

    /** @return iterable<string, array{list<string>, array<string, string|false>, string}> */
    public static function driftBaseSources(): iterable
    {
        yield 'git config waaseyaa.driftBase' => [[], ['WAASEYAA_DRIFT_BASE' => false], 'refs/preflight/configured-base'];
        yield 'WAASEYAA_DRIFT_BASE beats git config' => [[], ['WAASEYAA_DRIFT_BASE' => 'refs/preflight/env-base'], 'refs/preflight/env-base'];
        yield '--base beats WAASEYAA_DRIFT_BASE' => [['--base=refs/preflight/explicit-base'], ['WAASEYAA_DRIFT_BASE' => 'refs/preflight/env-base'], 'refs/preflight/explicit-base'];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string|false> $environment
     */
    #[Test]
    #[DataProvider('driftBaseSources')]
    public function preflight_resolves_the_drift_base_in_documented_precedence(array $arguments, array $environment, string $expected): void
    {
        $this->assertResolvedBase($expected, $arguments, $environment);
    }

    #[Test]
    public function preflight_drift_base_lookup_starts_the_repository_git_entrypoint(): void
    {
        // Every identity lookup must start repository_git_command(): the
        // bin/git adapter on POSIX, which honours WAASEYAA_SYSTEM_GIT, and
        // that pinned executable directly on native Windows. Pinning one that
        // cannot start must fail before a gate runs; a bare `git` would ignore
        // the pin and silently bind another executable.
        $scratch = $this->scratchDirectory();
        try {
            $manifest = $this->writeManifest($scratch, [[
                'id' => 'must-not-run',
                'run' => self::phpCommand('exit(97);'),
            ]]);
            [$exitCode, $stdout, $stderr] = $this->runPreflight($manifest, [
                'WAASEYAA_DRIFT_BASE' => false,
                'WAASEYAA_SYSTEM_GIT' => sys_get_temp_dir() . '/waaseyaa-missing-git-' . bin2hex(random_bytes(6)),
            ]);

            self::assertSame(2, $exitCode, $stdout . $stderr);
            self::assertStringContainsString('cannot bind the repository source identity', $stderr);
            self::assertStringNotContainsString('must-not-run', $stdout);
        } finally {
            new Filesystem()->remove($scratch);
        }
    }

    /**
     * Runs the preflight on a one-gate manifest that prints its {base} and
     * asserts the resolved value. Command-scoped git config (appended after
     * any existing entries) sets waaseyaa.driftBase for the runner's lookup.
     *
     * @param list<string> $arguments
     * @param array<string, string|false> $environment
     */
    private function assertResolvedBase(string $expected, array $arguments, array $environment): void
    {
        $index = (int) (getenv('GIT_CONFIG_COUNT') ?: 0);
        $environment += [
            'GIT_CONFIG_COUNT' => (string) ($index + 1),
            'GIT_CONFIG_KEY_' . $index => 'waaseyaa.driftBase',
            'GIT_CONFIG_VALUE_' . $index => 'refs/preflight/configured-base',
        ];

        $manifestPath = tempnam(sys_get_temp_dir(), 'preflight-manifest-');
        self::assertNotFalse($manifestPath);
        try {
            file_put_contents($manifestPath, json_encode([
                'schema_version' => 1,
                'gates' => [
                    ['id' => 'echo-base', 'base' => true, 'run' => self::phpCommand("echo 'base=', \$argv[1]; exit(1);", '{base}'), 'repair' => 'n/a', 'profile' => 'default', 'enforced_by' => 'workflow:ci.yml'],
                ],
            ], JSON_THROW_ON_ERROR));

            $process = new Process(
                [PHP_BINARY, $this->root . '/bin/check-pr-preflight', '--manifest=' . $manifestPath, ...$arguments],
                $this->root,
                $environment,
                null,
                120,
            );
            $exitCode = $process->run();
            $stdout = $process->getOutput();

            $this->assertSame(1, $exitCode, $stdout . $process->getErrorOutput());
            $this->assertMatchesRegularExpression('/^\s+base=' . preg_quote($expected, '/') . '$/m', $stdout, $process->getErrorOutput());
        } finally {
            @unlink($manifestPath);
        }
    }

    /**
     * #2679: from PowerShell or cmd, a bare `bash` is C:\Windows\System32\bash.exe,
     * the WSL launcher. On native Windows the runner must start a gate whose
     * command begins with `bash` with Git for Windows' Bash
     * (repository_bash_command()), never a PATH `bash`; POSIX hosts keep `bash`
     * from PATH. A `bash` placed first on PATH stands in for the WSL launcher,
     * and the gate's own arguments are paths containing spaces.
     */
    #[Test]
    public function preflight_bash_gates_start_the_host_bash_not_a_path_bash(): void
    {
        $scratch = $this->scratchDirectory();
        try {
            $shadow = $this->shadowBash($scratch);
            $evidence = $scratch . '/uname evidence';
            file_put_contents($scratch . '/probe gate.sh', "uname -s > \"\$1\"\n");
            $manifest = $this->writeManifest($scratch, [
                ['id' => 'host-bash', 'run' => 'bash ' . self::hostPath($scratch . '/probe gate.sh') . ' ' . self::hostPath($evidence)],
            ]);

            [$exitCode, $stdout, $stderr] = $this->runPreflight($manifest, [self::pathKey() => $shadow . PATH_SEPARATOR . (string) getenv('PATH')]);

            self::assertSame(0, $exitCode, $stdout . $stderr);
            self::assertMatchesRegularExpression('/^ok\s+host-bash\s/m', $stdout);
            self::assertFileExists($evidence);
            $system = trim((string) file_get_contents($evidence));
            if (PHP_OS_FAMILY === 'Windows') {
                self::assertFileDoesNotExist($shadow . '/used', 'Native Windows must not start the PATH bash.');
                self::assertMatchesRegularExpression('/^(MINGW|MSYS)/', $system, 'Git for Windows Bash must run the gate.');
            } else {
                self::assertFileExists($shadow . '/used', 'POSIX hosts must keep starting bash from PATH.');
                self::assertNotSame('', $system);
            }
        } finally {
            new Filesystem()->remove($scratch);
        }
    }

    #[Test]
    public function preflight_records_a_missing_capability_as_hosted_required_without_claiming_a_pass(): void
    {
        $scratch = $this->scratchDirectory();
        try {
            $ran = $scratch . '/php gate ran';
            $manifest = $this->writeManifest($scratch, [
                ['id' => 'php-first', 'run' => self::phpCommand("file_put_contents(\$argv[1], 'ran');", self::hostPath($ran))],
                [
                    'id' => 'hosted-only',
                    'run' => self::phpCommand("file_put_contents(\$argv[1], 'must-not-run');", self::hostPath($scratch . '/hosted gate ran')),
                    'required_capabilities' => ['capability-that-does-not-exist'],
                    'owning_hosted_check' => 'ci/hosted-only',
                ],
            ]);

            [$exitCode, $stdout, $stderr] = $this->runPreflight($manifest, []);

            self::assertSame(3, $exitCode, $stdout . $stderr);
            self::assertFileExists($ran, 'Supported gates must still run.');
            self::assertFileDoesNotExist($scratch . '/hosted gate ran');
            self::assertMatchesRegularExpression('/^HOST\s+hosted-only\s/m', $stdout);
            self::assertStringContainsString('ci/hosted-only', $stdout);
            self::assertStringContainsString('This local run is incomplete', $stdout);
            self::assertStringNotContainsString('ok   hosted-only', $stdout);

            [$hookExit, $hookStdout, $hookStderr] = $this->runPreflight($manifest, [], ['--allow-hosted-required']);
            self::assertSame(0, $hookExit, $hookStdout . $hookStderr);
            self::assertStringContainsString('This local run is incomplete', $hookStdout);
        } finally {
            new Filesystem()->remove($scratch);
        }
    }

    #[Test]
    public function preflight_reuses_only_matching_exact_gate_evidence(): void
    {
        $scratch = $this->scratchDirectory();
        try {
            $firstCounter = $scratch . '/first-count';
            $secondCounter = $scratch . '/second-count';
            $increment = static fn(string $path, string $suffix = ''): string => self::phpCommand(
                "\$p=\$argv[1]; \$n=is_file(\$p)?(int)file_get_contents(\$p):0; file_put_contents(\$p,(string)(\$n+1)); {$suffix}",
                self::hostPath($path),
            );
            $manifest = $this->writeManifest($scratch, [
                ['id' => 'first', 'run' => $increment($firstCounter)],
                ['id' => 'second', 'run' => $increment($secondCounter)],
            ]);
            $evidence = $scratch . '/evidence';
            $report = $scratch . '/report.json';
            $arguments = ['--evidence-dir=' . $evidence, '--report-json=' . $report];

            [$firstExit, $firstStdout, $firstStderr] = $this->runPreflight($manifest, [], $arguments);
            self::assertSame(0, $firstExit, $firstStdout . $firstStderr);
            self::assertSame('1', file_get_contents($firstCounter));
            self::assertSame('1', file_get_contents($secondCounter));

            [$secondExit, $secondStdout, $secondStderr] = $this->runPreflight($manifest, [], $arguments);
            self::assertSame(0, $secondExit, $secondStdout . $secondStderr);
            self::assertSame('1', file_get_contents($firstCounter));
            self::assertSame('1', file_get_contents($secondCounter));
            self::assertSame(2, substr_count($secondStdout, 'reused exact identity'));

            $manifest = $this->writeManifest($scratch, [
                ['id' => 'first', 'run' => $increment($firstCounter)],
                ['id' => 'second', 'run' => $increment($secondCounter, '/* gate-version-2 */')],
            ]);
            [$thirdExit, $thirdStdout, $thirdStderr] = $this->runPreflight($manifest, [], $arguments);
            self::assertSame(0, $thirdExit, $thirdStdout . $thirdStderr);
            self::assertSame('1', file_get_contents($firstCounter), 'Unchanged gate evidence must remain reusable.');
            self::assertSame('2', file_get_contents($secondCounter), 'Changed gate identity must execute again.');
            self::assertMatchesRegularExpression('/^ok\s+first\s+\(reused exact identity\)$/m', $thirdStdout);
            self::assertMatchesRegularExpression('/^ok\s+second\s+\([0-9.]+s\)$/m', $thirdStdout);

            $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['reused-exact', 'executed'], array_column($decoded['results'], 'source'));
        } finally {
            new Filesystem()->remove($scratch);
        }
    }

    #[Test]
    public function preflight_reuses_only_gates_whose_selected_bytes_are_equivalent(): void
    {
        $scratch = $this->scratchDirectory();
        $suffix = bin2hex(random_bytes(6));
        $firstInput = $this->root . '/.preflight-material-' . $suffix . '-first';
        $secondInput = $this->root . '/.preflight-material-' . $suffix . '-second';
        try {
            file_put_contents($firstInput, "version one\n");
            file_put_contents($secondInput, "stable\n");
            $firstCounter = $scratch . '/first-count';
            $secondCounter = $scratch . '/second-count';
            $increment = static fn(string $path): string => self::phpCommand(
                '$p=$argv[1]; $n=is_file($p)?(int)file_get_contents($p):0; file_put_contents($p,(string)($n+1));',
                self::hostPath($path),
            );
            $manifest = $this->writeManifest($scratch, [
                ['id' => 'first', 'run' => $increment($firstCounter), 'relevant_paths' => [basename($firstInput)]],
                ['id' => 'second', 'run' => $increment($secondCounter), 'relevant_paths' => [basename($secondInput)]],
            ]);
            $evidence = $scratch . '/evidence';
            $report = $scratch . '/report.json';
            $arguments = ['--evidence-dir=' . $evidence, '--report-json=' . $report];

            [$firstExit, $firstStdout, $firstStderr] = $this->runPreflight($manifest, [], $arguments);
            self::assertSame(0, $firstExit, $firstStdout . $firstStderr);
            file_put_contents($firstInput, "version two\n");

            [$secondExit, $secondStdout, $secondStderr] = $this->runPreflight($manifest, [], $arguments);
            self::assertSame(0, $secondExit, $secondStdout . $secondStderr);
            self::assertSame('2', file_get_contents($firstCounter), 'A changed selected byte must invalidate its gate.');
            self::assertSame('1', file_get_contents($secondCounter), 'Unchanged selected bytes remain reusable.');
            self::assertMatchesRegularExpression('/^ok\s+first\s+\([0-9.]+s\)$/m', $secondStdout);
            self::assertMatchesRegularExpression('/^ok\s+second\s+\(reused equivalent inputs from [^)]+\)$/m', $secondStdout);

            $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['executed', 'reused-equivalent'], array_column($decoded['results'], 'source'));
            self::assertNotSame($decoded['candidate'], $decoded['results'][1]['tested_candidate']);
        } finally {
            @unlink($firstInput);
            @unlink($secondInput);
            new Filesystem()->remove($scratch);
        }
    }

    #[Test]
    public function full_profile_reuses_default_gate_evidence_instead_of_repeating_it(): void
    {
        $scratch = $this->scratchDirectory();
        try {
            $defaultCounter = $scratch . '/default-count';
            $fullCounter = $scratch . '/full-count';
            $increment = static fn(string $path): string => self::phpCommand(
                '$p=$argv[1]; $n=is_file($p)?(int)file_get_contents($p):0; file_put_contents($p,(string)($n+1));',
                self::hostPath($path),
            );
            $manifest = $this->writeManifest($scratch, [
                ['id' => 'default-gate', 'run' => $increment($defaultCounter)],
                ['id' => 'full-gate', 'run' => $increment($fullCounter), 'profile' => 'full'],
            ]);
            $evidence = $scratch . '/evidence';

            [$defaultExit, $defaultStdout, $defaultStderr] = $this->runPreflight($manifest, [], ['--evidence-dir=' . $evidence]);
            self::assertSame(0, $defaultExit, $defaultStdout . $defaultStderr);
            self::assertSame('1', file_get_contents($defaultCounter));
            self::assertFileDoesNotExist($fullCounter);

            [$fullExit, $fullStdout, $fullStderr] = $this->runPreflight($manifest, [], ['--full', '--evidence-dir=' . $evidence]);
            self::assertSame(0, $fullExit, $fullStdout . $fullStderr);
            self::assertSame('1', file_get_contents($defaultCounter));
            self::assertSame('1', file_get_contents($fullCounter));
            self::assertMatchesRegularExpression('/^ok\s+default-gate\s+\(reused exact identity\)$/m', $fullStdout);
            self::assertMatchesRegularExpression('/^ok\s+full-gate\s+\([0-9.]+s\)$/m', $fullStdout);
        } finally {
            new Filesystem()->remove($scratch);
        }
    }

    #[Test]
    public function preflight_reports_unmatched_selectors_as_not_applicable_not_passed(): void
    {
        $scratch = $this->scratchDirectory();
        try {
            $manifest = $this->writeManifest($scratch, [[
                'id' => 'docs-elsewhere',
                'run' => self::phpCommand('exit(97);'),
                'relevant_paths' => ['definitely-not-a-real-directory/**'],
            ]]);
            [$exitCode, $stdout, $stderr] = $this->runPreflight($manifest, []);

            self::assertSame(0, $exitCode, $stdout . $stderr);
            self::assertMatchesRegularExpression('/^N\/A\s+docs-elsewhere\s/m', $stdout);
            self::assertStringNotContainsString('ok   docs-elsewhere', $stdout);
            self::assertStringContainsString('1 not-applicable', $stdout);
        } finally {
            new Filesystem()->remove($scratch);
        }
    }

    private function scratchDirectory(): string
    {
        $scratch = sys_get_temp_dir() . '/waaseyaa preflight bash ' . bin2hex(random_bytes(6));
        new Filesystem()->mkdir($scratch);

        return $scratch;
    }

    /**
     * A `bash` for the front of PATH that records each start. On Windows it
     * stands in for the WSL launcher and fails; on POSIX it runs the real bash.
     */
    private function shadowBash(string $scratch): string
    {
        $shadow = $scratch . '/shadow';
        new Filesystem()->mkdir($shadow);
        if (PHP_OS_FAMILY === 'Windows') {
            file_put_contents($shadow . '/bash.cmd', "@echo off\r\necho used>>\"%~dp0used\"\r\necho PATH bash started 1>&2\r\nexit /b 97\r\n");

            return $shadow;
        }

        $bash = new ExecutableFinder()->find('bash');
        self::assertIsString($bash, 'POSIX hosts need bash on PATH.');
        file_put_contents($shadow . '/bash', "#!/bin/sh\necho used >> " . escapeshellarg($shadow . '/used') . "\nexec " . escapeshellarg($bash) . " \"\$@\"\n");
        chmod($shadow . '/bash', 0o755);

        return $shadow;
    }

    /** @param list<array<string, mixed>> $gates */
    private function writeManifest(string $scratch, array $gates): string
    {
        $manifestPath = $scratch . '/manifest.json';
        file_put_contents($manifestPath, json_encode([
            'schema_version' => 2,
            'gate_defaults' => [
                'supported_hosts' => ['windows', 'linux', 'darwin'],
                'required_capabilities' => ['php', 'git'],
                'relevant_paths' => ['**'],
                'cost' => 'fast',
                'evidence_inputs' => ['relevant_path_bytes', 'composer_lock', 'toolchain', 'gate_definition'],
            ],
            'gates' => array_map(
                static fn(array $gate): array => $gate + ['repair' => 'n/a', 'profile' => 'default', 'enforced_by' => 'workflow:ci.yml'],
                $gates,
            ),
        ], JSON_THROW_ON_ERROR));

        return $manifestPath;
    }

    /**
     * @param array<string, string|false> $environment
     *
     * @return array{int, string, string}
     */
    private function runPreflight(string $manifestPath, array $environment, array $arguments = []): array
    {
        $process = new Process(
            [PHP_BINARY, $this->root . '/bin/check-pr-preflight', '--manifest=' . $manifestPath, ...$arguments],
            $this->root,
            $environment,
            null,
            120,
        );
        $exitCode = $process->run();

        return [(int) $exitCode, $process->getOutput(), $process->getErrorOutput()];
    }

    /** The PATH variable's spelling in this process (Windows keeps `Path`). */
    private static function pathKey(): string
    {
        foreach (array_keys(getenv()) as $name) {
            if (strcasecmp($name, 'PATH') === 0) {
                return $name;
            }
        }

        return 'PATH';
    }

    /** A host-quoted path argument, spelled with `/` so Git for Windows Bash reads it too. */
    private static function hostPath(string $path): string
    {
        return escapeshellarg(str_replace('\\', '/', $path));
    }

    /** Host-quoted `php -r` command; $code must not contain double quotes, % or ! (cmd.exe escaping). */
    private static function phpCommand(string $code, string $arguments = ''): string
    {
        return rtrim(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . $arguments);
    }
}
