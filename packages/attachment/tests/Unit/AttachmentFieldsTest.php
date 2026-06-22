<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Entity\EntityType;

/**
 * The framework builds an entity's table from its `#[Field]` declarations. The
 * attachment entity previously declared ZERO fields, so schema-sync produced a
 * table without `parent_entity_type`/`parent_entity_id`/`is_active` — the very
 * columns `AttachmentRepository::setActive()` reads and updates.
 */
#[CoversClass(Attachment::class)]
final class AttachmentFieldsTest extends TestCase
{
    #[Test]
    public function declares_the_parent_linkage_and_active_columns_for_schema_sync(): void
    {
        $fields = EntityType::fromClass(Attachment::class)->getFieldDefinitions();

        self::assertArrayHasKey('parent_entity_type', $fields);
        self::assertArrayHasKey('parent_entity_id', $fields);
        self::assertArrayHasKey('is_active', $fields);
    }
}
