<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class CiStableAggregateShadowTest extends TestCase
{
    /**
     * @var array<string, array{context: string, invariant: string, prerequisites: list<string>}>
     */
    private const SHADOWS = [
        'merge-source-repository-policy' => [
            'context' => 'merge/source-repository-policy',
            'invariant' => 'source-repository-policy',
            'prerequisites' => ['manifest-conformance', 'check-dead-code', 'ci-lint', 'verify-gates', 'composer-policy'],
        ],
        'merge-php-behavior-coverage' => [
            'context' => 'merge/php-behavior-and-coverage',
            'invariant' => 'php-behavior-coverage',
            'prerequisites' => ['ci-coverage', 'ci-unit-tests', 'mutation-pilot'],
        ],
        'merge-random-order' => [
            'context' => 'merge/random-order',
            'invariant' => 'php-behavior-coverage',
            'prerequisites' => ['ci-random-order'],
        ],
        'merge-security-authorization' => [
            'context' => 'merge/security-authorization',
            'invariant' => 'security-authorization',
            'prerequisites' => ['security-defaults'],
        ],
        'merge-public-package-contracts' => [
            'context' => 'merge/public-package-contracts',
            'invariant' => 'public-package-contracts',
            'prerequisites' => ['frontend-build', 'packaged-form', 'ci-package-isolation'],
        ],
        'merge-consumer-acceptance' => [
            'context' => 'merge/consumer-acceptance',
            'invariant' => 'consumer-acceptance',
            'prerequisites' => ['ingestion-defaults', 'core-only-boot', 'skeleton-create-project', 'fresh-install-boot', 'bimaaji-skill-resources'],
        ],
        'merge-browser-acceptance' => [
            'context' => 'merge/browser-acceptance',
            'invariant' => 'browser-acceptance',
            'prerequisites' => ['ci-playwright-smoke'],
        ],
        'merge-platform-runtime-acceptance' => [
            'context' => 'merge/platform-runtime-acceptance',
            'invariant' => 'platform-runtime-acceptance',
            'prerequisites' => ['frankenphp-worker', 'skeleton-create-project-windows', 'native-host-contract-evidence'],
        ],
        'merge-release-integrity' => [
            'context' => 'merge/release-integrity',
            'invariant' => 'release-integrity',
            'prerequisites' => ['release-publish-shape'],
        ],
    ];

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function stable_merge_decisions_fail_closed_over_the_current_required_roster(): void
    {
        $workflow = Yaml::parseFile($this->repoRoot . '/.github/workflows/ci.yml');
        self::assertIsArray($workflow);
        $jobs = $workflow['jobs'] ?? null;
        self::assertIsArray($jobs);

        $covered = [];
        foreach (self::SHADOWS as $jobId => $expected) {
            $job = $jobs[$jobId] ?? null;
            self::assertIsArray($job, $jobId);
            self::assertSame($expected['context'], $job['name'] ?? null, $jobId);
            self::assertSame('ubuntu-24.04', $job['runs-on'] ?? null, $jobId);
            self::assertSame(5, $job['timeout-minutes'] ?? null, $jobId);
            self::assertSame([], $job['permissions'] ?? null, $jobId);
            self::assertSame($expected['prerequisites'], $job['needs'] ?? null, $jobId);
            self::assertSame('always()', $job['if'] ?? null, $jobId);

            $steps = $job['steps'] ?? null;
            self::assertIsArray($steps, $jobId);
            self::assertCount(1, $steps, $jobId);
            $environment = $steps[0]['env'] ?? null;
            self::assertIsArray($environment, $jobId);
            self::assertCount(count($expected['prerequisites']), $environment, $jobId);
            $run = $steps[0]['run'] ?? null;
            self::assertIsString($run, $jobId);

            foreach ($expected['prerequisites'] as $prerequisite) {
                self::assertContains('${{ needs.' . $prerequisite . '.result }}', array_values($environment), $jobId);
                self::assertStringContainsString($prerequisite . '=', $run, $jobId);
                self::assertStringContainsString('= success', $run, $jobId);
                $covered[] = $prerequisite;
            }
        }

        // The 22 legacy required contexts plus ci/native-host-contract (#2678),
        // added behind the existing merge/platform-runtime-acceptance decision.
        self::assertCount(23, $covered);
        self::assertCount(23, array_unique($covered));
    }

    #[Test]
    public function manifest_records_the_candidate_projection_without_changing_live_required_contexts(): void
    {
        $manifest = json_decode(
            (string) file_get_contents($this->repoRoot . '/tools/ci-check-roster.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);

        $required = $manifest['policy']['required_projection']['contexts'] ?? null;
        self::assertIsArray($required);
        self::assertCount(9, $required);
        $requiredNames = array_column($required, 'context');
        $inventory = json_decode(
            (string) file_get_contents($this->repoRoot . '/tools/ci-workflow-inventory.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $contextByJob = [];
        foreach ($inventory['workflows'] ?? [] as $workflow) {
            if (($workflow['file'] ?? null) !== 'ci.yml') {
                continue;
            }
            foreach ($workflow['jobs'] ?? [] as $job) {
                if (count($job['contexts'] ?? []) === 1 && is_string($job['contexts'][0]['context'] ?? null)) {
                    $contextByJob[$job['key']] = $job['contexts'][0]['context'];
                }
            }
        }
        $owners = [];
        foreach ($manifest['policy']['invariants'] ?? [] as $invariant) {
            $owners[$invariant['id']] = $invariant['owner'];
        }

        $shadow = $manifest['policy']['stable_aggregate_interface'] ?? null;
        self::assertIsArray($shadow);
        self::assertSame('required', $shadow['status'] ?? null);
        self::assertSame(15368, $shadow['integration_id'] ?? null);
        self::assertSame(7, $shadow['ruleset_migration_task'] ?? null);

        $contexts = $shadow['contexts'] ?? null;
        self::assertIsArray($contexts);
        self::assertCount(count(self::SHADOWS), $contexts);

        $prerequisiteContexts = [];
        $interfaceContexts = [];
        foreach (self::SHADOWS as $jobId => $expected) {
            $entry = $contexts[$jobId] ?? null;
            self::assertIsArray($entry, $jobId);
            self::assertSame($expected['context'], $entry['context'] ?? null, $jobId);
            self::assertSame($expected['invariant'], $entry['invariant'] ?? null, $jobId);
            self::assertSame($expected['prerequisites'], $entry['prerequisite_jobs'] ?? null, $jobId);
            self::assertSame(
                array_map(static fn(string $job): string => $contextByJob[$job], $expected['prerequisites']),
                $entry['prerequisite_contexts'] ?? null,
                $jobId,
            );
            self::assertSame($owners[$expected['invariant']], $entry['failure_owner'] ?? null, $jobId);
            self::assertTrue($entry['required'] ?? false, $jobId);
            self::assertContains($entry['context'], $requiredNames, $jobId);
            array_push($prerequisiteContexts, ...$entry['prerequisite_contexts']);
            $interfaceContexts[] = $entry['context'];
        }

        sort($requiredNames);
        sort($interfaceContexts);
        self::assertSame($requiredNames, $interfaceContexts);
        self::assertCount(23, array_unique($prerequisiteContexts));
    }

    #[Test]
    public function generated_inventory_classifies_every_shadow_as_a_fail_closed_aggregate(): void
    {
        $inventory = json_decode(
            (string) file_get_contents($this->repoRoot . '/tools/ci-workflow-inventory.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($inventory);

        $jobs = [];
        foreach ($inventory['workflows'] ?? [] as $workflow) {
            if (($workflow['file'] ?? null) === 'ci.yml') {
                foreach ($workflow['jobs'] ?? [] as $job) {
                    $jobs[$job['key']] = $job;
                }
            }
        }

        foreach (self::SHADOWS as $jobId => $expected) {
            $job = $jobs[$jobId] ?? null;
            self::assertIsArray($job, $jobId);
            self::assertSame('aggregate', $job['structural_role'] ?? null, $jobId);
            $sortedPrerequisites = $expected['prerequisites'];
            sort($sortedPrerequisites);
            self::assertSame($sortedPrerequisites, $job['needs'] ?? null, $jobId);
            self::assertSame($sortedPrerequisites, $job['aggregate']['result_checked_prerequisites'] ?? null, $jobId);
            self::assertSame('always', $job['aggregate']['gate'] ?? null, $jobId);
        }
    }

    #[Test]
    public function shadow_steps_reject_every_non_success_terminal_state(): void
    {
        $workflow = Yaml::parseFile($this->repoRoot . '/.github/workflows/ci.yml');
        self::assertIsArray($workflow);

        foreach (array_keys(self::SHADOWS) as $jobId) {
            $step = $workflow['jobs'][$jobId]['steps'][0] ?? null;
            self::assertIsArray($step, $jobId);
            $run = $step['run'] ?? null;
            $environment = $step['env'] ?? null;
            self::assertIsString($run, $jobId);
            self::assertIsArray($environment, $jobId);

            $states = array_fill_keys(array_keys($environment), 'success');
            $success = new Process([$this->bash(), '-c', $run], $this->repoRoot, $states);
            $success->run();
            self::assertTrue($success->isSuccessful(), $jobId . ': ' . $success->getErrorOutput());

            $first = array_key_first($states);
            self::assertIsString($first);
            foreach (['failure', 'cancelled', 'skipped', ''] as $terminalState) {
                $failureStates = $states;
                $failureStates[$first] = $terminalState;
                $failure = new Process([$this->bash(), '-c', $run], $this->repoRoot, $failureStates);
                $failure->run();
                self::assertFalse($failure->isSuccessful(), sprintf('%s accepted terminal state %s', $jobId, $terminalState === '' ? 'missing' : $terminalState));
            }
        }
    }

    private function bash(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'bash';
        }

        $gitBash = 'C:/Program Files/Git/bin/bash.exe';
        self::assertFileExists($gitBash, 'Tests on Windows must use Git for Windows Bash, not WSL Bash.');

        return $gitBash;
    }
}
