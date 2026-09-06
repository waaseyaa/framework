<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\Tooling\DistributionExclusionPolicy;

require_once dirname(__DIR__, 2) . '/tools/lib/DistributionExclusionPolicy.php';

/**
 * #2648: one canonical exclusion policy governs Docker, deploy-rsync, and git export.
 *
 * The substantive proof is `bin/check-distribution-exclusion`, which compares
 * rendered surfaces to support/distribution-exclusion-policy-v1.json and can
 * run sentinel mutations plus a git archive proof. This class binds the gate to
 * the Architecture suite and hosted CI.
 */
#[CoversNothing]
final class DistributionExclusionGateTest extends TestCase
{
    private const GATE = 'bin/check-distribution-exclusion';

    private const REQUIRED_RSYNC_EXCLUDES = [
        '/.agent/',
        '/.agents/',
        '/.amazonq/',
        '/.augment/',
        '/.claude/',
        '/.codex/',
        '/.cursor/',
        '/.env',
        '/.env.*',
        '/.gemini/',
        '/.kilocode/',
        '/.kiro/',
        '/.mcp.json',
        '/.opencode/',
        '/.qwen/',
        '/.roo/',
        '/.vibe/',
        '/.waaseyaa-golden-sha',
        '/.waaseyaa/',
        '/.windsurf/',
        '/**/.env',
        '/**/.env.*',
        '/**/.mcp.json',
        '/composer.local.json',
        '/storage/*.sqlite',
        '/storage/files/',
        '/support-contract-evidence.json',
    ];

    private string $repoRoot = '';
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_2648_test_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->tmpRoot, 0o755, true));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function the_policy_gate_passes_for_the_repository_surfaces(): void
    {
        $gate = new Process([PHP_BINARY, self::GATE], $this->repoRoot);
        $gate->setTimeout(120.0);
        $gate->run();

        self::assertSame(
            0,
            $gate->getExitCode(),
            "Distribution exclusion surfaces drifted from policy.\n" . $gate->getOutput() . $gate->getErrorOutput(),
        );
        self::assertStringContainsString('OK', $gate->getOutput() . $gate->getErrorOutput());
    }

    #[Test]
    public function the_self_test_sentinels_and_archive_proofs_fail_closed(): void
    {
        $gate = new Process([PHP_BINARY, self::GATE, '--self-test'], $this->repoRoot);
        $gate->setTimeout(180.0);
        $gate->run();

        self::assertSame(
            0,
            $gate->getExitCode(),
            "Distribution exclusion self-test failed.\n" . $gate->getOutput() . $gate->getErrorOutput(),
        );
        self::assertStringContainsString('Self-test sentinels passed', $gate->getOutput());
        self::assertStringContainsString('git archive proof passed', $gate->getOutput());
        self::assertStringContainsString('Composer archive proof passed', $gate->getOutput());
    }

    #[Test]
    public function each_required_deploy_exclusion_is_mandatory(): void
    {
        $policy = DistributionExclusionPolicy::load($this->repoRoot);
        $declared = array_map(
            static fn(string $pattern): string => '/' . ltrim($pattern, '/'),
            $policy->rsyncExcludeAnchoredPatterns(),
        );
        sort($declared, SORT_STRING);

        $expected = self::REQUIRED_RSYNC_EXCLUDES;
        sort($expected, SORT_STRING);
        self::assertSame($expected, $declared);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredDeployExclusionProvider(): iterable
    {
        foreach (self::REQUIRED_RSYNC_EXCLUDES as $pattern) {
            yield $pattern => [$pattern];
        }
    }

    #[Test]
    #[DataProvider('requiredDeployExclusionProvider')]
    public function an_omitted_required_deploy_exclusion_fails(string $pattern): void
    {
        $workflow = $this->completeDeployWorkflow();
        $mutated = str_replace("            --exclude='{$pattern}' \\\n", '', $workflow, $count);
        self::assertSame(1, $count, "Fixture must contain exactly one {$pattern} exclusion.");

        $path = $this->writeWorkflow($mutated);
        $errors = DistributionExclusionPolicy::load($this->repoRoot)->verifyDeployRsyncWorkflows([$path]);

        self::assertNotSame([], $errors, "Omitting {$pattern} must fail.");
        self::assertStringContainsString($pattern, implode("\n", $errors));
    }

    #[Test]
    #[DataProvider('requiredDeployExclusionProvider')]
    public function a_mutated_required_deploy_exclusion_fails(string $pattern): void
    {
        $workflow = $this->completeDeployWorkflow();
        $mutated = str_replace(
            "--exclude='{$pattern}'",
            "--exclude='{$pattern}.mutated'",
            $workflow,
            $count,
        );
        self::assertSame(1, $count, "Fixture must contain exactly one {$pattern} exclusion.");

        $path = $this->writeWorkflow($mutated);
        $errors = DistributionExclusionPolicy::load($this->repoRoot)->verifyDeployRsyncWorkflows([$path]);

        self::assertNotSame([], $errors, "Mutating {$pattern} must fail.");
        self::assertStringContainsString($pattern, implode("\n", $errors));
    }

    #[Test]
    public function the_complete_deploy_fixture_passes(): void
    {
        $errors = DistributionExclusionPolicy::load($this->repoRoot)->verifyDeployRsyncWorkflows([
            $this->repoRoot . '/tests/Fixtures/DistributionExclusion/workflows/complete.yml',
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function the_existing_unanchored_docs_sentinel_still_fails(): void
    {
        $errors = DistributionExclusionPolicy::load($this->repoRoot)->verifyDeployRsyncWorkflows([
            $this->repoRoot . '/tests/Fixtures/DistributionExclusion/workflows/unanchored-docs.yml',
        ]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString("unanchored --exclude='docs/'", implode("\n", $errors));
    }

    #[Test]
    public function export_policy_covers_secret_and_operator_artifacts_while_readmitting_env_examples(): void
    {
        $lines = DistributionExclusionPolicy::load($this->repoRoot)->exportAttributeLines();

        foreach ([
            '.env export-ignore',
            '.env.* export-ignore',
            '**/.env export-ignore',
            '**/.env.* export-ignore',
            'storage/*.sqlite export-ignore',
            'storage/files/ export-ignore',
            '**/storage/*.sqlite export-ignore',
            '**/storage/files/ export-ignore',
        ] as $required) {
            self::assertContains($required, $lines);
        }
        self::assertContains('.env.example -export-ignore', $lines);
        self::assertContains('**/.env.example -export-ignore', $lines);
    }

    #[Test]
    public function appended_wildcard_markdown_export_ignore_is_rejected(): void
    {
        $path = $this->tmpRoot . '/.gitattributes';
        $contents = (string) file_get_contents($this->repoRoot . '/.gitattributes');
        file_put_contents($path, $contents . "\n*.md export-ignore\n");

        $errors = DistributionExclusionPolicy::load($this->repoRoot)->verifyGitattributes($path);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('*.md', implode("\n", $errors));
    }

    #[Test]
    public function self_test_never_rewrites_supplied_surface_files(): void
    {
        $fixtureRoot = $this->tmpRoot . '/read-only-root';
        self::assertTrue(mkdir($fixtureRoot . '/skeleton', 0o755, true));
        self::assertTrue(mkdir($fixtureRoot . '/tests/Fixtures/DistributionExclusion/workflows', 0o755, true));
        copy($this->repoRoot . '/.gitattributes', $fixtureRoot . '/.gitattributes');
        copy($this->repoRoot . '/skeleton/.dockerignore', $fixtureRoot . '/skeleton/.dockerignore');
        copy(
            $this->repoRoot . '/tests/Fixtures/DistributionExclusion/workflows/unanchored-docs.yml',
            $fixtureRoot . '/tests/Fixtures/DistributionExclusion/workflows/unanchored-docs.yml',
        );
        copy(
            $this->repoRoot . '/tests/Fixtures/DistributionExclusion/workflows/complete.yml',
            $fixtureRoot . '/tests/Fixtures/DistributionExclusion/workflows/complete.yml',
        );
        chmod($fixtureRoot . '/.gitattributes', 0o444);
        chmod($fixtureRoot . '/skeleton/.dockerignore', 0o444);
        $beforeAttributes = (string) file_get_contents($fixtureRoot . '/.gitattributes');
        $beforeDockerignore = (string) file_get_contents($fixtureRoot . '/skeleton/.dockerignore');

        $errors = DistributionExclusionPolicy::load($this->repoRoot)->selfTestSentinels(
            $fixtureRoot,
            $this->tmpRoot . '/sentinels',
        );

        self::assertSame([], $errors);
        self::assertSame($beforeAttributes, file_get_contents($fixtureRoot . '/.gitattributes'));
        self::assertSame($beforeDockerignore, file_get_contents($fixtureRoot . '/skeleton/.dockerignore'));
    }

    #[Test]
    public function the_gate_is_wired_into_hosted_ci_and_preflight(): void
    {
        self::assertFileExists($this->repoRoot . '/' . self::GATE);

        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');
        self::assertStringContainsString('run_gate check-distribution-exclusion', $workflow);

        $manifest = json_decode(
            (string) file_get_contents($this->repoRoot . '/tools/preflight-gates.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $ids = array_column($manifest['gates'], 'id');
        self::assertContains('check-distribution-exclusion', $ids);
    }

    #[Test]
    public function skeleton_verify_deploy_rsync_delegates_to_the_policy_gate(): void
    {
        $script = (string) file_get_contents($this->repoRoot . '/skeleton/bin/maintenance/verify-deploy-rsync');
        self::assertStringContainsString('check-distribution-exclusion', $script);
        self::assertStringContainsString('distribution-exclusion-policy', $script);
    }

    #[Test]
    public function approved_docs_paths_are_not_export_ignored(): void
    {
        $attributes = (string) file_get_contents($this->repoRoot . '/.gitattributes');
        self::assertDoesNotMatchRegularExpression(
            '/\bdocs\/\s+export-ignore\b/',
            $attributes,
            'docs/ must remain distributable in the framework export.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\*\.md\s+export-ignore\b/',
            $attributes,
            'Approved markdown must not be reflexively export-ignored.',
        );
    }

    private function completeDeployWorkflow(): string
    {
        return (string) file_get_contents(
            $this->repoRoot . '/tests/Fixtures/DistributionExclusion/workflows/complete.yml',
        );
    }

    private function writeWorkflow(string $contents): string
    {
        $path = $this->tmpRoot . '/workflow.yml';
        file_put_contents($path, $contents);

        return $path;
    }
}
