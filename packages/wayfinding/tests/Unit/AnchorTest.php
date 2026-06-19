<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Wayfinding\Anchor\Anchor;

#[CoversClass(Anchor::class)]
final class AnchorTest extends TestCase
{
    #[Test]
    public function ids_match_the_shipped_schema_driven_admin_scheme(): void
    {
        // These MUST stay byte-identical to docs/specs/admin-spa.md "Element
        // anchors" and the SchemaList/SchemaView/SchemaForm data-anchor bindings
        // shipped in alpha.227 — the server catalog and the SPA must agree.
        self::assertSame('list:node', Anchor::structural('list', 'node')->id);
        self::assertSame('view:node', Anchor::structural('view', 'node')->id);
        self::assertSame('form:node', Anchor::structural('form', 'node')->id);
        self::assertSame('list-field:node:title', Anchor::listField('node', 'title')->id);
        self::assertSame('field:node:title', Anchor::field('node', 'title')->id);
        self::assertSame('action:node:edit', Anchor::action('node', 'edit')->id);
        self::assertSame('action:node:delete', Anchor::action('node', 'delete')->id);
        self::assertSame('action:node:submit', Anchor::action('node', 'submit')->id);
    }

    #[Test]
    public function to_array_exposes_id_kind_and_identity(): void
    {
        self::assertSame(
            ['id' => 'field:node:title', 'kind' => 'field', 'entity_type' => 'node', 'field' => 'title'],
            Anchor::field('node', 'title')->toArray(),
        );
        self::assertSame(
            ['id' => 'action:node:edit', 'kind' => 'action', 'entity_type' => 'node', 'operation' => 'edit'],
            Anchor::action('node', 'edit')->toArray(),
        );
        self::assertSame(
            ['id' => 'list:node', 'kind' => 'list', 'entity_type' => 'node'],
            Anchor::structural('list', 'node')->toArray(),
        );
    }
}
