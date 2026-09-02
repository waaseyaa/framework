<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Schema\BundleLevelDiff;
use Waaseyaa\EntityStorage\Schema\EntityDiffFactory;
use Waaseyaa\EntityStorage\Schema\EntityLevelDiff;
use Waaseyaa\EntityStorage\Schema\SchemaSnapshot;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\AlterColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\DropColumn;
use Waaseyaa\Foundation\Schema\Diff\OpKind;

#[CoversClass(EntityDiffFactory::class)]
#[CoversClass(EntityLevelDiff::class)]
#[CoversClass(BundleLevelDiff::class)]
#[CoversClass(SchemaSnapshot::class)]
final class EntityDiffFactoryTest extends TestCase
{
    #[Test]
    public function emitsAddColumnForEachRegisteredFieldNotInSnapshot(): void
    {
        $factory = self::factory();
        $type = self::entityType('node');

        $diff = $factory->forEntityType(
            $type,
            'node',
            [
                self::field('title', 'string'),
                self::field('body', 'text'),
            ],
            [],
            new SchemaSnapshot(),
        );

        self::assertSame('node', $diff->entityTypeId);
        self::assertCount(2, $diff->composite->ops);
        self::assertInstanceOf(AddColumn::class, $diff->composite->ops[0]);
        self::assertInstanceOf(AddColumn::class, $diff->composite->ops[1]);
        self::assertSame('title', $diff->composite->ops[0]->column);
        self::assertSame('body', $diff->composite->ops[1]->column);
        self::assertSame([], $diff->bundleDiffs);
    }

    #[Test]
    public function bundleFieldsLandInBundleLevelDiffOnSubtable(): void
    {
        $factory = self::factory();
        $type = self::entityType('node');

        $diff = $factory->forEntityType(
            $type,
            'node',
            [],
            [
                'article' => [
                    self::field('summary', 'string'),
                    self::field('body', 'text'),
                    self::field('reading_time', 'integer'),
                ],
            ],
            new SchemaSnapshot(),
        );

        self::assertCount(0, $diff->composite->ops, 'Base table has no diffs in this scenario.');
        self::assertCount(1, $diff->bundleDiffs);
        $bundle = $diff->bundleDiffs[0];
        self::assertSame('article', $bundle->bundleId);
        self::assertSame('node__article', $bundle->subtableName());
        self::assertCount(3, $bundle->composite->ops);
        foreach ($bundle->composite->ops as $op) {
            self::assertSame(OpKind::AddColumn, $op->kind());
            self::assertSame('node__article', $op->table);
        }
    }

    #[Test]
    public function emptyBundleProducesNoBundleLevelDiff(): void
    {
        $factory = self::factory();
        $type = self::entityType('node');

        $diff = $factory->forEntityType(
            $type,
            'node',
            [],
            ['empty_bundle' => []],
            new SchemaSnapshot(),
        );

        self::assertSame([], $diff->bundleDiffs);
    }

    #[Test]
    public function alterColumnEmittedWhenSnapshotSpecDiffers(): void
    {
        $factory = self::factory();
        $type = self::entityType('node');

        $snapshot = new SchemaSnapshot([
            'node' => [
                'title' => new ColumnSpec(type: 'varchar', nullable: false, length: 255),
            ],
        ]);

        // Field changed: was varchar/255, now text.
        $diff = $factory->forEntityType(
            $type,
            'node',
            [self::field('title', 'text')],
            [],
            $snapshot,
        );

        self::assertCount(1, $diff->composite->ops);
        self::assertInstanceOf(AlterColumn::class, $diff->composite->ops[0]);
        self::assertSame('title', $diff->composite->ops[0]->column);
        self::assertSame('text', $diff->composite->ops[0]->newSpec->type);
    }

    #[Test]
    public function dropColumnEmittedForSnapshotColumnNotInRegistry(): void
    {
        $factory = self::factory();
        $type = self::entityType('node');

        $snapshot = new SchemaSnapshot([
            'node' => [
                'legacy_flag' => new ColumnSpec(type: 'boolean', nullable: true),
            ],
        ]);

        $diff = $factory->forEntityType(
            $type,
            'node',
            [],
            [],
            $snapshot,
        );

        self::assertCount(1, $diff->composite->ops);
        self::assertInstanceOf(DropColumn::class, $diff->composite->ops[0]);
        self::assertSame('legacy_flag', $diff->composite->ops[0]->column);
    }

    #[Test]
    public function fieldStorageDataIsExcludedFromColumnOps(): void
    {
        // Per #1257 K2: _data-stored fields are NOT materialised as columns.
        $factory = self::factory();
        $type = self::entityType('node');

        $diff = $factory->forEntityType(
            $type,
            'node',
            [
                self::field('title', 'string'),
                self::field('audit_blob', 'text', stored: FieldStorage::Data),
            ],
            [],
            new SchemaSnapshot(),
        );

        self::assertCount(1, $diff->composite->ops, 'Only `title` should produce a column op.');
        self::assertSame('title', $diff->composite->ops[0]->column);
    }

    #[Test]
    public function noOpWhenSnapshotMatchesRegistryExactly(): void
    {
        $factory = self::factory();
        $type = self::entityType('node');

        $snapshot = new SchemaSnapshot([
            'node' => [
                'title' => new ColumnSpec(type: 'varchar', nullable: true, length: 255),
            ],
        ]);

        $diff = $factory->forEntityType(
            $type,
            'node',
            [self::field('title', 'string')],
            [],
            $snapshot,
        );

        self::assertTrue($diff->isEmpty());
    }

    #[Test]
    public function entityLevelDiffChecksumDelegatesToCompositeRoot(): void
    {
        $factory = self::factory();
        $type = self::entityType('node');

        $diff = $factory->forEntityType(
            $type,
            'node',
            [self::field('title', 'string')],
            [],
            new SchemaSnapshot(),
        );

        // Per Q7: entity-type-id is metadata, not part of structural identity.
        // checksum must equal the composite's checksum.
        self::assertSame($diff->composite->checksum(), $diff->checksum());
    }

    #[Test]
    public function customDeriverIsHonored(): void
    {
        $factory = new EntityDiffFactory(
            deriver: static fn(FieldDefinitionInterface $f): ColumnSpec => new ColumnSpec(
                type: 'text',
                nullable: true,
            ),
        );

        $diff = $factory->forEntityType(
            self::entityType('node'),
            'node',
            [self::field('whatever', 'string')],
            [],
            new SchemaSnapshot(),
        );

        self::assertCount(1, $diff->composite->ops);
        self::assertSame('text', $diff->composite->ops[0]->spec->type);
    }

    private static function factory(): EntityDiffFactory
    {
        return new EntityDiffFactory();
    }

    private static function entityType(string $id): EntityTypeInterface
    {
        return new EntityType(
            id: $id,
            label: ucfirst($id),
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );
    }

    private static function field(string $name, string $type, FieldStorage $stored = FieldStorage::Column): FieldDefinitionInterface
    {
        return new FieldDefinition(
            name: $name,
            type: $type,
            stored: $stored,
        );
    }
}
