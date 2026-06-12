<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\EmptyFieldsFixture;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\SimpleFixture;

require_once __DIR__ . '/../Fixtures/AttributeFirstEntities/FactoryTestFixtures.php';

/**
 * The `discoverable` flag controls GET /api discovery-index visibility only
 * (mission request-surface-hardening-01KTX7F2, FR-002).
 */
#[CoversClass(EntityType::class)]
final class EntityTypeDiscoverableTest extends TestCase
{
    protected function setUp(): void
    {
        EntityType::clearFromClassCache();
        EntityMetadataReader::clearCache();
    }

    protected function tearDown(): void
    {
        EntityType::clearFromClassCache();
        EntityMetadataReader::clearCache();
    }

    #[Test]
    public function discoverableDefaultsToTrue(): void
    {
        $type = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );

        self::assertTrue($type->isDiscoverable());
    }

    #[Test]
    public function discoverableCanBeExplicitlyDisabled(): void
    {
        $type = new EntityType(
            id: 'secret',
            label: 'Secret',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            discoverable: false,
        );

        self::assertFalse($type->isDiscoverable());
    }

    #[Test]
    public function fromClassDefaultsToDiscoverable(): void
    {
        $type = EntityType::fromClass(SimpleFixture::class);

        self::assertTrue($type->isDiscoverable());
    }

    #[Test]
    public function fromClassPassesDiscoverableOverrideThrough(): void
    {
        $type = EntityType::fromClass(EmptyFieldsFixture::class, discoverable: false);

        self::assertFalse($type->isDiscoverable());
    }
}
