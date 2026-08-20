<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\RevisionRestoreChangedFields;

#[CoversClass(RevisionRestoreChangedFields::class)]
final class RevisionRestoreChangedFieldsTest extends TestCase
{
    #[Test]
    public function workflow_publication_and_privilege_fields_are_material_while_credentials_and_revision_metadata_are_not(): void
    {
        $current = $this->entity([
            'id' => 1,
            'title' => 'Now',
            'roles' => ['viewer'],
            'workflow_state' => 'published',
            'status' => true,
            'pass' => 'hash-a',
            'only_on_current' => 'remove me',
            'revision_id' => 2,
        ], 2);
        $target = $this->entity([
            'id' => 1,
            'title' => 'Then',
            'roles' => ['administrator'],
            'workflow_state' => 'draft',
            'status' => false,
            'pass' => 'hash-b',
            'revision_id' => 1,
        ], 1);

        self::assertSame(
            ['only_on_current', 'roles', 'title', 'workflow_state'],
            RevisionRestoreChangedFields::names($current, $target),
        );
    }

    /** @param array<string, mixed> $values */
    private function entity(array $values, int $revisionId): RestoreComparisonEntity
    {
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: $values,
            layout: new EntityReadLayout(new EntityReadLayoutGeneration(), [
                'id' => FieldReadLevel::Public,
                'title' => FieldReadLevel::Public,
                'revision_id' => FieldReadLevel::Public,
                'roles' => FieldReadLevel::Internal,
                'pass' => FieldReadLevel::Internal,
                'workflow_state' => FieldReadLevel::Internal,
                'status' => FieldReadLevel::Public,
                'only_on_current' => FieldReadLevel::Internal,
            ]),
            structure: new EntityStructure(
                'restore_comparison',
                'restore_comparison',
                1,
                null,
                revisionId: $revisionId,
                fieldNames: array_keys($values),
            ),
            entityTypeId: 'restore_comparison',
            entityKeys: ['id' => 'id', 'label' => 'title', 'revision' => 'revision_id'],
        );
        $entity = $boundary->installer()->instantiate(RestoreComparisonEntity::class, $payload);
        self::assertInstanceOf(RestoreComparisonEntity::class, $entity);

        return $entity;
    }
}

final class RestoreComparisonEntity extends EntityBase {}
