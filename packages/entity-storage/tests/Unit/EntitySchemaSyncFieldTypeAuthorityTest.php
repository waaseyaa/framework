<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Hydration\EntityInstantiator;
use Waaseyaa\EntityStorage\Hydration\SqlColumnTranslationHydrator;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\Tests\Fixtures\ExtensionFieldTypeFixture;

/**
 * Schema synchronization consumes the registry that admitted the fields
 * (#2786 B1): a downstream plugin admitted by the kernel's registry is
 * materialized by `schema:sync`, and an explicit registry override wins.
 */
#[CoversClass(EntitySchemaSync::class)]
#[CoversClass(SqlColumnTranslationHydrator::class)]
final class EntitySchemaSyncFieldTypeAuthorityTest extends TestCase
{
    private DBALDatabase $database;
    private FieldTypeManager $fieldTypes;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:');
        $this->fieldTypes = FieldTypeManager::fromManifest([
            'sync_money' => ExtensionFieldTypeFixture::declare('sync_money'),
        ]);
    }

    #[Test]
    public function sync_projects_extension_types_through_the_registry_that_admitted_them(): void
    {
        $registry = new FieldDefinitionRegistry($this->fieldTypes);
        $type = $this->ledger();
        $registry->registerCoreFields('sync_money_ledger', $type->getFieldDefinitions());

        new EntitySchemaSync($this->database, $registry)->syncAll([$type]);

        self::assertTrue($this->database->schema()->fieldExists('sync_money_ledger', 'price'));
    }

    #[Test]
    public function an_explicit_registry_overrides_the_field_registry_derivation(): void
    {
        $registry = new FieldDefinitionRegistry($this->fieldTypes);
        $type = $this->ledger();
        $registry->registerCoreFields('sync_money_ledger', $type->getFieldDefinitions());

        $this->expectException(UnknownFieldTypeException::class);
        new EntitySchemaSync($this->database, $registry, fieldTypes: new FieldTypeManager())->syncAll([$type]);
    }

    #[Test]
    public function translation_hydrator_threads_the_registry_into_its_translation_handler(): void
    {
        $parameters = new \ReflectionMethod(SqlColumnTranslationHydrator::class, '__construct')->getParameters();
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $parameters);

        self::assertContains('fieldTypes', $names, 'the hydrator must accept the boot-scoped registry');

        $type = $this->ledger();
        $hydrator = new SqlColumnTranslationHydrator(
            $this->database,
            $type,
            new EntityInstantiator($type),
            fieldTypes: $this->fieldTypes,
        );
        self::assertInstanceOf(SqlColumnTranslationHydrator::class, $hydrator);
    }

    private function ledger(): EntityType
    {
        // sql-column: entity-level fields become real typed columns, so the
        // column projection (and therefore the registry) is exercised by DDL.
        return new EntityType(
            id: 'sync_money_ledger',
            label: 'Ledger',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            primaryStorageBackend: 'sql-column',
            _fieldDefinitions: ['price' => ['type' => 'sync_money', 'label' => 'Price']],
        );
    }
}
