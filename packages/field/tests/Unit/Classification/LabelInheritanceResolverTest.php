<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Field\Classification\ClassificationDecision;
use Waaseyaa\Field\Classification\ClassificationParentResolverInterface;
use Waaseyaa\Field\Classification\LabelInheritanceResolver;

#[CoversClass(LabelInheritanceResolver::class)]
#[CoversClass(ClassificationDecision::class)]
final class LabelInheritanceResolverTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /** @param array<string, mixed> $fields */
    private function makeEntity(
        string $entityTypeId,
        array $fields = [],
        string $uuid = 'entity-uuid-001',
    ): EntityInterface {
        return new class($entityTypeId, $fields, $uuid) implements EntityInterface {
            public function __construct(
                private string $entityTypeId,
                private array $fields,
                private string $uuid,
            ) {}

            public function id(): int|string|null { return 1; }
            public function uuid(): string { return $this->uuid; }
            public function getEntityTypeId(): string { return $this->entityTypeId; }
            public function bundle(): string { return $this->entityTypeId; }
            public function language(): string { return 'en'; }
            public function get(string $name): mixed { return $this->fields[$name] ?? null; }
            public function set(string $name, mixed $value): static { $this->fields[$name] = $value; return $this; }
            public function isNew(): bool { return false; }
            public function label(): string { return ''; }
            public function toArray(): array { return $this->fields; }
            public function getCastSpecForField(string $field): string|array|null { return null; }
            public function enforceIsNew(): void {}
        };
    }

    private function makeResolver(): LabelInheritanceResolver
    {
        return new LabelInheritanceResolver();
    }

    // ---------------------------------------------------------------------------
    // Tests — explicit label
    // ---------------------------------------------------------------------------

    #[Test]
    public function explicit_label_is_returned_directly(): void
    {
        $entity = $this->makeEntity('node', ['classification_label' => 'confidential']);
        $resolver = $this->makeResolver();

        $decision = $resolver->resolve($entity);

        self::assertSame('confidential', $decision->label);
        self::assertFalse($decision->inherited);
        self::assertNull($decision->inheritedFromUuid);
    }

    #[Test]
    public function null_label_with_no_resolver_returns_explicit_null(): void
    {
        $entity = $this->makeEntity('node', []);
        $resolver = $this->makeResolver();

        $decision = $resolver->resolve($entity);

        self::assertNull($decision->label);
        self::assertFalse($decision->inherited);
    }

    // ---------------------------------------------------------------------------
    // Tests — inheritance
    // ---------------------------------------------------------------------------

    #[Test]
    public function entity_inherits_label_from_parent_when_no_explicit_label(): void
    {
        $parent = $this->makeEntity('node', ['classification_label' => 'internal'], 'parent-uuid-abc');
        $child = $this->makeEntity('node', []);

        $parentResolver = new class($parent) implements ClassificationParentResolverInterface {
            public function __construct(private EntityInterface $parent) {}
            public function getSupportedEntityTypeId(): string { return 'node'; }
            public function resolveParent(EntityInterface $entity): ?EntityInterface { return $this->parent; }
        };

        $resolver = $this->makeResolver();
        $resolver->addResolver($parentResolver);

        $decision = $resolver->resolve($child);

        self::assertSame('internal', $decision->label);
        self::assertTrue($decision->inherited);
        self::assertSame('parent-uuid-abc', $decision->inheritedFromUuid);
    }

    #[Test]
    public function parent_with_null_label_yields_explicit_null(): void
    {
        $parent = $this->makeEntity('node', ['classification_label' => null], 'parent-uuid-xyz');
        $child = $this->makeEntity('node', []);

        $parentResolver = new class($parent) implements ClassificationParentResolverInterface {
            public function __construct(private EntityInterface $parent) {}
            public function getSupportedEntityTypeId(): string { return 'node'; }
            public function resolveParent(EntityInterface $entity): ?EntityInterface { return $this->parent; }
        };

        $resolver = $this->makeResolver();
        $resolver->addResolver($parentResolver);

        $decision = $resolver->resolve($child);

        self::assertNull($decision->label);
        self::assertFalse($decision->inherited);
    }

    #[Test]
    public function no_parent_resolver_for_entity_type_yields_explicit_null(): void
    {
        $entity = $this->makeEntity('taxonomy_term', []);
        $resolver = $this->makeResolver();

        $decision = $resolver->resolve($entity);

        self::assertNull($decision->label);
        self::assertFalse($decision->inherited);
    }

    #[Test]
    public function resolver_returning_null_parent_yields_explicit_null(): void
    {
        $entity = $this->makeEntity('node', []);

        $parentResolver = new class implements ClassificationParentResolverInterface {
            public function getSupportedEntityTypeId(): string { return 'node'; }
            public function resolveParent(EntityInterface $entity): ?EntityInterface { return null; }
        };

        $resolver = $this->makeResolver();
        $resolver->addResolver($parentResolver);

        $decision = $resolver->resolve($entity);

        self::assertNull($decision->label);
        self::assertFalse($decision->inherited);
    }

    // ---------------------------------------------------------------------------
    // Tests — ClassificationDecision helpers
    // ---------------------------------------------------------------------------

    #[Test]
    public function to_storage_array_contains_all_three_columns(): void
    {
        $decision = ClassificationDecision::inherited('confidential', 'parent-uuid-abc');

        $arr = $decision->toStorageArray();

        self::assertArrayHasKey('classification_label', $arr);
        self::assertArrayHasKey('classification_inherited_from', $arr);
        self::assertArrayHasKey('classification_overridden_at', $arr);
        self::assertSame('confidential', $arr['classification_label']);
        self::assertSame('parent-uuid-abc', $arr['classification_inherited_from']);
    }

    #[Test]
    public function explicit_decision_has_null_inherited_from(): void
    {
        $decision = ClassificationDecision::explicit('restricted');

        self::assertFalse($decision->inherited);
        self::assertNull($decision->inheritedFromUuid);
        self::assertSame('restricted', $decision->label);
    }
}
