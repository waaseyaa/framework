<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the CI/release workflow invariants that cannot be exercised as PHP
 * behavior: blocking architecture coverage, immutable action references, and
 * a green-CI/checksum gate before release fan-out.
 */
#[CoversNothing]
final class CiReleaseWorkflowParityTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function blocking_ci_runs_architecture_and_the_pl008_meta_test(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');

        self::assertStringContainsString('php bin/build-phpunit-shards', $ci);
        self::assertStringContainsString('tests/Architecture', (string) file_get_contents($this->repoRoot . '/phpunit.xml.dist'));
        self::assertStringContainsString('check-package-layers-pl008-self-test', $ci);
    }

    #[Test]
    public function security_sensitive_php_and_release_actions_are_sha_pinned(): void
    {
        $workflows = glob($this->repoRoot . '/.github/workflows/*.yml');
        self::assertNotFalse($workflows);

        $matches = 0;
        foreach ($workflows as $workflow) {
            $contents = (string) file_get_contents($workflow);
            preg_match_all(
                '/uses:\s+(shivammathur\/setup-php|softprops\/action-gh-release)@([^\s#]+)/',
                $contents,
                $references,
                PREG_SET_ORDER,
            );

            foreach ($references as [, $action, $revision]) {
                ++$matches;
                self::assertMatchesRegularExpression(
                    '/^[0-9a-f]{40}$/',
                    $revision,
                    sprintf('%s must use an immutable full commit SHA in %s.', $action, $workflow),
                );
            }
        }

        self::assertGreaterThan(0, $matches, 'Expected to find setup-php/action-gh-release uses to audit.');
    }

    #[Test]
    public function split_fan_out_requires_green_ci_for_the_exact_tagged_sha(): void
    {
        $split = $this->read('.github/workflows/split.yml');
        $gateStart = strpos($split, '  verify-ci-green:');
        $splitStart = strpos($split, '  split:');

        self::assertNotFalse($gateStart, 'split.yml must define the fail-closed verify-ci-green job.');
        self::assertNotFalse($splitStart, 'split.yml must define the split fan-out job.');
        self::assertLessThan($splitStart, $gateStart, 'CI verification must be declared before fan-out.');

        $gate = substr($split, $gateStart, $splitStart - $gateStart);
        self::assertStringContainsString('ref: ${{ github.sha }}', $gate);
        self::assertStringContainsString('bash bin/wait-for-green-ci "${SHA}" 2700', $gate);

        $greenCiGate = $this->read('bin/wait-for-green-ci');
        // The polled workflow is now a parameter so release-cut.yml can also gate
        // on Release Readiness. The property this guards is unchanged: the run is
        // selected by EXACT head_sha, and the default is still ci.yml, so every
        // pre-existing two-argument call site keeps its original meaning.
        self::assertStringContainsString('WORKFLOW="${3:-ci.yml}"', $greenCiGate);
        self::assertStringContainsString('actions/workflows/${WORKFLOW}/runs?head_sha=${SHA}', $greenCiGate);
        self::assertStringContainsString('if [ "$conclusion" = "success" ]', $greenCiGate);
        self::assertStringContainsString('if [ "$TIMEOUT" = "0" ]', $greenCiGate);

        $splitJob = substr($split, $splitStart, 300);
        self::assertMatchesRegularExpression('/needs:\s+verify-ci-green/', $splitJob);
    }

    #[Test]
    public function splitsh_archive_is_verified_before_extraction(): void
    {
        $split = $this->read('.github/workflows/split.yml');
        $download = strpos($split, 'lite_linux_amd64.tar.gz');
        $checksum = strpos($split, '2539301ce5e21d0ca44b689d0dd2c1b20d9f9e996c1fe6c462afb8af4e7141cc');
        $verify = strpos($split, 'sha256sum --check');
        $extract = strpos($split, 'tar -xzf');

        self::assertNotFalse($download);
        self::assertNotFalse($checksum, 'The v1.0.1 Linux release archive must have its pinned SHA-256.');
        self::assertNotFalse($verify);
        self::assertNotFalse($extract);
        self::assertLessThan($verify, $checksum, 'Checksum declaration must precede verification.');
        self::assertLessThan($extract, $verify, 'Checksum verification must precede archive extraction.');
    }

    #[Test]
    public function release_commit_stages_every_file_mutated_by_internal_version_sync(): void
    {
        $release = $this->read('.github/workflows/release-cut.yml');

        self::assertStringContainsString(
            'git add CHANGELOG.md VERSION composer.lock packages/*/composer.json skeleton/composer.json',
            $release,
        );
    }

    /**
     * The cut must gate on every proof the repository has, at the exact commit.
     *
     * `release-cut.yml` predates the sharded CI and Release Readiness work and
     * had drifted out of contact with both: it waited only on `ci.yml`, never
     * dispatched the readiness sweep, and ran PHP with no `setup-php` step at
     * all while a stale comment claimed one existed. Each assertion below marks
     * a way a tag could previously be created with less proof than the
     * repository is capable of producing.
     */
    #[Test]
    public function release_cut_gates_the_exact_commit_on_every_available_proof(): void
    {
        $release = $this->read('.github/workflows/release-cut.yml');

        // PHP is provisioned explicitly, not inherited from the runner image.
        self::assertStringContainsString('uses: shivammathur/setup-php@', $release);
        self::assertStringContainsString("php-version: '8.5'", $release);
        self::assertStringNotContainsString('after the setup-php steps above', $release);

        // Gate 2: the suite, on the exact release SHA.
        self::assertStringContainsString('gh workflow run ci.yml --ref "release-cut/${VERSION}"', $release);
        self::assertStringContainsString('bash bin/wait-for-green-ci "$RELEASE_SHA" 2700', $release);

        // Gate 3: Release Readiness, on that same exact SHA.
        self::assertStringContainsString('gh workflow run release.yml --ref "release-cut/${VERSION}" -f sha="$RELEASE_SHA"', $release);
        self::assertStringContainsString('bash bin/wait-for-green-ci "$RELEASE_SHA" 3600 release.yml', $release);

        // Gate 4: the complete inventory in ONE unsharded random-order process.
        // A shard only randomises within itself, so the sharded proof cannot
        // observe a cross-shard ordering dependency.
        self::assertStringContainsString('php bin/test-random-order', $release);
        self::assertStringNotContainsString('php bin/test-random-order --plan', $release);

        // Every gate precedes the irreversible step.
        $tagStep = strpos($release, 'Tag the gated commit and fast-forward main');
        self::assertIsInt($tagStep);
        foreach ([
            'gh workflow run ci.yml',
            'gh workflow run release.yml',
            'php bin/test-random-order',
        ] as $gate) {
            self::assertLessThan(
                $tagStep,
                strpos($release, $gate),
                "$gate must run before the tag is created",
            );
        }
    }

    #[Test]
    public function release_readiness_is_exact_sha_manual_and_has_no_deployment_authority(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');
        $candidateScript = $this->read('scripts/build-release-candidate.sh');

        self::assertStringNotContainsString('  push:', $workflow);
        self::assertStringContainsString('  workflow_dispatch:', $workflow);
        self::assertStringContainsString('sha:', $workflow);
        self::assertStringContainsString('Validate explicit readiness request', $workflow);
        self::assertStringContainsString('^[0-9a-f]{40}$', $workflow);
        self::assertStringContainsString('git merge-base --is-ancestor "$CANDIDATE_SHA" origin/main', $workflow);
        // Acceptance widened to a release-cut/* gate tip so the readiness sweep can
        // verify the exact commit BEFORE main advances. Narrow by construction: only
        // a branch tip in that namespace, which only release-cut.yml creates.
        self::assertStringContainsString('refs/remotes/origin/release-cut/*', $workflow);
        // Checkout uses the VALIDATED sha, not the raw dispatch input, so the
        // validation job is load-bearing rather than decorative.
        self::assertStringContainsString('ref: ${{ needs.validate-readiness-request.outputs.candidate_sha }}', $workflow);
        self::assertStringNotContainsString('ref: ${{ inputs.sha }}', $workflow);
        self::assertStringContainsString('bash scripts/build-release-candidate.sh', $workflow);
        self::assertStringContainsString('permissions:', $workflow);
        self::assertStringContainsString('contents: read', $workflow);
        self::assertStringNotContainsString('environment:', $workflow);
        self::assertStringNotContainsString('scripts/deploy.sh', $workflow);
        self::assertStringNotContainsString('scripts/rollback.sh', $workflow);
        self::assertStringNotContainsString('Promote to production', $workflow);
        self::assertStringNotContainsString('Create incident issue', $workflow);
        self::assertFileDoesNotExist($this->repoRoot . '/scripts/deploy.sh');
        self::assertFileDoesNotExist($this->repoRoot . '/scripts/rollback.sh');

        self::assertStringContainsString('composer install', $candidateScript);
        self::assertStringContainsString('--no-dev', $candidateScript);
        self::assertStringContainsString('required_node_major=', $candidateScript);
        self::assertStringContainsString('deployment_performed', $candidateScript);
        self::assertStringContainsString('npm run build', $candidateScript);
        self::assertStringNotContainsString('|| true', $candidateScript);
        self::assertStringNotContainsString('|| echo', $candidateScript);
    }

    #[Test]
    public function exact_release_gate_installs_the_advanced_skeleton_from_the_checked_out_source(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');
        $start = strpos($ci, '      - name: create-project (path skeleton) and audit-site');
        $end = strpos($ci, '      - name: Fresh skeleton preserves', $start === false ? 0 : $start);

        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $step = substr($ci, $start, $end - $start);

        self::assertStringContainsString('SOURCE_SKELETON: "${{', $step);
        self::assertStringContainsString("startsWith(github.ref_name, 'release-cut/')", $step);
        self::assertStringContainsString("github.event_name == 'push'", $step);
        self::assertStringContainsString("github.ref_name == 'main'", $step);
        self::assertStringContainsString("startsWith(github.event.head_commit.message, 'chore: release ')", $step);
        self::assertStringContainsString('if [[ "$SOURCE_SKELETON" == "true" ]]; then', $step);
        self::assertStringContainsString('--no-install --no-scripts', $step);
        self::assertStringContainsString('repositories.framework path "$GITHUB_WORKSPACE"', $step);
        self::assertStringContainsString('repositories.packages path "$GITHUB_WORKSPACE/packages/*"', $step);
        self::assertStringContainsString('waaseyaa/framework:dev-main --no-update', $step);
        self::assertStringContainsString('run-script --working-dir="$work/skel-proj" post-create-project-cmd', $step);
        self::assertStringContainsString('else', $step, 'Ordinary PR/main CI must retain the published-release create-project path.');
        self::assertStringContainsString('./bin/maintenance/waaseyaa-audit-site', $step);
    }

    private function read(string $relativePath): string
    {
        $path = $this->repoRoot . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
