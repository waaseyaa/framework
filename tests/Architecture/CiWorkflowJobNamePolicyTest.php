<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Holds the stable GitHub Actions job-name contract from #3094.
 */
#[CoversNothing]
final class CiWorkflowJobNamePolicyTest extends TestCase
{
    #[Test]
    public function every_workflow_job_has_an_explicit_name(): void
    {
        foreach ($this->inventory()['workflows'] as $workflow) {
            foreach ($workflow['jobs'] as $job) {
                $locator = $workflow['file'] . '#' . $job['key'];
                self::assertNotNull($job['name']['raw'] ?? null, $locator);
                self::assertStringNotContainsString('default-', $job['name']['derivation'] ?? '', $locator);
            }
        }
    }

    #[Test]
    public function resolvable_visible_contexts_are_unique_across_workflows(): void
    {
        $owners = [];
        foreach ($this->inventory()['workflows'] as $workflow) {
            foreach ($workflow['jobs'] as $job) {
                foreach ($job['contexts'] as $context) {
                    if (!is_string($context['context'] ?? null)) {
                        continue;
                    }

                    $owners[$context['context']][$workflow['file']] = true;
                }
            }
        }

        foreach ($owners as $context => $workflows) {
            self::assertCount(1, $workflows, $context . ': ' . implode(', ', array_keys($workflows)));
        }
    }

    /** @return array<string, mixed> */
    private function inventory(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/tools/ci-workflow-inventory.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
