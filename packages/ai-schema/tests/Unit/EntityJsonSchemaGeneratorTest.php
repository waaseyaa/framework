<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Schema\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Schema\EntityJsonSchemaGenerator;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Field\FieldDefinition;

#[CoversClass(EntityJsonSchemaGenerator::class)]
final class EntityJsonSchemaGeneratorTest extends TestCase
{
    #[Test]
    public function itAdaptsTheEffectiveFieldSetThroughTheCanonicalAuthority(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $type = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $manager->expects(self::once())->method('getDefinition')->with('article')->willReturn($type);
        $manager->expects(self::once())->method('resolveFieldDefinitions')->with('article', null)->willReturn([
            'summary' => new FieldDefinition(name: 'summary', type: 'string', required: true),
        ]);

        $schema = new EntityJsonSchemaGenerator($manager)->generate(
            'article',
            $this->entity(),
            $this->handler(),
            $this->createStub(AuthorizationPrincipalInterface::class),
        );

        self::assertFalse($schema['additionalProperties']);
        self::assertSame('string', $schema['properties']['summary']['type']);
        self::assertSame('string', $schema['properties']['summary']['x-field-type']);
        self::assertContains('summary', $schema['required']);
    }

    #[Test]
    public function itFailsClosedWhenAnEffectiveFieldTypeIsUnknown(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinition')->willReturn(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));
        $manager->method('resolveFieldDefinitions')->willReturn([
            'mystery' => new FieldDefinition(name: 'mystery', type: 'unknown'),
        ]);

        $this->expectException(UnknownFieldTypeException::class);
        new EntityJsonSchemaGenerator($manager)->generate(
            'article',
            $this->entity(),
            $this->handler(),
            $this->createStub(AuthorizationPrincipalInterface::class),
        );
    }

    #[Test]
    public function itOmitsFieldMetadataForbiddenToTheExplicitPrincipal(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $type = new EntityType(id: 'article', label: 'Article', class: \stdClass::class, keys: ['id' => 'id']);
        $manager->method('getDefinition')->willReturn($type);
        $manager->method('resolveFieldDefinitions')->willReturn([
            'public' => new FieldDefinition(name: 'public', type: 'string'),
            'secret' => new FieldDefinition(name: 'secret', type: 'string', description: 'classified metadata'),
        ]);

        $schema = new EntityJsonSchemaGenerator($manager)->generate(
            'article',
            $this->entity(),
            $this->handler('secret'),
            $this->createStub(AuthorizationPrincipalInterface::class),
        );

        self::assertArrayHasKey('public', $schema['properties']);
        self::assertArrayNotHasKey('secret', $schema['properties']);
        self::assertStringNotContainsString('classified metadata', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    private function entity(): EntityInterface
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('article');

        return $entity;
    }

    private function handler(?string $deniedField = null): EntityAccessHandler
    {
        $policy = new class ($deniedField) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(private readonly ?string $deniedField) {}
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
            public function appliesTo(string $entityTypeId): bool { return $entityTypeId === 'article'; }
            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'view' && $fieldName === $this->deniedField
                    ? AccessResult::forbidden()
                    : AccessResult::neutral();
            }
        };

        return new EntityAccessHandler([$policy]);
    }
}
