<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Job;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Field\Attribute\FieldTemplate;
use Waaseyaa\Field\Classification\Job\HoldScanJob;
use Waaseyaa\Field\Classification\Job\PurgeJob;
use Waaseyaa\Field\Classification\Job\RedactJob;
use Waaseyaa\Field\Classification\Job\RetentionScanner;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\FakeEntity;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\FakeStorage;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\JobTestEnvironment;

require_once __DIR__ . '/Support/JobTestEnvironment.php';

/**
 * Parity tests: verify that the bounded keyset-scan path (small batchSize=2,
 * forcing multiple batch iterations) produces EXACTLY the same set of purged /
 * redacted / conflict-flagged entities as the unbounded pre-change behaviour.
 *
 * Fixture deliberately mixes:
 *   - matching label entities (purged/redacted/flagged)
 *   - non-matching label entity
 *   - hold-* label entity (PurgeJob must skip via hold guard)
 *   - exempt uuid entity
 *   - age-ineligible row (PurgeJob only)
 *   - rows spanning MORE than one batch (batchSize=2)
 */
#[CoversNothing]
final class RetentionScanParityTest extends TestCase
{
    use JobTestEnvironment;

    // -------------------------------------------------------------------------
    // PurgeJob parity
    // -------------------------------------------------------------------------

    #[Test]
    public function purge_job_with_batched_scan_purges_exact_same_set_as_unbounded_path(): void
    {
        // applies_to=['internal', 'hold-*'] → multi-pattern → labelCondition=null.
        // All classified+age-eligible entities reach PHP filters; the hold guard
        // and exemption logic is unchanged — only iteration is bounded.
        $policyStorage = $this->makeStorage('retention_policy', [
            10 => $this->makePolicy(10, [
                'action' => RetentionPolicy::ACTION_PURGE,
                'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
                'applies_to' => ['internal', 'hold-*'],   // multi-pattern → labelCondition=null
                'trigger_value' => 'P30D',
                'exemptions' => ['document:exempt-uuid'],
            ]),
        ]);

        $nodeStorage = $this->makeStorage('document', [
            1 => $this->makeEntity('document', 1, [           // matching label, old — PURGED
                'uuid' => 'uuid-1',
                'classification_label' => 'internal',
                'created_at' => '2000-01-01 00:00:00',
            ]),
            2 => $this->makeEntity('document', 2, [           // matching label, old — PURGED
                'uuid' => 'uuid-2',
                'classification_label' => 'internal',
                'created_at' => '2000-01-01 00:00:00',
            ]),
            3 => $this->makeEntity('document', 3, [           // matching label, old — PURGED
                'uuid' => 'uuid-3',
                'classification_label' => 'internal',
                'created_at' => '2000-01-01 00:00:00',
            ]),
            4 => $this->makeEntity('document', 4, [           // non-matching label — SURVIVES
                'uuid' => 'uuid-4',
                'classification_label' => 'public',
                'created_at' => '2000-01-01 00:00:00',
            ]),
            5 => $this->makeEntity('document', 5, [           // hold-* label — SURVIVES (hold guard)
                'uuid' => 'uuid-5',
                'classification_label' => 'hold-legal',
                'created_at' => '2000-01-01 00:00:00',
            ]),
            6 => $this->makeEntity('document', 6, [           // matching label, exempt uuid — SURVIVES
                'uuid' => 'exempt-uuid',
                'classification_label' => 'internal',
                'created_at' => '2000-01-01 00:00:00',
            ]),
            7 => $this->makeEntity('document', 7, [           // matching label, age-ineligible — SURVIVES
                'uuid' => 'uuid-7',
                'classification_label' => 'internal',
                'created_at' => '2999-01-01 00:00:00',
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'document' => $nodeStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        // Inject a small batchSize=2 to force multiple batch iterations.
        new PurgeJob($etm, $audit, null, new RetentionScanner($etm, 2))->run();

        // Exactly entities 1, 2, 3 are purged.
        $remaining = array_keys($nodeStorage->all());
        sort($remaining);
        self::assertSame([4, 5, 6, 7], $remaining, 'entities 1-3 purged; 4-7 survive');

        $purgeEvents = array_values(array_filter(
            $audit->recorded,
            static fn($d): bool => $d->kind === AuditEventKind::RetentionPurge,
        ));
        self::assertCount(3, $purgeEvents);
        $purgedUuids = array_map(static fn($d): string => $d->entityUuid, $purgeEvents);
        self::assertEqualsCanonicalizing(['uuid-1', 'uuid-2', 'uuid-3'], $purgedUuids);
    }

    // -------------------------------------------------------------------------
    // RedactJob parity
    // -------------------------------------------------------------------------

    #[Test]
    public function redact_job_with_batched_scan_redacts_exact_same_set_as_unbounded_path(): void
    {
        // Single literal pattern → labelCondition = {op:'=', value:'restricted'}.
        $policyStorage = $this->makeStorage('retention_policy', [
            20 => $this->makePolicy(20, [
                'action' => RetentionPolicy::ACTION_REDACT,
                'applies_to' => ['restricted'],
                'trigger_value' => '',
                'exemptions' => ['person:exempt-uuid'],
            ]),
        ]);

        $piiFields = ['ssn' => '123', 'email' => 'a@b.test'];

        $personStorage = new FakeStorage('person', [
            1 => new ParityPiiPerson('person', array_merge($piiFields, [   // REDACTED
                'id' => 1, 'uuid' => 'uuid-1', 'classification_label' => 'restricted',
            ])),
            2 => new ParityPiiPerson('person', array_merge($piiFields, [   // REDACTED
                'id' => 2, 'uuid' => 'uuid-2', 'classification_label' => 'restricted',
            ])),
            3 => new ParityPiiPerson('person', array_merge($piiFields, [   // REDACTED
                'id' => 3, 'uuid' => 'uuid-3', 'classification_label' => 'restricted',
            ])),
            4 => new ParityPiiPerson('person', array_merge($piiFields, [   // non-matching label — SURVIVES
                'id' => 4, 'uuid' => 'uuid-4', 'classification_label' => 'public',
            ])),
            5 => new ParityPiiPerson('person', array_merge($piiFields, [   // exempt uuid — SURVIVES
                'id' => 5, 'uuid' => 'exempt-uuid', 'classification_label' => 'restricted',
            ])),
            6 => $this->makeEntity('person', 6, [                           // no PII fields — SURVIVES unredacted
                'uuid' => 'uuid-6', 'classification_label' => 'restricted',
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'person' => $personStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new RedactJob($etm, $audit, null, new RetentionScanner($etm, 2))->run();

        // Entities 1-3 redacted (PII fields nulled).
        foreach ([1, 2, 3] as $id) {
            $entity = $personStorage->load($id);
            self::assertNotNull($entity);
            self::assertNull($entity->get('ssn'), "entity {$id}: ssn should be nulled");
            self::assertNull($entity->get('email'), "entity {$id}: email should be nulled");
        }

        // Entity 4 (wrong label): never yielded by scanner — survives intact.
        $entity4 = $personStorage->load(4);
        self::assertNotNull($entity4);
        self::assertSame('123', $entity4->get('ssn'), 'non-matching label: ssn preserved');

        // Entity 5 (exempt): yielded but skipped — survives intact.
        $entity5 = $personStorage->load(5);
        self::assertNotNull($entity5);
        self::assertSame('123', $entity5->get('ssn'), 'exempt: ssn preserved');

        // Entity 6 (no PII): yielded but no PII fields to null — no audit record.
        $redactEvents = array_values(array_filter(
            $audit->recorded,
            static fn($d): bool => $d->kind === AuditEventKind::RetentionRedact,
        ));
        self::assertCount(3, $redactEvents);
        $redactedUuids = array_map(static fn($d): string => $d->entityUuid, $redactEvents);
        self::assertEqualsCanonicalizing(['uuid-1', 'uuid-2', 'uuid-3'], $redactedUuids);
    }

    // -------------------------------------------------------------------------
    // HoldScanJob parity
    // -------------------------------------------------------------------------

    #[Test]
    public function hold_scan_job_with_batched_scan_flags_exact_same_conflicts_as_unbounded_path(): void
    {
        // labelCondition=null for HoldScanJob — all classified entities are scanned.
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

        $docStorage = $this->makeStorage('document', [
            1 => $this->makeEntity('document', 1, [   // hold-legal → CONFLICT
                'uuid' => 'uuid-1', 'classification_label' => 'hold-legal',
            ]),
            2 => $this->makeEntity('document', 2, [   // hold-legal → CONFLICT
                'uuid' => 'uuid-2', 'classification_label' => 'hold-legal',
            ]),
            3 => $this->makeEntity('document', 3, [   // hold-legal → CONFLICT
                'uuid' => 'uuid-3', 'classification_label' => 'hold-legal',
            ]),
            4 => $this->makeEntity('document', 4, [   // internal → no hold match → NO CONFLICT
                'uuid' => 'uuid-4', 'classification_label' => 'internal',
            ]),
            5 => $this->makeEntity('document', 5, [   // public → no match → NO CONFLICT
                'uuid' => 'uuid-5', 'classification_label' => 'public',
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'document' => $docStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new HoldScanJob($etm, $audit, null, new RetentionScanner($etm, 2))->run();

        $conflicts = array_values(array_filter(
            $audit->recorded,
            static fn($d): bool => $d->kind === AuditEventKind::ClassificationChange
                && ($d->attributes['conflict'] ?? null) === 'hold_vs_purge',
        ));

        // Exactly 3 conflicts: entities 1, 2, 3.
        self::assertCount(3, $conflicts);
        $conflictUuids = array_map(static fn($d): string => $d->entityUuid, $conflicts);
        self::assertEqualsCanonicalizing(['uuid-1', 'uuid-2', 'uuid-3'], $conflictUuids);
    }
}

/**
 * Parity-test fixture: entity carrying PII fields via #[FieldTemplate], used to
 * exercise RedactJob's discoverPiiFields() reflection path across multiple batches.
 */
final class ParityPiiPerson extends FakeEntity
{
    #[FieldTemplate(key: 'ssn', type: 'string', pii: true)]
    public string $ssnField = '';

    #[FieldTemplate(key: 'email', type: 'string', pii: true)]
    public string $emailField = '';
}
