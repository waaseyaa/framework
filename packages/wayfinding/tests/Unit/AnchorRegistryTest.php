<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Tests\Support\CountingEntityTypeManager;
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
            'action:widget:create', 'action:widget:edit', 'action:widget:delete', 'action:widget:submit',
            'field:widget:title', 'field:widget:body', 'field:widget:status',
            'list-field:widget:title', 'list-field:widget:body', 'list-field:widget:status',
        ] as $expected) {
            self::assertContains($expected, $ids, "catalog should contain {$expected}");
        }
    }

    #[Test]
    public function catalog_includes_the_list_level_create_action_anchor(): void
    {
        // P1-3: the list-view "Create new" control is now a catalogued target so a
        // presenter can beacon it directly (mirrors the data-anchor SchemaList
        // emits on that button).
        $registry = $this->registry();

        self::assertContains('action:widget:create', $this->ids($registry));
        self::assertTrue($registry->isValid('action:widget:create'));
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

    #[Test]
    public function catalog_is_memoized_per_instance(): void
    {
        // MAJOR perf finding (audit L4-wayfinding.md): a public anonymous
        // endpoint was rebuilding the full catalog, iterating every entity
        // type and field through SchemaPresenter, on every request. Prove
        // catalog() only derives the anchor set once per AnchorRegistry
        // instance no matter how many times it (or isValid()/anchorIds(),
        // which call it internally) are invoked.
        $widget = new EntityType(
            id: 'widget',
            label: 'Widget',
            class: WidgetEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            translatable: false,
            revisionable: false,
        );

        $etm = new CountingEntityTypeManager(new InMemoryEntityTypeManager(
            ['widget' => $widget],
            ['widget' => ['body' => ['type' => 'text_long', 'label' => 'Body']]],
        ));

        $registry = new AnchorRegistry($etm);

        $first = $registry->catalog();
        $second = $registry->catalog();
        self::assertTrue($registry->isValid('field:widget:body'));
        $registry->anchorIds();

        self::assertSame($first, $second);
        // One entity type ("widget"): resolveFieldDefinitions() is called
        // exactly once per type per catalog build, immediately preceding the
        // SchemaPresenter::present() call it feeds. A rebuild on every one of
        // the four calls above would report 4, not 1.
        self::assertSame(1, $etm->getDefinitionsCallCount);
        self::assertSame(1, $etm->resolveFieldDefinitionsCallCount);
    }
}
