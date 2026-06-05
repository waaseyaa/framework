<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Classification\Job\PurgeJob;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\JobTestEnvironment;

require_once __DIR__ . '/Support/JobTestEnvironment.php';

/**
 * NFR-004: a failure while applying one retention policy must not abort the
 * rest of the sweep. Each policy iteration is independently try-catch wrapped.
 */
#[CoversClass(PurgeJob::class)]
final class BestEffortTest extends TestCase
{
    use JobTestEnvironment;

    #[Test]
    public function a_failing_policy_iteration_does_not_abort_the_remaining_policies(): void
    {
        $e1 = $this->makeEntity('node', 1, [
            'uuid' => 'u-1',
            'classification_label' => 'internal',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $e2 = $this->makeEntity('node', 2, [
            'uuid' => 'u-2',
            'classification_label' => 'confidential',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $e3 = $this->makeEntity('node', 3, [
            'uuid' => 'u-3',
            'classification_label' => 'restricted',
            'created_at' => '2000-01-01 00:00:00',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $e1, 2 => $e2, 3 => $e3]);
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->purgePolicy(10, 'internal'),
            20 => $this->purgePolicy(20, 'confidential'),
            30 => $this->purgePolicy(30, 'restricted'),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);

        // Fail on the SECOND audit write (the 'confidential' policy iteration).
        $audit = $this->throwingAuditWriterOnCall(2);

        (new PurgeJob($etm, $audit))->run();

        // All three entities were deleted: deletion precedes the audit write,
        // and the third policy still ran despite the second's failure.
        self::assertSame([], $nodeStorage->all(), 'every matched entity deleted across all three policies');

        // The writer was invoked three times; the 2nd threw, 1st and 3rd recorded.
        self::assertSame(3, $audit->calls);
        self::assertCount(2, $audit->recorded);
        $labels = array_map(static fn($d): string => (string) $d->attributes['label_id'], $audit->recorded);
        self::assertEqualsCanonicalizing(['internal', 'restricted'], $labels);
    }

    private function purgePolicy(int $id, string $label): RetentionPolicy
    {
        return $this->makePolicy($id, [
            'action' => RetentionPolicy::ACTION_PURGE,
            'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
            'applies_to' => [$label],
            'trigger_value' => 'P1D',
            'exemptions' => [],
        ]);
    }
}
