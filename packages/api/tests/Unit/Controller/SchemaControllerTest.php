<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\NodeNidContentTestEntity;
use Waaseyaa\Api\Tests\Fixtures\RequiredFieldTestEntity;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Api\Tests\Fixtures\ThrowingPrototypeTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SchemaController::class)]
final class SchemaControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private SchemaController $controller;

    protected function setUp(): void
    {
        $storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $storage,
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: [...TestEntity::definitionKeys(), 'default_langcode' => 'default_langcode'],
            translatable: true,
        ));

        $schemaPresenter = new SchemaPresenter();

        $this->controller = new SchemaController(
            $this->entityTypeManager,
            $schemaPresenter,
        );
    }

    #[Test]
    public function showReturnsSchemaForEntityType(): void
    {
        $doc = $this->controller->show('article');
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertArrayHasKey('meta', $array);
        $this->assertArrayHasKey('schema', $array['meta']);

        $schema = $array['meta']['schema'];
        $this->assertSame('Article', $schema['title']);
        $this->assertSame('object', $schema['type']);
        $this->assertSame('article', $schema['x-entity-type']);
        $this->assertTrue($schema['x-translatable']);
    }

    #[Test]
    public function showIncludesSystemPropertiesInSchema(): void
    {
        $doc = $this->controller->show('article');
        $schema = $doc->toArray()['meta']['schema'];

        $this->assertArrayHasKey('properties', $schema);
        $properties = $schema['properties'];

        // id property.
        $this->assertArrayHasKey('id', $properties);
        $this->assertSame('integer', $properties['id']['type']);
        $this->assertTrue($properties['id']['readOnly']);
        $this->assertSame('hidden', $properties['id']['x-widget']);

        // uuid property.
        $this->assertArrayHasKey('uuid', $properties);
        $this->assertSame('string', $properties['uuid']['type']);
        $this->assertSame('uuid', $properties['uuid']['format']);

        // title property (label key).
        $this->assertArrayHasKey('title', $properties);
        $this->assertSame('string', $properties['title']['type']);
        $this->assertSame('text', $properties['title']['x-widget']);
    }

    #[Test]
    public function showIncludesSelfLink(): void
    {
        $doc = $this->controller->show('article');
        $array = $doc->toArray();

        $this->assertArrayHasKey('links', $array);
        $this->assertSame('/api/schema/article', $array['links']['self']);
    }

    #[Test]
    public function showReturnsErrorForUnknownEntityType(): void
    {
        $doc = $this->controller->show('nonexistent');
        $array = $doc->toArray();

        $this->assertSame(404, $doc->statusCode);
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
        $this->assertStringContainsString('nonexistent', $array['errors'][0]['detail']);
    }

    #[Test]
    public function showRejectsAnUnknownBundleInsteadOfFallingBackToTheBaseSchema(): void
    {
        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => new InMemoryEntityStorage('article'),
            fieldRegistry: $registry,
        );
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));
        $registry->registerBundleFields('article', 'page', [
            new FieldDefinition(
                name: 'page_body',
                type: 'text',
                targetEntityTypeId: 'article',
                targetBundle: 'page',
            ),
        ]);

        $controller = new SchemaController($manager, new SchemaPresenter($registry));
        $doc = $controller->show('article', 'unknown');

        self::assertSame(422, $doc->statusCode);
        self::assertSame('422', $doc->toArray()['errors'][0]['status']);
        self::assertStringContainsString('unknown', $doc->toArray()['errors'][0]['detail']);
        self::assertArrayNotHasKey('meta', $doc->toArray());
    }

    #[Test]
    public function showIncludesFieldDefinitionsInSchema(): void
    {
        $storage = new InMemoryEntityStorage('node');
        $manager = new EntityTypeManager(new EventDispatcher(), fn() => $storage);

        $manager->registerEntityType(TestEntityType::stub(
            'node',
            [
                'status' => new FieldDefinition(
                    name: 'status',
                    type: 'boolean',
                    settings: ['weight' => 10],
                    label: 'Published',
                ),
                'uid' => new FieldDefinition(
                    name: 'uid',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => 'user', 'weight' => 20],
                    label: 'Author',
                ),
            ],
            keys: NodeNidContentTestEntity::definitionKeys(),
            class: NodeNidContentTestEntity::class,
            label: 'Content',
        ));

        $controller = new SchemaController($manager, new SchemaPresenter());
        $doc = $controller->show('node');
        $schema = $doc->toArray()['meta']['schema'];

        $this->assertSame(200, $doc->statusCode);
        $this->assertArrayHasKey('status', $schema['properties']);
        $this->assertSame('boolean', $schema['properties']['status']['type']);
        $this->assertSame('boolean', $schema['properties']['status']['x-widget']);
        $this->assertSame('Published', $schema['properties']['status']['x-label']);

        $this->assertArrayHasKey('uid', $schema['properties']);
        $this->assertSame('entity_autocomplete', $schema['properties']['uid']['x-widget']);
        $this->assertSame('user', $schema['properties']['uid']['x-target-type']);
    }

    #[Test]
    public function showAcceptsFieldAccessContext(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $handler = new EntityAccessHandler([]);

        $controller = new SchemaController(
            $this->entityTypeManager,
            new SchemaPresenter(),
            $handler,
            $account,
        );

        $doc = $controller->show('article');
        $schema = $doc->toArray()['meta']['schema'];

        $this->assertSame(200, $doc->statusCode);

        // System properties are always present — not subject to field access.
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertArrayHasKey('uuid', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
    }

    /**
     * Regression for the fail-open vulnerability: when the access-context
     * prototype entity cannot be constructed, SchemaController used to fall
     * through to SchemaPresenter::present() with a null $entity, which skips
     * the field-access-filtering gate entirely (present() line ~180) and
     * emits an unfiltered schema — leaking restricted field metadata (here,
     * 'secret'). The fixed controller must fail closed: no schema body, a
     * 500 error, and the exception detail must never reach the client.
     */
    #[Test]
    public function showFailsClosedWhenPrototypeConstructionThrows(): void
    {
        $storage = new InMemoryEntityStorage('throwing_prototype');
        $manager = new EntityTypeManager(new EventDispatcher(), fn() => $storage);

        $manager->registerEntityType(TestEntityType::stub(
            'throwing_prototype',
            [
                'secret' => new FieldDefinition(
                    name: 'secret',
                    type: 'string',
                    label: 'Secret',
                ),
            ],
            keys: ThrowingPrototypeTestEntity::definitionKeys(),
            class: ThrowingPrototypeTestEntity::class,
            label: 'Sensitive',
        ));

        $account = $this->createMock(AccountInterface::class);
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'throwing_prototype';
            }

            public function fieldAccess(
                EntityInterface $entity,
                string $fieldName,
                string $operation,
                AccountInterface $account,
            ): AccessResult {
                if ($fieldName === 'secret' && $operation === 'view') {
                    return AccessResult::forbidden('secret is view-restricted');
                }

                return AccessResult::neutral();
            }
        };
        $handler = new EntityAccessHandler([$policy]);

        $controller = new SchemaController($manager, new SchemaPresenter(), $handler, $account);

        $doc = $controller->show('throwing_prototype');
        $array = $doc->toArray();

        $this->assertSame(500, $doc->statusCode);
        $this->assertArrayNotHasKey('meta', $array);
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('500', $array['errors'][0]['status']);

        // The exception message (class name, entity type id) must never leak
        // to the client — only a generic detail.
        $this->assertStringNotContainsString('ThrowingPrototypeTestEntity', $array['errors'][0]['detail'] ?? '');
        $this->assertStringNotContainsString('always fails to construct', $array['errors'][0]['detail'] ?? '');

        // No schema body at all — the pre-fix leak exposed 'secret' here.
        $this->assertStringNotContainsString('secret', json_encode($array, JSON_THROW_ON_ERROR));
    }

    /**
     * Availability regression: an entity type whose constructor REQUIRES a
     * field to be present (RequiredFieldTestEntity, mirroring engagement's
     * Comment / user's UserBlock) must still return a 200 filtered schema —
     * SchemaController seeds every declared field + entity key so the strict
     * constructor is satisfied. Without seeding, this type would hit the
     * fail-closed 500 backstop even though it is perfectly valid. Also proves
     * filtering actually ran: a view-forbidden field is absent from the schema.
     */
    #[Test]
    public function showSeedsRequiredFieldsSoConstructorStrictTypeReturnsFilteredSchema(): void
    {
        $storage = new InMemoryEntityStorage('required_field');
        $manager = new EntityTypeManager(new EventDispatcher(), fn() => $storage);

        $manager->registerEntityType(TestEntityType::stub(
            'required_field',
            [
                'owner_id' => new FieldDefinition(
                    name: 'owner_id',
                    type: 'integer',
                    label: 'Owner',
                ),
                'secret' => new FieldDefinition(
                    name: 'secret',
                    type: 'string',
                    label: 'Secret',
                ),
            ],
            keys: RequiredFieldTestEntity::definitionKeys(),
            class: RequiredFieldTestEntity::class,
            label: 'Required Field',
        ));

        $account = $this->createMock(AccountInterface::class);
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'required_field';
            }

            public function fieldAccess(
                EntityInterface $entity,
                string $fieldName,
                string $operation,
                AccountInterface $account,
            ): AccessResult {
                if ($fieldName === 'secret' && $operation === 'view') {
                    return AccessResult::forbidden('secret is view-restricted');
                }

                return AccessResult::neutral();
            }
        };
        $handler = new EntityAccessHandler([$policy]);

        $controller = new SchemaController($manager, new SchemaPresenter(), $handler, $account);

        $doc = $controller->show('required_field');
        $array = $doc->toArray();

        // Availability restored: 200 with a schema, NOT the 500 backstop.
        $this->assertSame(200, $doc->statusCode);
        $this->assertArrayHasKey('meta', $array);
        $this->assertArrayHasKey('schema', $array['meta']);

        $properties = $array['meta']['schema']['properties'];
        // Filtering ran against the constructed prototype: the view-forbidden
        // field was removed, a non-restricted declared field remains.
        $this->assertArrayNotHasKey('secret', $properties);
        $this->assertArrayHasKey('owner_id', $properties);
    }

    /**
     * A normal entity type whose prototype constructs fine must still return
     * 200 with a filtered schema — the fail-closed fix must not regress the
     * happy path.
     */
    #[Test]
    public function showStillReturnsFilteredSchemaWhenPrototypeConstructionSucceeds(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $handler = new EntityAccessHandler([]);

        $controller = new SchemaController(
            $this->entityTypeManager,
            new SchemaPresenter(),
            $handler,
            $account,
        );

        $doc = $controller->show('article');
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertArrayHasKey('meta', $array);
        $this->assertArrayHasKey('schema', $array['meta']);
        $this->assertArrayHasKey('properties', $array['meta']['schema']);
        $this->assertArrayHasKey('title', $array['meta']['schema']['properties']);
    }
}
