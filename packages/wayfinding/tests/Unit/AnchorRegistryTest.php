<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Tests\Support\InMemoryEntityTypeManager;
use Waaseyaa\Wayfinding\Tests\Support\WidgetEntity;

#[CoversClass(AnchorRegistry::class)]
final class AnchorRegistryTest extends TestCase
{
    private function registry(): AnchorRegistry
    {
        $widget = new EntityType(
            id: 'widget',
            label: 'Widget',
            class: WidgetEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            translatable: false,
            revisionable: false,
        );

        $etm = new InMemoryEntityTypeManager(
            ['widget' => $widget],
            ['widget' => [
                'body' => ['type' => 'text_long', 'label' => 'Body'],
                'status' => ['type' => 'boolean', 'label' => 'Published'],
            ]],
        );

        return new AnchorRegistry($etm);
    }

    /** @return list<string> */
    private function ids(AnchorRegistry $registry): array
    {
        // Exercises anchorIds() (the flat-id accessor used by emit-time validation).
        return $registry->anchorIds();
    }

    #[Test]
    public function catalog_includes_structural_action_and_field_anchors(): void
    {
        $ids = $this->ids($this->registry());

        foreach ([
            'list:widget', 'view:widget', 'form:widget',
            'action:widget:edit', 'action:widget:delete', 'action:widget:submit',
            'field:widget:title', 'field:widget:body', 'field:widget:status',
            'list-field:widget:title', 'list-field:widget:body', 'list-field:widget:status',
        ] as $expected) {
            self::assertContains($expected, $ids, "catalog should contain {$expected}");
        }
    }

    #[Test]
    public function hidden_widget_fields_are_excluded(): void
    {
        // id/uuid are system fields the SchemaPresenter marks x-widget=hidden; the
        // SPA renders no anchors for them, so neither must the catalog.
        $ids = $this->ids($this->registry());

        self::assertNotContains('field:widget:id', $ids);
        self::assertNotContains('field:widget:uuid', $ids);
        self::assertNotContains('list-field:widget:id', $ids);
    }

    #[Test]
    public function is_valid_accepts_catalogued_anchors_and_rejects_others(): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->isValid('field:widget:body'));
        self::assertTrue($registry->isValid('action:widget:edit'));
        self::assertTrue($registry->isValid('list:widget'));

        self::assertFalse($registry->isValid('field:widget:nonexistent'));
        self::assertFalse($registry->isValid('list:other_type'));
        self::assertFalse($registry->isValid('not-an-anchor'));
    }
}
