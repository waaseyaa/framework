<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Field\Classification\Job\HoldScanJob;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\JobTestEnvironment;

require_once __DIR__ . '/Support/JobTestEnvironment.php';

#[CoversClass(HoldScanJob::class)]
final class HoldScanJobTest extends TestCase
{
    use JobTestEnvironment;

    #[Test]
    public function flags_entities_matched_by_both_hold_and_purge_policies(): void
    {
        $held = $this->makeEntity('node', 1, [
            'uuid' => 'u-conflict',
            'classification_label' => 'hold-legal',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $held]);
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->makePolicy(10, [
                'action' => RetentionPolicy::ACTION_HOLD_FLAG,
                'applies_to' => ['hold-*'],
                'trigger_value' => '',
                'exemptions' => [],
            ]),
            20 => $this->makePolicy(20, [
                'action' => RetentionPolicy::ACTION_PURGE,
                'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
                'applies_to' => ['hold-legal'],
                'trigger_value' => 'P1D',
                'exemptions' => [],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new HoldScanJob($etm, $audit)->run();

        $conflicts = array_values(array_filter(
            $audit->recorded,
            static fn($d): bool => $d->kind === AuditEventKind::ClassificationChange
                && ($d->attributes['conflict'] ?? null) === 'hold_vs_purge',
        ));
        self::assertCount(1, $conflicts);
        self::assertSame('u-conflict', $conflicts[0]->entityUuid);
        self::assertSame(10, $conflicts[0]->attributes['hold_policy_id']);
        self::assertSame(20, $conflicts[0]->attributes['purge_policy_id']);
        self::assertSame('warning', $conflicts[0]->severity);
    }

    #[Test]
    public function does_not_flag_when_only_hold_policy_matches(): void
    {
        $held = $this->makeEntity('node', 1, [
            'uuid' => 'u-1',
            'classification_label' => 'hold-legal',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $held]);
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->makePolicy(10, [
                'action' => RetentionPolicy::ACTION_HOLD_FLAG,
                'applies_to' => ['hold-*'],
                'trigger_value' => '',
                'exemptions' => [],
            ]),
            // A purge policy that does NOT match the held label.
            20 => $this->makePolicy(20, [
                'action' => RetentionPolicy::ACTION_PURGE,
                'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
                'applies_to' => ['internal'],
                'trigger_value' => 'P1D',
                'exemptions' => [],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new HoldScanJob($etm, $audit)->run();

        self::assertSame([], $audit->recorded, 'no overlapping purge policy → no conflict');
    }

    #[Test]
    public function does_nothing_when_no_purge_policies_exist(): void
    {
        $held = $this->makeEntity('node', 1, [
            'uuid' => 'u-1',
            'classification_label' => 'hold-legal',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $held]);
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->makePolicy(10, [
                'action' => RetentionPolicy::ACTION_HOLD_FLAG,
                'applies_to' => ['hold-*'],
                'trigger_value' => '',
                'exemptions' => [],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new HoldScanJob($etm, $audit)->run();

        self::assertSame([], $audit->recorded);
    }
}
