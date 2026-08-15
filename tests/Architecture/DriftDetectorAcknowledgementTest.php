<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DriftDetectorAcknowledgementTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa-drift-detector-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . '/tools', 0o777, true);
        mkdir($this->fixtureRoot . '/packages/entity/src', 0o777, true);
        mkdir($this->fixtureRoot . '/docs/specs', 0o777, true);

        copy(dirname(__DIR__, 2) . '/tools/drift-detector.sh', $this->fixtureRoot . '/tools/drift-detector.sh');
        file_put_contents($this->fixtureRoot . '/packages/entity/src/Example.php', "<?php\nfinal class Example {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/entity-system.md', "# Entity system\n");

        $this->executeCommand('git init --quiet');
        $this->executeCommand('git config gc.auto 0');
        $this->executeCommand('git config user.email test@example.com');
        $this->executeCommand('git config user.name "Drift Detector Test"');
        $this->executeCommand('git add .');
        $this->executeCommand('git commit --quiet -m baseline');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->fixtureRoot);
    }

    #[Test]
    public function accepts_a_backtick_wrapped_commit_trailer(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'fix: non-contract change' -m 'spec-reviewed: `docs/specs/entity-system.md` - no contract change'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString("OK: docs/specs/entity-system.md (acknowledged via 'spec-reviewed:')", $output);
    }

    #[Test]
    public function ignores_pr_body_acknowledgements_because_ci_enforces_commit_trailers(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'fix: unacknowledged contract-bearing change'");

        [$exitCode, $output] = $this->executeCommand(
            "PR_BODY='spec-reviewed: docs/specs/entity-system.md - claimed only in PR body' bash tools/drift-detector.sh HEAD~1",
            allowFailure: true,
        );

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/entity-system.md', $output);
    }

    #[Test]
    public function search_source_is_coupled_to_its_spec(): void
    {
        mkdir($this->fixtureRoot . '/packages/search/src', 0o777, true);
        file_put_contents($this->fixtureRoot . '/packages/search/src/Indexer.php', "<?php\nfinal class Indexer {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/search.md', "# Search\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add search baseline'");
        file_put_contents($this->fixtureRoot . '/packages/search/src/Indexer.php', "<?php\nfinal class Indexer { public const CHANGED = true; }\n");
        $this->executeCommand('git add packages/search/src/Indexer.php');
        $this->executeCommand("git commit --quiet -m 'feat: change search contract'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/search.md', $output);
    }

    #[Test]
    public function admin_surface_source_is_coupled_to_the_admin_spa_spec(): void
    {
        mkdir($this->fixtureRoot . '/packages/admin-surface/src', 0o777, true);
        file_put_contents($this->fixtureRoot . '/packages/admin-surface/src/Host.php', "<?php\nfinal class Host {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/admin-spa.md', "# Admin SPA\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add admin surface baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/admin-surface/src/Host.php',
            "<?php\nfinal class Host { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/admin-surface/src/Host.php');
        $this->executeCommand("git commit --quiet -m 'feat: change admin surface contract'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/admin-spa.md', $output);
        self::assertStringNotContainsString('not mapped to any spec', $output);
    }

    #[Test]
    public function oidc_source_is_coupled_to_the_api_layer_spec(): void
    {
        mkdir($this->fixtureRoot . '/packages/oidc/src', 0o777, true);
        file_put_contents($this->fixtureRoot . '/packages/oidc/src/JwksController.php', "<?php\nfinal class JwksController {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/api-layer.md', "# API Layer\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add oidc baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/oidc/src/JwksController.php',
            "<?php\nfinal class JwksController { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/oidc/src/JwksController.php');
        $this->executeCommand("git commit --quiet -m 'feat: change oidc contract'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/api-layer.md', $output);
        self::assertStringNotContainsString('not mapped to any spec', $output);
    }

    #[Test]
    public function stale_acknowledgement_does_not_forgive_a_later_source_change(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'fix: first change' -m 'spec-reviewed: docs/specs/entity-system.md - reviewed the first change only'");
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED_AGAIN = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'feat: later unreviewed change'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~2', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/entity-system.md', $output);
        self::assertStringContainsString('predates later source change', $output);
    }

    #[Test]
    public function final_empty_commit_acknowledgement_covers_earlier_changes(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'feat: unreviewed change'");
        $this->executeCommand("git commit --quiet --allow-empty -m 'chore: acknowledge drift' -m 'spec-reviewed: docs/specs/entity-system.md - reviewed after the change'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~2', allowFailure: true);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString("OK: docs/specs/entity-system.md (acknowledged via 'spec-reviewed:')", $output);
    }

    #[Test]
    public function blanket_all_acknowledgement_is_rejected_with_a_diagnostic(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'feat: change' -m 'spec-reviewed: all - blanket acknowledgement'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(2, $exitCode, $output);
        self::assertStringContainsString("'spec-reviewed: all' is no longer accepted", $output);
    }

    #[Test]
    public function comma_separated_acknowledgement_registers_each_listed_spec(): void
    {
        mkdir($this->fixtureRoot . '/packages/search/src', 0o777, true);
        file_put_contents($this->fixtureRoot . '/packages/search/src/Indexer.php', "<?php\nfinal class Indexer {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/search.md', "# Search\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add search baseline'");
        file_put_contents($this->fixtureRoot . '/packages/entity/src/Example.php', "<?php\nfinal class Example { public const CHANGED = true; }\n");
        file_put_contents($this->fixtureRoot . '/packages/search/src/Indexer.php', "<?php\nfinal class Indexer { public const CHANGED = true; }\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'feat: change both packages' -m 'spec-reviewed: docs/specs/entity-system.md, docs/specs/search.md - both reviewed'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString("OK: docs/specs/entity-system.md (acknowledged via 'spec-reviewed:')", $output);
        self::assertStringContainsString("OK: docs/specs/search.md (acknowledged via 'spec-reviewed:')", $output);
    }

    #[Test]
    public function acknowledgement_token_naming_no_spec_file_is_diagnosed(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'feat: change' -m 'spec-reviewed: docs/specs/does-not-exist.md - typo'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('names no spec file', $output);
        self::assertStringContainsString('STALE: docs/specs/entity-system.md', $output);
    }

    #[Test]
    public function acknowledgement_token_that_is_not_a_spec_path_is_diagnosed(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'feat: change' -m 'spec-reviewed: SOME-CHANGE-RECORD-ID custody notes'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('is not a spec path', $output);
        self::assertStringContainsString('STALE: docs/specs/entity-system.md', $output);
    }

    #[Test]
    public function acknowledgement_of_an_unaffected_spec_is_diagnosed(): void
    {
        file_put_contents($this->fixtureRoot . '/docs/specs/search.md', "# Search\n");
        $this->executeCommand('git add docs/specs/search.md');
        $this->executeCommand("git commit --quiet -m 'test: add search spec baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'feat: change entity only' -m 'spec-reviewed: docs/specs/entity-system.md, docs/specs/search.md - over-broad acknowledgement'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('not affected by this change set', $output);
    }

    #[Test]
    public function package_migrations_are_contract_bearing(): void
    {
        mkdir($this->fixtureRoot . '/packages/entity/migrations', 0o777, true);
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/migrations/2026_01_01_000001_example.php',
            "<?php\nreturn [];\n",
        );
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add migration baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/migrations/2026_01_01_000001_example.php',
            "<?php\nreturn ['changed' => true];\n",
        );
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'feat: change migration schema'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/entity-system.md', $output);
    }

    #[Test]
    public function package_migration_coupled_with_spec_update_passes(): void
    {
        mkdir($this->fixtureRoot . '/packages/entity/migrations', 0o777, true);
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/migrations/2026_01_01_000001_example.php',
            "<?php\nreturn [];\n",
        );
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add migration baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/migrations/2026_01_01_000001_example.php',
            "<?php\nreturn ['changed' => true];\n",
        );
        file_put_contents($this->fixtureRoot . '/docs/specs/entity-system.md', "# Entity system\n\nMigration documented.\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'feat: change migration schema with spec'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('OK: docs/specs/entity-system.md (updated in this change set)', $output);
    }

    #[Test]
    public function explicit_invalid_base_ref_fails_closed_without_fallback(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'feat: change'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh no-such-ref', allowFailure: true);

        self::assertSame(3, $exitCode, $output);
        self::assertStringContainsString('does not resolve to a commit', $output);
        self::assertStringNotContainsString('falling back', $output);
    }

    #[Test]
    public function legacy_integer_base_beyond_history_fails_closed(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'feat: change'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh 99', allowFailure: true);

        self::assertSame(3, $exitCode, $output);
        self::assertStringContainsString('does not resolve to a commit', $output);
    }

    #[Test]
    public function single_commit_repository_without_a_base_fails_closed(): void
    {
        $this->executeCommand('git branch -m no-base-branch');

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh', allowFailure: true);

        self::assertSame(4, $exitCode, $output);
        self::assertStringContainsString('no base ref available', $output);
    }

    #[Test]
    public function oidc_signing_key_source_couples_to_the_signing_lifecycle_spec(): void
    {
        mkdir($this->fixtureRoot . '/packages/oidc/src/Key', 0o777, true);
        file_put_contents($this->fixtureRoot . '/packages/oidc/src/Key/SigningKeyLifecyclePolicy.php', "<?php\nfinal class SigningKeyLifecyclePolicy {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/api-layer.md', "# API Layer\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/s1-signing-key-lifecycle.md', "# Signing key lifecycle\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add oidc key baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/oidc/src/Key/SigningKeyLifecyclePolicy.php',
            "<?php\nfinal class SigningKeyLifecyclePolicy { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'feat: change signing key lifecycle'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/api-layer.md', $output);
        self::assertStringContainsString('STALE: docs/specs/s1-signing-key-lifecycle.md', $output);
    }

    #[Test]
    public function oidc_rekey_source_couples_to_the_security_defaults_spec(): void
    {
        mkdir($this->fixtureRoot . '/packages/oidc/src/Rekey', 0o777, true);
        file_put_contents($this->fixtureRoot . '/packages/oidc/src/Rekey/OidcAccessTokenRekeyAdapter.php', "<?php\nfinal class OidcAccessTokenRekeyAdapter {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/api-layer.md', "# API Layer\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/security-defaults.md', "# Security defaults\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add oidc rekey baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/oidc/src/Rekey/OidcAccessTokenRekeyAdapter.php',
            "<?php\nfinal class OidcAccessTokenRekeyAdapter { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'feat: change oidc rekey custody'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/api-layer.md', $output);
        self::assertStringContainsString('STALE: docs/specs/security-defaults.md', $output);
    }

    #[Test]
    public function config_manifest_source_couples_to_its_custody_specs(): void
    {
        mkdir($this->fixtureRoot . '/packages/config/src/Manifest', 0o777, true);
        file_put_contents($this->fixtureRoot . '/packages/config/src/Manifest/Signer.php', "<?php\nfinal class Signer {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/config-management.md', "# Config management\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/s1-signing-key-lifecycle.md', "# Signing key lifecycle\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add config manifest baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/config/src/Manifest/Signer.php',
            "<?php\nfinal class Signer { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'feat: change manifest signing'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/entity-system.md', $output);
        self::assertStringContainsString('STALE: docs/specs/config-management.md', $output);
        self::assertStringContainsString('STALE: docs/specs/s1-signing-key-lifecycle.md', $output);
    }

    #[Test]
    public function cli_admin_build_source_couples_to_the_admin_spa_spec(): void
    {
        mkdir($this->fixtureRoot . '/packages/cli/src/AdminBuild', 0o777, true);
        file_put_contents($this->fixtureRoot . '/packages/cli/src/AdminBuild/Pipeline.php', "<?php\nfinal class Pipeline {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/cli-kernel.md', "# CLI kernel\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/admin-spa.md', "# Admin SPA\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/security-defaults.md', "# Security defaults\n");
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add admin build baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/cli/src/AdminBuild/Pipeline.php',
            "<?php\nfinal class Pipeline { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'feat: change hermetic admin build'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/cli-kernel.md', $output);
        self::assertStringContainsString('STALE: docs/specs/admin-spa.md', $output);
        self::assertStringContainsString('STALE: docs/specs/security-defaults.md', $output);
    }

    #[Test]
    public function unmapped_production_package_source_fails_closed(): void
    {
        mkdir($this->fixtureRoot . '/packages/unmapped-contract/src', 0o777, true);
        file_put_contents(
            $this->fixtureRoot . '/packages/unmapped-contract/src/Contract.php',
            "<?php\nfinal class Contract {}\n",
        );
        $this->executeCommand('git add .');
        $this->executeCommand("git commit --quiet -m 'test: add unmapped package baseline'");
        file_put_contents(
            $this->fixtureRoot . '/packages/unmapped-contract/src/Contract.php',
            "<?php\nfinal class Contract { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/unmapped-contract/src/Contract.php');
        $this->executeCommand("git commit --quiet -m 'feat: change unmapped package contract'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('BLOCKED: contract-bearing source changed in package(s) not mapped to any spec:', $output);
        self::assertStringContainsString('1 unmapped package(s) block specification-drift verification.', $output);
    }

    /** @return array{int, string} */
    private function executeCommand(string $command, bool $allowFailure = false): array
    {
        $output = [];
        $exitCode = 0;
        exec('cd ' . escapeshellarg($this->fixtureRoot) . ' && ' . $command . ' 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);

        if (!$allowFailure) {
            self::assertSame(0, $exitCode, "Command failed: {$command}\n{$joined}");
        }

        return [$exitCode, $joined];
    }

    private function removeTree(string $path, int $attempts = 3): void
    {
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            clearstatcache(true, $path);
            if (!is_dir($path)) {
                return;
            }

            $items = @scandir($path);
            if ($items === false) {
                continue;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $child = $path . '/' . $item;
                clearstatcache(true, $child);
                if (!file_exists($child) && !is_link($child)) {
                    continue;
                }
                if (is_dir($child) && !is_link($child)) {
                    $this->removeTree($child, $attempts);
                } else {
                    @unlink($child);
                }
            }

            if (@rmdir($path) || !is_dir($path)) {
                return;
            }
        }

        self::assertDirectoryDoesNotExist($path, 'Disposable drift-detector fixture could not be removed safely.');
    }
}
