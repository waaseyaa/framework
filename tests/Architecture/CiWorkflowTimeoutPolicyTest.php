<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Holds the explicit GitHub Actions timeout contract from #3090.
 */
#[CoversNothing]
final class CiWorkflowTimeoutPolicyTest extends TestCase
{
    #[Test]
    public function every_workflow_job_has_a_bounded_literal_timeout(): void
    {
        $jobCount = 0;
        foreach (glob(dirname(__DIR__, 2) . '/.github/workflows/*.yml') ?: [] as $file) {
            $workflow = Yaml::parseFile($file);
            self::assertIsArray($workflow, $file);
            self::assertIsArray($workflow['jobs'] ?? null, $file);

            foreach ($workflow['jobs'] as $key => $job) {
                ++$jobCount;
                $timeout = $job['timeout-minutes'] ?? null;
                self::assertIsInt($timeout, basename($file) . '#' . $key);
                self::assertGreaterThanOrEqual(5, $timeout, basename($file) . '#' . $key);
                self::assertLessThanOrEqual(125, $timeout, basename($file) . '#' . $key);
            }
        }

        self::assertGreaterThan(0, $jobCount);
    }

    #[Test]
    public function generated_inventory_records_no_implicit_timeout(): void
    {
        $inventory = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/tools/ci-workflow-inventory.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($inventory['workflows'] as $workflow) {
            foreach ($workflow['jobs'] as $job) {
                $locator = $workflow['file'] . '#' . $job['key'];
                self::assertSame('literal', $job['timeout_minutes']['kind'] ?? null, $locator);
                self::assertIsInt($job['timeout_minutes']['value'] ?? null, $locator);
            }
        }
    }

    #[Test]
    public function long_polling_jobs_keep_budget_specific_limits(): void
    {
        self::assertSame(120, $this->timeout('release-cut.yml', 'cut'));
        self::assertSame(60, $this->timeout('split.yml', 'verify-ci-green'));
        self::assertSame(45, $this->timeout('split.yml', 'verify-packagist'));
        self::assertSame(50, $this->timeout('discord-release.yml', 'notify'));
        self::assertSame(125, $this->timeout('auto-merge.yml', 'enable-auto-merge'));
    }

    private function timeout(string $file, string $job): int
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/' . $file);
        self::assertIsArray($workflow);
        $timeout = $workflow['jobs'][$job]['timeout-minutes'] ?? null;
        self::assertIsInt($timeout);

        return $timeout;
    }
}
