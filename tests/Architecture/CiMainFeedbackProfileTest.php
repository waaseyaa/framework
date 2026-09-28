<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class CiMainFeedbackProfileTest extends TestCase
{
    private const FULL_PROFILE_IF = "github.event_name != 'push' || github.ref != 'refs/heads/main'";
    private const FULL_PROFILE_ALWAYS_IF = "always() && (github.event_name != 'push' || github.ref != 'refs/heads/main')";

    /** @var array<string, mixed> */
    private array $workflow;

    protected function setUp(): void
    {
        $parsed = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        self::assertIsArray($parsed);
        $this->workflow = $parsed;
    }

    public function testOrdinaryMainPushesCancelSupersededFeedbackRuns(): void
    {
        self::assertSame(
            "\${{ github.event_name == 'pull_request' || (github.event_name == 'push' && github.ref == 'refs/heads/main') }}",
            $this->workflow['concurrency']['cancel-in-progress'] ?? null,
        );
    }

    public function testExplicitDispatchOnlyOffersTheFullProfile(): void
    {
        self::assertSame(
            [
                'description' => 'Qualification profile',
                'required' => true,
                'default' => 'full',
                'type' => 'choice',
                'options' => ['full'],
            ],
            $this->workflow['on']['workflow_dispatch']['inputs']['profile'] ?? null,
        );
    }

    public function testExpensiveQualificationJobsDoNotRunInTheMainFeedbackProfile(): void
    {
        $ordinaryJobs = [
            'spec-drift',
            'release-pipeline-fixtures',
            'release-evidence',
            'composer-deps-audit',
            'prepare-test-plan',
            'ci-test-shards',
            'prepare-random-order-plan',
            'ci-random-order-shard',
            'ci-package-isolation',
            'mutation-pilot',
            'skeleton-create-project',
            'skeleton-create-project-windows',
            'native-host-contract',
            'packaged-form',
            'site-reference-consumer',
            'fresh-install-boot',
            'site-recipe-provider-activation',
            'bimaaji-skill-resources',
            'site-init-profile-acceptance',
            'cli-health-report',
            'cli-sync-rules',
            'cli-io-consumer-contract',
            'split-artifact-acceptance',
            'studio-alpha-acceptance',
            'core-only-boot',
            'frankenphp-worker',
            'ci-playwright-smoke',
            'frontend-build',
            'release-publish-shape',
        ];

        foreach ($ordinaryJobs as $jobId) {
            self::assertSame(
                self::FULL_PROFILE_IF,
                $this->workflow['jobs'][$jobId]['if'] ?? null,
                $jobId,
            );
        }

        $alwaysJobs = [
            'ci-unit-tests',
            'ci-random-order',
            'ci-coverage',
            'native-host-contract-evidence',
            'native-host-consumer-cli-evidence',
            'merge-source-repository-policy',
            'merge-php-behavior-coverage',
            'merge-random-order',
            'merge-security-authorization',
            'merge-public-package-contracts',
            'merge-consumer-acceptance',
            'merge-browser-acceptance',
            'merge-platform-runtime-acceptance',
            'merge-release-integrity',
        ];

        foreach ($alwaysJobs as $jobId) {
            self::assertSame(
                self::FULL_PROFILE_ALWAYS_IF,
                $this->workflow['jobs'][$jobId]['if'] ?? null,
                $jobId,
            );
        }
    }

    public function testMainFeedbackAndFullQualificationPublishDistinctFailClosedDecisions(): void
    {
        $feedback = $this->workflow['jobs']['main-feedback'] ?? null;
        self::assertIsArray($feedback);
        self::assertSame('ci/main-feedback', $feedback['name'] ?? null);
        self::assertSame(
            "always() && github.event_name == 'push' && github.ref == 'refs/heads/main'",
            $feedback['if'] ?? null,
        );
        self::assertSame([
            'immutable-source-artifact',
            'support-contract',
            'composer-policy',
            'ci-lint',
            'check-dead-code',
            'verify-gates',
            'manifest-conformance',
            'ingestion-defaults',
            'security-defaults',
        ], $feedback['needs'] ?? null);
        self::assertStringContainsString('!= success', $feedback['steps'][0]['run'] ?? '');

        $full = $this->workflow['jobs']['full-qualification'] ?? null;
        self::assertIsArray($full);
        self::assertSame('ci/full-qualification', $full['name'] ?? null);
        self::assertSame(self::FULL_PROFILE_ALWAYS_IF, $full['if'] ?? null);
        self::assertSame([
            'merge-source-repository-policy',
            'merge-php-behavior-coverage',
            'merge-random-order',
            'merge-security-authorization',
            'merge-public-package-contracts',
            'merge-consumer-acceptance',
            'merge-browser-acceptance',
            'merge-platform-runtime-acceptance',
            'merge-release-integrity',
        ], $full['needs'] ?? null);
        self::assertStringContainsString('!= success', $full['steps'][0]['run'] ?? '');
    }

    public function testReleaseCutRequestsAndRequiresFullQualification(): void
    {
        $release = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/release-cut.yml');
        self::assertStringContainsString(
            'gh workflow run ci.yml --ref "release-cut/${VERSION}" -f profile=full',
            $release,
        );
        self::assertStringContainsString(
            'bash bin/wait-for-green-ci "$RELEASE_SHA" 2700 ci.yml ci/full-qualification',
            $release,
        );
    }
}
