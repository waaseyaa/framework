<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Field\Classification\ClassificationParentResolverInterface;
use Waaseyaa\Field\Classification\EntityLifecycleSubscriber;
use Waaseyaa\Field\Classification\LabelInheritanceResolver;

#[CoversClass(EntityLifecycleSubscriber::class)]
final class EntityLifecycleSubscriberTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /** @param array<string, mixed> $fields */
    private function makeEntity(
        string $entityTypeId = 'node',
        array $fields = [],
        string $uuid = 'entity-uuid-001',
        bool $isNew = true,
    ): EntityInterface {
        return new class ($entityTypeId, $fields, $uuid, $isNew) implements EntityInterface {
            /** @var array<string, mixed> */
            public array $fields;

            public function __construct(
                private string $entityTypeId,
                array $fields,
                private string $uuid,
                private bool $new,
            ) {
                $this->fields = $fields;
            }

            public function id(): int|string|null
            {
                return 1;
            }
            public function uuid(): string
            {
                return $this->uuid;
            }
            public function getEntityTypeId(): string
            {
                return $this->entityTypeId;
            }
            public function bundle(): string
            {
                return $this->entityTypeId;
            }
            public function language(): string
            {
                return 'en';
            }
            public function get(string $name): mixed
            {
                return $this->fields[$name] ?? null;
            }
            public function set(string $name, mixed $value): static
            {
                $this->fields[$name] = $value;
                return $this;
            }
            public function isNew(): bool
            {
                return $this->new;
            }
            public function label(): string
            {
                return '';
            }
            public function toArray(): array
            {
                return $this->fields;
            }
            public function getCastSpecForField(string $field): string|array|null
            {
                return null;
            }
            public function enforceIsNew(): void {}
        };
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    #[Test]
    public function classification_uninvolved_entity_gets_no_stray_keys_written(): void
    {
        // The common case framework-wide now that the subscriber is live on
        // every save (WP4 dead-subscriber sweep): an entity with no explicit
        // label, no registered parent resolver, and no prior classification
        // state. The subscriber must not pollute its value bag (and thus the
        // sql-blob `_data` column) with three stray NULL keys.
        $entity = $this->makeEntity('plain_widget', ['title' => 'hello']);

        $auditWriter = new class implements AuditWriterInterface {
            /** @var list<AuditEventDescriptor> */
            public array $recorded = [];
            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };

        $subscriber = new EntityLifecycleSubscriber(new LabelInheritanceResolver(), $auditWriter);
        $subscriber->onPreSave(new EntityEvent($entity));

        self::assertArrayNotHasKey('classification_label', $entity->fields);
        self::assertArrayNotHasKey('classification_inherited_from', $entity->fields);
        self::assertArrayNotHasKey('classification_overridden_at', $entity->fields);
        self::assertCount(0, $auditWriter->recorded);
    }

    #[Test]
    public function clearing_a_previously_labelled_entity_still_writes_nulls_and_audits(): void
    {
        // Counter-case for the skip: an all-null decision must STILL be
        // written (and audited) when a previous label exists — otherwise a
        // cleared label would silently survive in storage.
        $original = $this->makeEntity('node', ['classification_label' => 'confidential']);
        $entity = $this->makeEntity('node', []);

        $auditWriter = new class implements AuditWriterInterface {
            /** @var list<AuditEventDescriptor> */
            public array $recorded = [];
            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };

        $subscriber = new EntityLifecycleSubscriber(new LabelInheritanceResolver(), $auditWriter);
        $subscriber->onPreSave(new EntityEvent($entity, $original));

        self::assertArrayHasKey('classification_label', $entity->fields);
        self::assertNull($entity->fields['classification_label']);
        self::assertCount(1, $auditWriter->recorded);
        self::assertSame('confidential', $auditWriter->recorded[0]->attributes['previous_label']);
        self::assertNull($auditWriter->recorded[0]->attributes['new_label']);
    }

    #[Test]
    public function stale_in_memory_classification_value_is_cleared_not_skipped(): void
    {
        // An entity carrying a stray non-null classification value (e.g.
        // hydrated provenance whose parent lost its label) with an all-null
        // decision and no previous label must still get the nulls written so
        // the stale value does not survive.
        $entity = $this->makeEntity('node', ['classification_inherited_from' => 'ghost-parent-uuid']);

        $auditWriter = new class implements AuditWriterInterface {
            /** @var list<AuditEventDescriptor> */
            public array $recorded = [];
            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };

        $subscriber = new EntityLifecycleSubscriber(new LabelInheritanceResolver(), $auditWriter);
        $subscriber->onPreSave(new EntityEvent($entity));

        self::assertArrayHasKey('classification_inherited_from', $entity->fields);
        self::assertNull($entity->fields['classification_inherited_from']);
    }

    #[Test]
    public function on_pre_save_writes_resolved_label_back_to_entity(): void
    {
        $entity = $this->makeEntity('node', ['classification_label' => 'confidential']);

        $auditWriter = new class implements AuditWriterInterface {
            /** @var list<AuditEventDescriptor> */
            public array $recorded = [];
            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };

        $resolver = new LabelInheritanceResolver();
        $subscriber = new EntityLifecycleSubscriber($resolver, $auditWriter);

        $event = new EntityEvent($entity);
        $subscriber->onPreSave($event);

        // The resolved label should be written back.
        self::assertSame('confidential', $entity->fields['classification_label']);
    }

    #[Test]
    public function audit_record_dispatched_on_label_change(): void
    {
        $original = $this->makeEntity('node', ['classification_label' => 'public']);
        $entity = $this->makeEntity('node', ['classification_label' => 'confidential']);

        $auditWriter = new class implements AuditWriterInterface {
            /** @var list<AuditEventDescriptor> */
            public array $recorded = [];
            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };

        $resolver = new LabelInheritanceResolver();
        $subscriber = new EntityLifecycleSubscriber($resolver, $auditWriter);

        $event = new EntityEvent($entity, $original);
        $subscriber->onPreSave($event);

        self::assertCount(1, $auditWriter->recorded);
        self::assertSame('classification.change', $auditWriter->recorded[0]->kind->value);
        self::assertSame('public', $auditWriter->recorded[0]->attributes['previous_label']);
        self::assertSame('confidential', $auditWriter->recorded[0]->attributes['new_label']);
    }

    #[Test]
    public function no_audit_record_when_label_unchanged(): void
    {
        $original = $this->makeEntity('node', ['classification_label' => 'internal']);
        $entity = $this->makeEntity('node', ['classification_label' => 'internal']);

        $auditWriter = new class implements AuditWriterInterface {
            /** @var list<AuditEventDescriptor> */
            public array $recorded = [];
            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };

        $resolver = new LabelInheritanceResolver();
        $subscriber = new EntityLifecycleSubscriber($resolver, $auditWriter);

        $event = new EntityEvent($entity, $original);
        $subscriber->onPreSave($event);

        self::assertCount(0, $auditWriter->recorded);
    }

    #[Test]
    public function inherited_label_written_when_no_explicit_label(): void
    {
        $parent = $this->makeEntity('node', ['classification_label' => 'restricted'], 'parent-uuid-001');
        $child = $this->makeEntity('node', []);

        $parentResolver = new class ($parent) implements ClassificationParentResolverInterface {
            public function __construct(private EntityInterface $parent) {}
            public function getSupportedEntityTypeId(): string
            {
                return 'node';
            }
            public function resolveParent(EntityInterface $entity): ?EntityInterface
            {
                return $this->parent;
            }
        };

        $auditWriter = new class implements AuditWriterInterface {
            /** @var list<AuditEventDescriptor> */
            public array $recorded = [];
            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };

        $resolver = new LabelInheritanceResolver();
        $resolver->addResolver($parentResolver);

        $subscriber = new EntityLifecycleSubscriber($resolver, $auditWriter);

        $event = new EntityEvent($child);
        $subscriber->onPreSave($event);

        self::assertSame('restricted', $child->fields['classification_label']);
        self::assertSame('parent-uuid-001', $child->fields['classification_inherited_from']);
        // First save (no previous label) — audit IS dispatched (null → 'restricted' is a change).
        self::assertCount(1, $auditWriter->recorded);
        self::assertTrue($auditWriter->recorded[0]->attributes['inherited']);
    }

    #[Test]
    public function exception_in_audit_write_does_not_propagate(): void
    {
        $entity = $this->makeEntity('node', ['classification_label' => 'confidential']);
        $original = $this->makeEntity('node', ['classification_label' => 'public']);

        $auditWriter = new class implements AuditWriterInterface {
            public function record(AuditEventDescriptor $descriptor): void
            {
                throw new \RuntimeException('audit backend offline');
            }
        };

        // AuditWriterInterface contract says it must not throw — but our
        // EntityLifecycleSubscriber also wraps in try-catch for defence in depth.
        $resolver = new LabelInheritanceResolver();
        $subscriber = new EntityLifecycleSubscriber($resolver, $auditWriter);

        $event = new EntityEvent($entity, $original);

        // Must not throw.
        $subscriber->onPreSave($event);

        self::assertTrue(true); // Reached here without exception.
    }
}
