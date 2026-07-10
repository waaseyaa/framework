<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\ContentModel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Migration\ContentModel\ContentModel;
use Waaseyaa\Migration\ContentModel\ContentModelRegistrar;
use Waaseyaa\Migration\ContentModel\ContentTypeModel;
use Waaseyaa\Migration\Exception\ContentModelRegistrationException;
use Waaseyaa\Migration\Tests\Fixtures\ContentModelTestKit;

/**
 * G-026 (#1940) — blessing + failure-semantics coverage for
 * {@see ContentModelRegistrar}: the one supported path for declaring
 * import-derived per-bundle content models.
 */
#[CoversClass(ContentModelRegistrar::class)]
final class ContentModelRegistrarTest extends TestCase
{
    #[Test]
    public function it_registers_the_bundle_config_entity_and_declares_typed_fields(): void
    {
        $kit = ContentModelTestKit::build();
        $registrar = new ContentModelRegistrar($kit->typeManager);

        $registrar->register($this->buildModel());

        $repository = $kit->typeManager->getRepository('migration_test_content_type');
        $configEntities = $repository->findBy([]);
        self::assertCount(1, $configEntities);
        self::assertSame('page', (string) $configEntities[0]->id());

        $registered = $kit->fieldRegistry->bundleFieldsFor('migration_test_page', 'page');
        self::assertArrayHasKey('summary', $registered);
        self::assertSame('string', $registered['summary']->getType());
    }

    #[Test]
    public function it_is_idempotent_across_repeated_registration(): void
    {
        $kit = ContentModelTestKit::build();
        $registrar = new ContentModelRegistrar($kit->typeManager);

        $registrar->register($this->buildModel());
        $registrar->register($this->buildModel());

        $repository = $kit->typeManager->getRepository('migration_test_content_type');
        self::assertCount(1, $repository->findBy([]));
    }

    #[Test]
    public function it_is_a_no_op_for_an_empty_model(): void
    {
        $kit = ContentModelTestKit::build();
        $registrar = new ContentModelRegistrar($kit->typeManager);

        $registrar->register(new ContentModel());

        $repository = $kit->typeManager->getRepository('migration_test_content_type');
        self::assertCount(0, $repository->findBy([]));
    }

    #[Test]
    public function it_throws_loudly_when_the_destination_entity_type_is_not_registered(): void
    {
        $kit = ContentModelTestKit::build();
        $registrar = new ContentModelRegistrar($kit->typeManager);

        $model = new ContentModel(types: [
            new ContentTypeModel(
                entityTypeId: 'no_such_entity_type',
                bundle: 'page',
                label: 'Page',
            ),
        ]);

        $this->expectException(ContentModelRegistrationException::class);
        $this->expectExceptionMessageMatches('/not registered/');

        $registrar->register($model);
    }

    #[Test]
    public function it_throws_loudly_when_a_field_declaration_is_rejected(): void
    {
        $kit = ContentModelTestKit::build();
        $registrar = new ContentModelRegistrar($kit->typeManager);

        // Wrong targetBundle — FieldDefinitionRegistry::registerBundleFields()
        // rejects this; the registrar must surface it, not swallow it.
        $badField = new FieldDefinition(
            name: 'summary',
            type: 'string',
            targetEntityTypeId: 'migration_test_page',
            targetBundle: 'a_different_bundle',
        );

        $model = new ContentModel(types: [
            new ContentTypeModel(
                entityTypeId: 'migration_test_page',
                bundle: 'page',
                label: 'Page',
                fields: [$badField],
            ),
        ]);

        $this->expectException(ContentModelRegistrationException::class);

        $registrar->register($model);
    }

    #[Test]
    public function it_throws_loudly_when_no_field_registry_is_configured(): void
    {
        $typeManager = new EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher());
        $typeManager->registerEntityType(ContentModelTestKit::pageContentType());
        $registrar = new ContentModelRegistrar($typeManager);

        $field = new FieldDefinition(
            name: 'summary',
            type: 'string',
            targetEntityTypeId: 'migration_test_page',
            targetBundle: 'page',
        );

        $model = new ContentModel(types: [
            new ContentTypeModel(
                entityTypeId: 'migration_test_page',
                bundle: 'page',
                label: 'Page',
                fields: [$field],
            ),
        ]);

        $this->expectException(ContentModelRegistrationException::class);

        $registrar->register($model);
    }

    #[Test]
    public function no_bundle_entity_type_declared_leaves_config_entity_registration_a_silent_no_op(): void
    {
        // migration_test_widget (from the existing bundle-threading fixture)
        // declares no bundleEntityType — a legitimate design, not a failure.
        $kit = ContentModelTestKit::build();
        $registrar = new ContentModelRegistrar($kit->typeManager);

        $kit->typeManager->registerEntityType(
            \Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidgetType::nonRevisionable(),
        );

        $model = new ContentModel(types: [
            new ContentTypeModel(
                entityTypeId: 'migration_test_widget',
                bundle: 'article',
                label: 'Article',
            ),
        ]);

        // Must not throw.
        $registrar->register($model);
        self::assertTrue(true);
    }

    private function buildModel(): ContentModel
    {
        $field = new FieldDefinition(
            name: 'summary',
            type: 'string',
            targetEntityTypeId: 'migration_test_page',
            targetBundle: 'page',
        );

        return new ContentModel(types: [
            new ContentTypeModel(
                entityTypeId: 'migration_test_page',
                bundle: 'page',
                label: 'Page',
                fields: [$field],
            ),
        ]);
    }
}
