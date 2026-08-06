<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\Exception\EntitySerializationForbidden;
use Waaseyaa\Entity\FieldReadLevel;

final class FieldReadContractsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EntityReadRuntime::installGuard(null);
        EntityReadRuntime::installFieldRegistry(null);
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
        EntityReadRuntime::installFieldRegistry(null);
        parent::tearDown();
    }

    #[Test]
    public function read_levels_have_stable_wire_values(): void
    {
        self::assertSame('public', FieldReadLevel::Public->value);
        self::assertSame('protected', FieldReadLevel::Protected->value);
        self::assertSame('internal', FieldReadLevel::Internal->value);
    }

    #[Test]
    public function entity_structure_exposes_only_immutable_structural_selectors(): void
    {
        $structure = new EntityStructure(
            entityTypeId: 'node',
            bundleId: 'article',
            id: '42',
            uuid: '018f5f20-0000-7000-8000-000000000001',
            activeLanguageId: 'cr',
            defaultLanguageId: 'en',
            knownTranslationIds: ['en', 'cr'],
            revisionId: '7',
            revisionTip: true,
            defaultRevision: true,
            fieldNames: ['id', 'title'],
        );

        self::assertSame('node', $structure->entityTypeId);
        self::assertSame('article', $structure->bundleId);
        self::assertSame(['en', 'cr'], $structure->knownTranslationIds);
        self::assertTrue($structure->hasField('title'));
        self::assertFalse($structure->hasField('body'));
    }

    #[Test]
    public function future_serialization_exception_is_a_logic_error_without_runtime_wiring(): void
    {
        $exception = new EntitySerializationForbidden('activation only');

        self::assertInstanceOf(\LogicException::class, $exception);
    }

    #[Test]
    public function ordinary_entities_are_sealed_against_php_serialization(): void
    {
        $entity = new TestEntity(
            ['id' => 7, 'mail' => 'member@example.test'],
            'user',
            ['id' => 'id'],
        );

        self::assertSame('member@example.test', $entity->get('mail'));
        self::assertSame(['id' => 7, 'mail' => 'member@example.test'], $entity->toArray());

        $this->expectException(EntitySerializationForbidden::class);
        serialize($entity);
    }
}
