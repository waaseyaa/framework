<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $this->assertSame(1, $this->manifest['schema_version'] ?? null);
        $this->assertIsArray($this->manifest['gates']);
        $this->assertNotSame([], $this->manifest['gates']);

        $ids = array_column($this->manifest['gates'], 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'Gate ids must be unique.');

        foreach ($this->manifest['gates'] as $gate) {
            foreach (['id', 'run', 'repair', 'profile', 'enforced_by'] as $key) {
                $this->assertArrayHasKey($key, $gate, sprintf('Gate %s must declare %s.', $gate['id'] ?? '?', $key));
            }
            $this->assertContains($gate['profile'], ['default', 'full'], $gate['id']);
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
            'check-composer-policy', 'check-package-layers',
            // support/s1-contract CI job + the remaining S1 roster gates
            'check-support-contract', 'check-s1-sqlite-contract',
            'check-s1-configuration-activation', 'check-s1-configuration-authority',
            'check-s1-schema-authority',
            'check-delivery-agent-events',
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
        // the failure, echoes the repair line, and still exits non-zero.
        $manifestPath = tempnam(sys_get_temp_dir(), 'preflight-manifest-');
        self::assertNotFalse($manifestPath);
        try {
            file_put_contents($manifestPath, json_encode([
                'schema_version' => 1,
                'gates' => [
                    ['id' => 'always-green', 'run' => 'true', 'repair' => 'n/a', 'profile' => 'default', 'enforced_by' => 'workflow:ci.yml'],
                    ['id' => 'always-red', 'run' => 'echo broken; false', 'repair' => 'run the-exact-repair-command', 'profile' => 'default', 'enforced_by' => 'workflow:ci.yml'],
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
        } finally {
            @unlink($manifestPath);
        }
    }
}
