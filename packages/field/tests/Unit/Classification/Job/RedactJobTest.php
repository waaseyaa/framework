<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Field\Attribute\FieldTemplate;
use Waaseyaa\Field\Classification\Job\RedactJob;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\FakeEntity;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\FakeStorage;
use Waaseyaa\Field\Tests\Unit\Classification\Job\Support\JobTestEnvironment;

require_once __DIR__ . '/Support/JobTestEnvironment.php';

#[CoversClass(RedactJob::class)]
final class RedactJobTest extends TestCase
{
    use JobTestEnvironment;

    #[Test]
    public function nulls_pii_fields_and_preserves_structural_fields(): void
    {
        $person = new RedactablePerson('person', [
            'id' => 1,
            'uuid' => 'u-1',
            'classification_label' => 'restricted',
            'ssn' => '123-45-6789',
            'email' => 'jane@example.test',
            'title' => 'Knowledge Keeper',
        ]);

        $personStorage = new FakeStorage('person', [1 => $person]);
        $policyStorage = $this->makeStorage('retention_policy', [
            20 => $this->makePolicy(20, [
                'action' => RetentionPolicy::ACTION_REDACT,
                'applies_to' => ['restricted'],
                'trigger_value' => '',
                'exemptions' => [],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'person' => $personStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new RedactJob($etm, $audit)->run();

        // PII fields nulled.
        self::assertNull($person->get('ssn'));
        self::assertNull($person->get('email'));
        // Structural / identity fields preserved.
        self::assertSame('Knowledge Keeper', $person->get('title'));
        self::assertSame('u-1', $person->get('uuid'));
        self::assertSame('restricted', $person->get('classification_label'));

        $redactEvents = array_filter(
            $audit->recorded,
            static fn($d): bool => $d->kind === AuditEventKind::RetentionRedact,
        );
        self::assertCount(1, $redactEvents);
        $event = array_values($redactEvents)[0];
        self::assertEqualsCanonicalizing(['ssn', 'email'], $event->attributes['redacted_fields']);
        self::assertSame(20, $event->attributes['policy_id']);
    }

    #[Test]
    public function skips_entities_without_pii_fields(): void
    {
        // A plain FakeEntity carries no #[FieldTemplate(pii: true)] attributes.
        $plain = $this->makeEntity('node', 1, [
            'uuid' => 'u-1',
            'classification_label' => 'restricted',
            'body' => 'sensitive',
        ]);

        $nodeStorage = $this->makeStorage('node', [1 => $plain]);
        $policyStorage = $this->makeStorage('retention_policy', [
            20 => $this->makePolicy(20, [
                'action' => RetentionPolicy::ACTION_REDACT,
                'applies_to' => ['restricted'],
                'trigger_value' => '',
                'exemptions' => [],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'node' => $nodeStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new RedactJob($etm, $audit)->run();

        self::assertSame('sensitive', $plain->get('body'), 'no PII metadata → nothing redacted');
        self::assertSame([], $audit->recorded);
    }

    #[Test]
    public function honours_exemptions(): void
    {
        $person = new RedactablePerson('person', [
            'id' => 1,
            'uuid' => 'u-exempt',
            'classification_label' => 'restricted',
            'ssn' => '123-45-6789',
            'email' => 'jane@example.test',
            'title' => 'Keeper',
        ]);

        $personStorage = new FakeStorage('person', [1 => $person]);
        $policyStorage = $this->makeStorage('retention_policy', [
            20 => $this->makePolicy(20, [
                'action' => RetentionPolicy::ACTION_REDACT,
                'applies_to' => ['restricted'],
                'trigger_value' => '',
                'exemptions' => ['person:u-exempt'],
            ]),
        ]);

        $etm = $this->makeEntityTypeManager([
            'retention_policy' => $policyStorage,
            'person' => $personStorage,
        ]);
        $audit = $this->recordingAuditWriter();

        new RedactJob($etm, $audit)->run();

        self::assertSame('123-45-6789', $person->get('ssn'), 'exempt entity is not redacted');
        self::assertSame([], $audit->recorded);
    }
}

/**
 * Test fixture: an entity whose PII fields are declared via #[FieldTemplate].
 * The marker properties exist only to carry the attributes read reflectively
 * by RedactJob; the real values live in the FakeEntity value bag.
 */
final class RedactablePerson extends FakeEntity
{
    #[FieldTemplate(key: 'ssn', type: 'string', pii: true)]
    public string $ssnField = '';

    #[FieldTemplate(key: 'email', type: 'string', pii: true)]
    public string $emailField = '';

    #[FieldTemplate(key: 'title', type: 'string')]
    public string $titleField = '';
}
