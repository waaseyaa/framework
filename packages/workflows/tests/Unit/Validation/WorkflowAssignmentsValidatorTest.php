<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Validation\WorkflowAssignmentsValidator;

/**
 * @covers \Waaseyaa\Workflows\Validation\WorkflowAssignmentsValidator
 */
#[CoversClass(WorkflowAssignmentsValidator::class)]
final class WorkflowAssignmentsValidatorTest extends TestCase
{
    /**
     * @param array<string, EntityTypeInterface> $definitions
     */
    private function entityTypeManager(array $definitions): EntityTypeManagerInterface
    {
        return new class ($definitions) implements EntityTypeManagerInterface {
            public function __construct(private readonly array $definitions) {}
            public function getDefinition(string $entityTypeId): EntityTypeInterface { return $this->definitions[$entityTypeId]; }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return $this->definitions; }
            public function hasDefinition(string $entityTypeId): bool { return isset($this->definitions[$entityTypeId]); }
            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }
            public function getRepository(string $entityTypeId): EntityRepositoryInterface { throw new \LogicException('not needed'); }
        };
    }

    #[Test]
    public function a_revisionable_single_axis_binding_is_valid(): void
    {
        $validator = new WorkflowAssignmentsValidator();
        $entityTypeManager = $this->entityTypeManager([
            'node' => new EntityType(id: 'node', label: 'Content', class: \stdClass::class, keys: ['id' => 'nid', 'revision' => 'vid'], revisionable: true),
        ]);

        $violations = $validator->validate(['node.article' => 'editorial'], $entityTypeManager);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function binding_a_non_revisionable_type_is_rejected(): void
    {
        $validator = new WorkflowAssignmentsValidator();
        $entityTypeManager = $this->entityTypeManager([
            'note' => new EntityType(id: 'note', label: 'Note', class: \stdClass::class, keys: ['id' => 'id'], revisionable: false),
        ]);

        $violations = $validator->validate(['note.note' => 'editorial'], $entityTypeManager);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('not revisionable', $violations[0]);
    }

    #[Test]
    public function binding_a_revisionable_and_translatable_type_is_rejected(): void
    {
        $validator = new WorkflowAssignmentsValidator();
        $entityTypeManager = $this->entityTypeManager([
            'page' => new EntityType(
                id: 'page',
                label: 'Page',
                class: WorkflowAssignmentsValidatorTwoAxisStub::class,
                keys: ['id' => 'id', 'revision' => 'vid', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'],
                revisionable: true,
                translatable: true,
            ),
        ]);

        $violations = $validator->validate(['page.page' => 'editorial'], $entityTypeManager);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('translatable', $violations[0]);
    }

    #[Test]
    public function an_unregistered_entity_type_is_skipped_not_rejected(): void
    {
        // Not this validator's job to know about entity-type discovery
        // ordering during a fresh import — an assignment naming a
        // not-yet-registered type is left to the resolver's own runtime
        // check (which fails when it is actually asked to resolve it).
        $validator = new WorkflowAssignmentsValidator();
        $entityTypeManager = $this->entityTypeManager([]);

        $violations = $validator->validate(['ghost.bundle' => 'editorial'], $entityTypeManager);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function wildcard_bundle_bindings_are_validated_the_same_way(): void
    {
        $validator = new WorkflowAssignmentsValidator();
        $entityTypeManager = $this->entityTypeManager([
            'note' => new EntityType(id: 'note', label: 'Note', class: \stdClass::class, keys: ['id' => 'id'], revisionable: false),
        ]);

        $violations = $validator->validate(['note.*' => 'editorial'], $entityTypeManager);

        $this->assertCount(1, $violations);
    }
}

/**
 * Minimal two-axis stub satisfying EntityType's translatable-registration
 * guard — see WorkflowBindingResolverTest's identical fixture rationale.
 */
final class WorkflowAssignmentsValidatorTwoAxisStub implements \Waaseyaa\Entity\TranslatableInterface
{
    public function defaultLangcode(): string { return 'en'; }
    public function activeLangcode(): string { return 'en'; }
    public function language(): string { return 'en'; }
    public function hasTranslation(string $langcode): bool { return false; }
    public function getTranslation(string $langcode): static { return $this; }
    public function addTranslation(string $langcode): static { return $this; }
    public function removeTranslation(string $langcode): void {}
    public function translations(): iterable { return []; }
    public function getTranslationLanguages(): array { return []; }
    public function fieldLangcode(string $fieldName): ?string { return null; }
}
