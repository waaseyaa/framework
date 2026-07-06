<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Http\AnchorCatalogController;
use Waaseyaa\Wayfinding\Tests\Support\CountingEntityTypeManager;
use Waaseyaa\Wayfinding\Tests\Support\InMemoryEntityTypeManager;
use Waaseyaa\Wayfinding\Tests\Support\WidgetEntity;

#[CoversClass(AnchorCatalogController::class)]
final class AnchorCatalogControllerTest extends TestCase
{
    #[Test]
    public function publishes_the_anchor_catalog_as_json(): void
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
            ['widget' => ['body' => ['type' => 'text_long', 'label' => 'Body']]],
        );

        $response = new AnchorCatalogController(new AnchorRegistry($etm))->catalog();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['version']);
        self::assertContains('field', $payload['kinds']);

        $ids = array_map(static fn(array $anchor): string => $anchor['id'], $payload['anchors']);
        self::assertContains('list:widget', $ids);
        self::assertContains('field:widget:body', $ids);
        self::assertContains('action:widget:submit', $ids);
    }

    #[Test]
    public function reuses_the_injected_registry_instead_of_rebuilding_per_request(): void
    {
        // MAJOR finding (audit L4-wayfinding.md): this public anonymous
        // endpoint used to `new AnchorRegistry(...)` fresh inside catalog(),
        // discarding the shared singleton and rebuilding the full catalog
        // (SchemaPresenter over every entity type/field) on every request.
        // The controller must now take one AnchorRegistry via constructor
        // injection and reuse its memoized catalog across repeated calls.
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

        $controller = new AnchorCatalogController(new AnchorRegistry($etm));

        $controller->catalog();
        $controller->catalog();

        // A per-request rebuild (the pre-fix behavior) would report 2, not 1.
        self::assertSame(1, $etm->getDefinitionsCallCount);
        self::assertSame(1, $etm->resolveFieldDefinitionsCallCount);
    }
}
