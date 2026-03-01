<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityType;

#[CoversClass(SchemaPresenter::class)]
final class SchemaPresenterTargetTypeTest extends TestCase
{
    #[Test]
    public function entityReferenceFieldIncludesTargetType(): void
    {
        $presenter = new SchemaPresenter();
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: \Waaseyaa\Entity\ContentEntityBase::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $schema = $presenter->present($entityType, [
            'author' => [
                'type' => 'entity_reference',
                'label' => 'Author',
                'settings' => ['target_type' => 'user'],
            ],
        ]);

        $this->assertSame('entity_autocomplete', $schema['properties']['author']['x-widget']);
        $this->assertSame('user', $schema['properties']['author']['x-target-type']);
    }

    #[Test]
    public function entityReferenceFieldDefaultsToNoTargetType(): void
    {
        $presenter = new SchemaPresenter();
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: \Waaseyaa\Entity\ContentEntityBase::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $schema = $presenter->present($entityType, [
            'related' => [
                'type' => 'entity_reference',
                'label' => 'Related',
            ],
        ]);

        $this->assertSame('entity_autocomplete', $schema['properties']['related']['x-widget']);
        $this->assertArrayNotHasKey('x-target-type', $schema['properties']['related']);
    }
}
