<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\GraphQL\Schema\SchemaFactory;
use Waaseyaa\GraphQL\Tests\Fixtures\AttributeFirstEntities\ArticleSchemaFixture;

require_once __DIR__ . '/../../Fixtures/AttributeFirstEntities/ArticleSchemaFixture.php';

/** Regression coverage for architecture-integrity issue #2764. */
#[CoversClass(SchemaFactory::class)]
final class SchemaFactoryCompositionCacheTest extends TestCase
{
    protected function setUp(): void
    {
        SchemaFactory::resetCache();
    }

    #[Test]
    public function sequential_compositions_with_the_same_type_id_keep_their_own_fields(): void
    {
        $firstManager = $this->managerWithFields(['first_only']);
        $secondManager = $this->managerWithFields(['second_only']);

        $first = new SchemaFactory($firstManager)->build();
        $second = new SchemaFactory($secondManager)->build();

        self::assertNotSame($first, $second);

        $firstType = $first->getType('AuditArticle');
        $secondType = $second->getType('AuditArticle');

        self::assertNotNull($firstType);
        self::assertNotNull($secondType);
        self::assertTrue($firstType->hasField('first_only'));
        self::assertFalse($firstType->hasField('second_only'));
        self::assertFalse($secondType->hasField('first_only'));
        self::assertTrue($secondType->hasField('second_only'));
    }

    #[Test]
    public function sequential_compositions_with_the_same_bundle_keep_their_own_bundle_fields(): void
    {
        $firstManager = $this->managerWithBundleField('first_bundle_only');
        $secondManager = $this->managerWithBundleField('second_bundle_only');

        $first = new SchemaFactory($firstManager)->build();
        $second = new SchemaFactory($secondManager)->build();

        self::assertNotSame($first, $second);

        $firstType = $first->getType('AuditArticlePage');
        $secondType = $second->getType('AuditArticlePage');

        self::assertNotNull($firstType);
        self::assertNotNull($secondType);
        self::assertTrue($firstType->hasField('first_bundle_only'));
        self::assertFalse($firstType->hasField('second_bundle_only'));
        self::assertFalse($secondType->hasField('first_bundle_only'));
        self::assertTrue($secondType->hasField('second_bundle_only'));
    }

    #[Test]
    public function an_override_name_does_not_hide_a_changed_resolver(): void
    {
        $manager = $this->managerWithFields(['title']);

        $first = (new SchemaFactory($manager))->withMutationOverrides([
            'createAuditArticle' => [
                'resolve' => static fn(): array => ['source' => 'first'],
            ],
        ])->build();
        $second = (new SchemaFactory($manager))->withMutationOverrides([
            'createAuditArticle' => [
                'resolve' => static fn(): array => ['source' => 'second'],
            ],
        ])->build();

        self::assertNotSame($first, $second);

        $firstResolver = $first->getMutationType()?->getField('createAuditArticle')->resolveFn;
        $secondResolver = $second->getMutationType()?->getField('createAuditArticle')->resolveFn;

        self::assertIsCallable($firstResolver);
        self::assertIsCallable($secondResolver);
        self::assertSame(['source' => 'first'], $firstResolver());
        self::assertSame(['source' => 'second'], $secondResolver());
    }

    #[Test]
    public function the_cache_does_not_retain_a_finished_composition(): void
    {
        $manager = $this->managerWithFields(['title']);
        $reference = \WeakReference::create($manager);
        $schema = new SchemaFactory($manager)->build();

        unset($schema, $manager);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    #[Test]
    public function a_field_registry_change_invalidates_the_compositions_cached_shape(): void
    {
        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(
            new EventDispatcher(),
            fieldRegistry: $registry,
        );
        $manager->registerCoreEntityType(new EntityType(
            id: 'audit_article',
            label: 'Audit Article',
            class: ArticleSchemaFixture::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            _fieldDefinitions: [
                'first' => new FieldDefinition(
                    name: 'first',
                    type: 'string',
                    targetEntityTypeId: 'audit_article',
                ),
            ],
        ));
        $factory = new SchemaFactory($manager);

        $before = $factory->build();
        $beforeType = $before->getType('AuditArticle');
        self::assertNotNull($beforeType);
        self::assertFalse($beforeType->hasField('second'));

        $registry->mergeCoreFields('audit_article', [
            'second' => new FieldDefinition(
                name: 'second',
                type: 'string',
                targetEntityTypeId: 'audit_article',
            ),
        ]);
        $after = $factory->build();

        self::assertNotSame($before, $after);
        self::assertFalse($beforeType->hasField('second'));
        self::assertTrue($after->getType('AuditArticle')?->hasField('second') ?? false);
    }

    /** @param list<string> $fieldNames */
    private function managerWithFields(array $fieldNames): EntityTypeManager
    {
        $fields = [];
        foreach ($fieldNames as $fieldName) {
            $fields[$fieldName] = new FieldDefinition(name: $fieldName, type: 'string');
        }

        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerCoreEntityType(new EntityType(
            id: 'audit_article',
            label: 'Audit Article',
            class: ArticleSchemaFixture::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            _fieldDefinitions: $fields,
        ));

        return $manager;
    }

    private function managerWithBundleField(string $fieldName): EntityTypeManager
    {
        $bundle = $this->createStub(EntityInterface::class);
        $bundle->method('id')->willReturn('page');
        $bundleRepository = $this->createStub(EntityRepositoryInterface::class);
        $bundleRepository->method('findBy')->willReturn([$bundle]);

        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(
            new EventDispatcher(),
            repositoryFactory: static fn(): EntityRepositoryInterface => $bundleRepository,
            fieldRegistry: $registry,
        );
        $manager->registerCoreEntityType(new EntityType(
            id: 'audit_article_type',
            label: 'Audit Article Type',
            class: ArticleSchemaFixture::class,
            keys: ['id' => 'id'],
        ));
        $manager->registerCoreEntityType(new EntityType(
            id: 'audit_article',
            label: 'Audit Article',
            class: ArticleSchemaFixture::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            bundleEntityType: 'audit_article_type',
        ));
        $registry->registerBundleFields('audit_article', 'page', [
            $fieldName => new FieldDefinition(
                name: $fieldName,
                type: 'string',
                targetEntityTypeId: 'audit_article',
                targetBundle: 'page',
            ),
        ]);

        return $manager;
    }
}
