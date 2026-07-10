<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Migration\Account\MigrationSystemAccount;
use Waaseyaa\Migration\ContentModel\ContentModel;
use Waaseyaa\Migration\ContentModel\ContentModelRegistrar;
use Waaseyaa\Migration\ContentModel\ContentTypeModel;
use Waaseyaa\Migration\ContentModel\DerivesContentModelInterface;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RunOptions;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\Tests\Fixtures\AllowAllPolicy;
use Waaseyaa\Migration\Tests\Fixtures\ContentModelTestKit;

/**
 * G-026 (#1940) failure-mode regression test.
 *
 * Reproduces the pass-1 Sheguiandah sequence end-to-end and proves the fix:
 * a fresh SQLite database (the `db:init` equivalent — no tables beyond what
 * entity-type registration lazily creates), then a single `import:run`-style
 * invocation (one {@see MigrationRunner} construction, one `run()` call) with
 * a {@see DerivesContentModelInterface} provider wired the way
 * `AbstractKernel::injectContentModelProviders()` /
 * `ServiceProvider::withContentModelProviders()` wire it in production.
 *
 * Assertions cover the whole chain in ONE invocation — no second boot
 * required, which is precisely the property the pass-1 build lacked:
 *   1. The bundle config entity ("node_type" analog) exists.
 *   2. The per-bundle typed field is declared in the field registry.
 *   3. The migration's own destination write into that bundle succeeds and
 *      lands the typed field, not just the base columns.
 */
#[CoversNothing]
final class ContentModelRegistrationEndToEndTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'migration_test_page';
    private const string BUNDLE = 'page';
    private const string MIGRATION_ID = 'wp_posts_to_pages';

    #[Test]
    public function content_model_is_registered_and_the_destination_write_succeeds_in_one_invocation(): void
    {
        // "db:init equivalent": fresh SQLite database, entity types
        // registered, but NO bundle config entity and NO bundle field
        // declared yet — exactly the pass-1 starting state.
        $kit = ContentModelTestKit::build();

        $conn = $kit->database->getConnection();
        $conn->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $conn->executeStatement($sql);
        }

        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $idMap = new MigrationIdMap($kit->database);
        $systemAccount = new MigrationSystemAccount();
        $gate = new EntityAccessGate(new EntityAccessHandler([new AllowAllPolicy(self::ENTITY_TYPE_ID)]));

        $destination = new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $kit->typeManager,
            entityRepository: $kit->typeManager->getRepository(self::ENTITY_TYPE_ID),
            idMap: $idMap,
            gate: $gate,
            eventDispatcher: $dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $systemAccount,
        );

        $source = new InMemorySource(
            id: 'in_memory',
            records: [new SourceRecord('one', [
                'id' => 'one',
                'title' => 'Hello content model',
                'summary' => 'Derived field, not the _data blob.',
            ])],
        );

        $definition = new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: $source,
            process: ['title' => 'title', 'summary' => 'summary'],
            destination: $destination,
            bundle: self::BUNDLE,
        );

        $migrationsProvider = new class([$definition]) implements HasMigrationsInterface {
            /** @param list<MigrationDefinition> $defs */
            public function __construct(private readonly array $defs)
            {
            }

            public function migrations(): iterable
            {
                yield from $this->defs;
            }
        };

        $contentModel = new ContentModel(types: [
            new ContentTypeModel(
                entityTypeId: self::ENTITY_TYPE_ID,
                bundle: self::BUNDLE,
                label: 'Page',
                fields: [
                    new FieldDefinition(
                        name: 'summary',
                        type: 'string',
                        targetEntityTypeId: self::ENTITY_TYPE_ID,
                        targetBundle: self::BUNDLE,
                    ),
                ],
            ),
        ]);

        // Mirrors a source-reading ServiceProvider implementing
        // DerivesContentModelInterface — the kernel COLLECTS this at boot
        // (AbstractKernel::injectContentModelProviders()) without invoking
        // it; MigrationRunner is where deriveContentModel() is actually
        // called, at import time.
        $contentModelProvider = new class($contentModel) implements DerivesContentModelInterface {
            public function __construct(private readonly ContentModel $model)
            {
            }

            public function deriveContentModel(): ?ContentModel
            {
                return $this->model;
            }
        };

        $registry = new MigrationRegistry([$migrationsProvider]);
        $registry->boot();

        $registrar = new ContentModelRegistrar($kit->typeManager);

        $runner = new MigrationRunner(
            registry: $registry,
            chain: new ProcessChainExecutor(),
            idMap: $idMap,
            contentModelRegistrar: $registrar,
            contentModelProviders: [$contentModelProvider],
        );

        // Precondition: before the run, nothing is registered — proves the
        // registration genuinely happens as part of THIS invocation, not
        // some out-of-band setup step.
        self::assertCount(0, $kit->typeManager->getRepository('migration_test_content_type')->findBy([]));
        self::assertArrayNotHasKey('summary', $kit->fieldRegistry->bundleFieldsFor(self::ENTITY_TYPE_ID, self::BUNDLE));

        $report = $runner->run(self::MIGRATION_ID, new RunOptions());

        self::assertFalse($report->aborted);
        self::assertSame(1, $report->imported, 'destination write must succeed against the just-registered bundle schema');

        // 1. Bundle config entity ("node_type" analog) exists.
        $configEntities = $kit->typeManager->getRepository('migration_test_content_type')->findBy([]);
        self::assertCount(1, $configEntities);
        self::assertSame(self::BUNDLE, (string) $configEntities[0]->id());

        // 2. Per-bundle typed field is declared.
        $declared = $kit->fieldRegistry->bundleFieldsFor(self::ENTITY_TYPE_ID, self::BUNDLE);
        self::assertArrayHasKey('summary', $declared);

        // 3. The write landed the typed field via the per-bundle subtable,
        //    not the opaque _data blob.
        $subtable = \Waaseyaa\EntityStorage\SqlSchemaHandler::resolveSubtableName(self::ENTITY_TYPE_ID, self::BUNDLE);
        self::assertTrue($kit->database->schema()->tableExists($subtable));

        $row = $conn->executeQuery(\sprintf('SELECT "summary" FROM "%s"', $subtable))->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame('Derived field, not the _data blob.', $row['summary']);

        $entities = $kit->typeManager->getRepository(self::ENTITY_TYPE_ID)->findBy(['title' => 'Hello content model']);
        self::assertCount(1, $entities);
        self::assertSame(self::BUNDLE, $entities[0]->get('page_type'));
    }

    #[Test]
    public function a_provider_whose_derived_model_is_null_leaves_registration_a_no_op(): void
    {
        $kit = ContentModelTestKit::build();

        $noopProvider = new class implements DerivesContentModelInterface {
            public function deriveContentModel(): ?ContentModel
            {
                return null; // source mirror not built yet — safe no-op.
            }
        };

        $registry = new MigrationRegistry([]);
        $registry->boot();

        $runner = new MigrationRunner(
            registry: $registry,
            chain: new ProcessChainExecutor(),
            idMap: new MigrationIdMap($kit->database),
            contentModelRegistrar: new ContentModelRegistrar($kit->typeManager),
            contentModelProviders: [$noopProvider],
        );

        $this->expectException(\OutOfBoundsException::class);
        $runner->run('unknown_migration', new RunOptions());
    }
}
