<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Workflows\Config\WorkflowAssignmentsSemanticValidator;

#[CoversClass(WorkflowAssignmentsSemanticValidator::class)]
final class WorkflowAssignmentsSemanticValidatorTest extends TestCase
{
    #[Test]
    public function the_canonical_assignment_validator_judges_the_complete_map_at_the_document_root(): void
    {
        $validator = new WorkflowAssignmentsSemanticValidator($this->entityTypes());

        $violations = $validator->validate([
            'note.note' => 'editorial',
            'page.page' => 'editorial',
        ]);

        self::assertCount(2, $violations);
        self::assertSame(
            [WorkflowAssignmentsSemanticValidator::DOCUMENT_PATH, WorkflowAssignmentsSemanticValidator::DOCUMENT_PATH],
            [$violations[0]->path, $violations[1]->path],
            'A cross-assignment verdict is owned by the whole document, not by one entry.',
        );
        self::assertStringContainsString('not revisionable', $violations[0]->message);
        self::assertStringContainsString('translatable', $violations[1]->message);
    }

    #[Test]
    public function a_noncanonical_entry_is_refused_before_the_map_reaches_the_canonical_validator(): void
    {
        $validator = new WorkflowAssignmentsSemanticValidator($this->entityTypes());

        $violations = $validator->validate([
            'node' => 'editorial',
            'note.note' => 'editorial',
        ]);

        self::assertCount(1, $violations);
        self::assertSame('node', $violations[0]->path);
        self::assertStringContainsString('canonical', $violations[0]->message);
    }

    #[Test]
    public function a_revisionable_single_axis_map_is_accepted(): void
    {
        $validator = new WorkflowAssignmentsSemanticValidator($this->entityTypes());

        self::assertSame([], $validator->validate(['node.page' => 'editorial', 'node.*' => 'editorial']));
    }

    #[Test]
    public function the_declared_semantic_contract_names_the_owning_package_and_schema(): void
    {
        $contract = new WorkflowAssignmentsSemanticValidator($this->entityTypes())->contract();

        self::assertStringContainsString('waaseyaa/workflows', $contract);
        self::assertStringContainsString('workflows.assignments', $contract);
    }

    private function entityTypes(): EntityTypeManager
    {
        $manager = new EntityTypeManager(new SymfonyEventDispatcherAdapter());
        $manager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Content',
            class: \stdClass::class,
            keys: ['id' => 'nid', 'revision' => 'vid'],
            revisionable: true,
        ));
        $manager->registerEntityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            revisionable: false,
        ));
        $manager->registerEntityType(new EntityType(
            id: 'page',
            label: 'Page',
            class: WorkflowAssignmentsSemanticTwoAxisStub::class,
            keys: ['id' => 'id', 'revision' => 'vid', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'],
            revisionable: true,
            translatable: true,
        ));

        return $manager;
    }
}

/** Minimal two-axis stub satisfying EntityType's translatable-registration guard. */
final class WorkflowAssignmentsSemanticTwoAxisStub implements \Waaseyaa\Entity\TranslatableInterface
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
