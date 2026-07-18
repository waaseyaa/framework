<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\Entity\EntityRevisionRestoreGuard;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\FieldReadLevel;

final class EntityRevisionRestoreGuardTest extends TestCase
{
    #[Test]
    public function sealed_revisions_compare_internal_fields_without_exporting_values_or_requiring_ambient_scope(): void
    {
        $current = $this->entity(['id' => 1, 'title' => 'Current', 'roles' => ['member'], 'pass' => 'hash-a'], 2);
        $target = $this->entity(['id' => 1, 'title' => 'Target', 'roles' => ['administrator'], 'pass' => 'hash-b'], 1);

        self::assertSame(['roles', 'title'], EntityRevisionRestoreGuard::changedFieldNames($current, $target));
    }

    /** @param array<string, mixed> $values */
    private function entity(array $values, int $revisionId): ComparisonRevisionEntity
    {
        $generation = new EntityReadLayoutGeneration();
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: $values + ['revision_id' => $revisionId],
            layout: new EntityReadLayout($generation, [
                'id' => FieldReadLevel::Public,
                'title' => FieldReadLevel::Public,
                'revision_id' => FieldReadLevel::Public,
                'roles' => FieldReadLevel::Internal,
                'pass' => FieldReadLevel::Internal,
            ]),
            structure: new EntityStructure(
                'comparison_revision',
                'comparison_revision',
                1,
                null,
                revisionId: $revisionId,
                fieldNames: array_keys($values + ['revision_id' => $revisionId]),
            ),
            entityTypeId: 'comparison_revision',
            entityKeys: ['id' => 'id', 'label' => 'title', 'revision' => 'revision_id'],
        );
        $entity = $boundary->installer()->instantiate(ComparisonRevisionEntity::class, $payload);
        self::assertInstanceOf(ComparisonRevisionEntity::class, $entity);

        return $entity;
    }
}

final class ComparisonRevisionEntity extends EntityBase {}
