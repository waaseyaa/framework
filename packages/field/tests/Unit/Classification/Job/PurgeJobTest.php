<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Field\Classification\Job\PurgeJob;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\JobTestEnvironment;

require_once __DIR__ . '/Support/JobTestEnvironment.php';

#[CoversClass(PurgeJob::class)]
final class PurgeJobTest extends TestCase
{
    use JobTestEnvironment;

    #[Test]
    public function purges_only_age_eligible_matching_entities(): void
    {
        $old = $this->makeEntity('node', 1, [
            'uuid' => 'u-old',
            'classification_label' => 'internal',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $fresh = $this->makeEntity('node', 2, [
            'uuid' => 'u-fresh',
            'classification_label' => 'internal',
            'created_at' => '2999-01-01 00:00:00',
        ]);
        $otherLabel = $this->makeEntity('node', 3, [
            'uuid' => 'u-other',
            'classification_label' => 'public',
            'created_at' => '2000-01-01 00:00:00',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $old, 2 => $fresh, 3 => $otherLabel]);
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->makePolicy(10, [
                'action' => RetentionPolicy::ACTION_PURGE,
                'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
                'applies_to' => ['internal'],
                'trigger_value' => 'P30D',
                'exemptions' => [],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new PurgeJob($etm, $audit)->run();

        // Only the old, internal-labelled, non-exempt entity is deleted.
        self::assertArrayNotHasKey(1, $nodeStorage->all());
        self::assertArrayHasKey(2, $nodeStorage->all(), 'fresh entity survives');
        self::assertArrayHasKey(3, $nodeStorage->all(), 'non-matching label survives');

        $purgeEvents = array_filter(
            $audit->recorded,
            static fn($d): bool => $d->kind === AuditEventKind::RetentionPurge,
        );
        self::assertCount(1, $purgeEvents);
        $event = array_values($purgeEvents)[0];
        self::assertSame('u-old', $event->entityUuid);
        self::assertSame('internal', $event->attributes['label_id']);
        self::assertSame(10, $event->attributes['policy_id']);
    }

    #[Test]
    public function never_purges_legal_hold_labelled_entities_even_when_a_policy_matches(): void
    {
        // A misconfigured purge policy whose glob matches `hold-*` labels must
        // NOT delete legal-hold content — the hold guard overrides the match.
        $held = $this->makeEntity('node', 1, [
            'uuid' => 'u-held',
            'classification_label' => 'hold-legal',
            'created_at' => '2000-01-01 00:00:00',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $held]);
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->makePolicy(10, [
                'action' => RetentionPolicy::ACTION_PURGE,
                'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
                'applies_to' => ['hold-*'],
                'trigger_value' => 'P30D',
                'exemptions' => [],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new PurgeJob($etm, $audit)->run();

        self::assertArrayHasKey(1, $nodeStorage->all(), 'legal-hold entity must never be purged');
        self::assertSame([], $audit->recorded);
    }

    #[Test]
    public function honours_exemptions(): void
    {
        $exempt = $this->makeEntity('node', 1, [
            'uuid' => 'u-exempt',
            'classification_label' => 'internal',
            'created_at' => '2000-01-01 00:00:00',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $exempt]);
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->makePolicy(10, [
                'action' => RetentionPolicy::ACTION_PURGE,
                'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
                'applies_to' => ['internal'],
                'trigger_value' => 'P30D',
                'exemptions' => ['node:u-exempt'],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new PurgeJob($etm, $audit)->run();

        self::assertArrayHasKey(1, $nodeStorage->all(), 'exempt entity must survive');
        self::assertSame([], $audit->recorded);
    }

    #[Test]
    public function glob_applies_to_matches_prefixed_labels(): void
    {
        $nationConf = $this->makeEntity('node', 1, [
            'uuid' => 'u-1',
            'classification_label' => 'nation-confidential',
            'created_at' => '2000-01-01 00:00:00',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $nationConf]);
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->makePolicy(10, [
                'action' => RetentionPolicy::ACTION_PURGE,
                'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
                'applies_to' => ['nation-*'],
                'trigger_value' => 'P1D',
                'exemptions' => [],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);

        new PurgeJob($etm, $this->recordingAuditWriter())->run();

        self::assertArrayNotHasKey(1, $nodeStorage->all());
    }

    #[Test]
    public function ignores_non_age_based_policies(): void
    {
        $entity = $this->makeEntity('node', 1, [
            'uuid' => 'u-1',
            'classification_label' => 'internal',
            'created_at' => '2000-01-01 00:00:00',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $entity]);
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->makePolicy(10, [
                'action' => RetentionPolicy::ACTION_PURGE,
                'trigger_kind' => RetentionPolicy::TRIGGER_EVENT_BASED,
                'applies_to' => ['internal'],
                'trigger_value' => 'audit:access.denied',
                'exemptions' => [],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);

        new PurgeJob($etm, $this->recordingAuditWriter())->run();

        self::assertArrayHasKey(1, $nodeStorage->all(), 'event-based policies are not purged by the age sweep');
    }
}
