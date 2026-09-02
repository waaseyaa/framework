<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Field\Tests\Fixtures\ExtensionFieldTypeFixture;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * The kernel's boot-scoped field-type registry reaches every runtime schema
 * handler the factory builds (#2786 B1). A downstream plugin admitted by the
 * registry must therefore be projectable by the repository factory's
 * `SqlSchemaHandler`; the static built-in default would refuse it.
 */
#[CoversClass(EntityTypeManagerFactory::class)]
final class EntityTypeManagerFactoryFieldTypesTest extends TestCase
{
    private DBALDatabase $database;
    private FieldTypeManager $fieldTypes;
    private FieldDefinitionRegistry $fieldRegistry;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:');
        EntityMutationAuthoritySchema::ensure($this->database);
        $this->fieldTypes = FieldTypeManager::fromManifest([
            'factory_money' => ExtensionFieldTypeFixture::declare('factory_money'),
        ]);
        $this->fieldRegistry = new FieldDefinitionRegistry($this->fieldTypes);
    }

    #[Test]
    public function build_requires_the_boot_scoped_registry(): void
    {
        $parameter = new \ReflectionMethod(EntityTypeManagerFactory::class, 'build')->getParameters();
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $parameter);

        self::assertContains('fieldTypes', $names);
        $fieldTypes = $parameter[array_search('fieldTypes', $names, true)];
        self::assertFalse($fieldTypes->isOptional(), 'kernel wiring must pass the registry explicitly');
        self::assertSame(FieldTypeManagerInterface::class, (string) $fieldTypes->getType());
    }

    #[Test]
    public function repository_factory_projects_a_registry_admitted_extension_type(): void
    {
        $manager = $this->build($this->fieldTypes);
        $this->registerLedgerWithMoneyBundleField($manager);
        new EntitySchemaSync($this->database, $this->fieldRegistry)->syncAll([
            $manager->getDefinition('factory_money_ledger'),
        ]);

        self::assertTrue($this->database->schema()->fieldExists('factory_money_ledger__basic', 'price'));
        // getRepository() asserts the runtime schema through the factory's own
        // SqlSchemaHandler, whose bundle-subtable spec derives every column
        // through the registry it was handed.
        $manager->getRepository('factory_money_ledger');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function runtime_schema_assertion_consults_the_injected_registry(): void
    {
        $manager = $this->build($this->fieldTypes);
        $this->registerLedgerWithMoneyBundleField($manager);
        new EntitySchemaSync($this->database, $this->fieldRegistry)->syncAll([
            $manager->getDefinition('factory_money_ledger'),
        ]);

        $factory = new EntityTypeManagerFactory();
        $factory->assertRegisteredRuntimeSchemas(
            $this->database,
            $manager,
            $this->fieldRegistry,
            new NullLogger(),
            $this->fieldTypes,
        );
        $this->addToAssertionCount(1);

        // The built-ins-only registry cannot project the admitted plugin: the
        // assertion must refuse, proving the handler consults what it is handed.
        $this->expectException(UnknownFieldTypeException::class);
        $factory->assertRegisteredRuntimeSchemas(
            $this->database,
            $manager,
            $this->fieldRegistry,
            new NullLogger(),
            new FieldTypeManager(),
        );
    }

    private function registerLedgerWithMoneyBundleField(EntityTypeManager $manager): void
    {
        $manager->registerEntityType(new EntityType(
            id: 'factory_money_ledger',
            label: 'Ledger',
            class: \stdClass::class,
            keys: ['id' => 'id', 'bundle' => 'type'],
            bundleEntityType: 'factory_money_ledger_type',
        ));
        // Bundle fields are the runtime-asserted projection: SqlSchemaHandler
        // derives every bundle-subtable column at assertion time.
        $manager->addBundleFields('factory_money_ledger', 'basic', [
            new FieldDefinition(
                'price',
                'factory_money',
                targetEntityTypeId: 'factory_money_ledger',
                targetBundle: 'basic',
                label: 'Price',
            ),
        ]);
    }

    private function build(FieldTypeManagerInterface $fieldTypes): EntityTypeManager
    {
        return new EntityTypeManagerFactory()->build(
            database: $this->database,
            dispatcher: new SymfonyEventDispatcherAdapter(),
            fieldRegistry: $this->fieldRegistry,
            logger: new NullLogger(),
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn() => null,
            accountContextAttacher: static function (object $repository): void {},
            fieldReadScope: new AccountFieldReadScope(),
            fieldTypes: $fieldTypes,
        );
    }
}
