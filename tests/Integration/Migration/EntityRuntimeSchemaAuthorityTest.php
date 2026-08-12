<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Migration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Log\Handler\ErrorLogHandler;
use Waaseyaa\Foundation\Log\LogManager;

/** Retained-red proof that entity DDL is still reachable outside the coordinator. */
final class EntityRuntimeSchemaAuthorityTest extends TestCase
{
    #[Test]
    public function direct_schema_handler_refuses_uncoordinated_ddl(): void
    {
        $database = DBALDatabase::createSqlite();

        try {
            new SqlSchemaHandler($this->entityType(), $database)->ensureTable();
            self::fail('Direct entity-schema mutation must require the schema coordinator.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB107', $exception->getMessage());
        }

        self::assertFalse($database->schema()->tableExists('authority_probe'));
    }

    #[Test]
    public function schema_sync_runner_refuses_an_uncoordinated_write(): void
    {
        $database = DBALDatabase::createSqlite();

        try {
            new EntitySchemaSyncRunner($database)->run([$this->entityType()]);
            self::fail('Entity schema sync must execute through the schema coordinator.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB107', $exception->getMessage());
        }

        self::assertFalse($database->schema()->tableExists('authority_probe'));
    }

    #[Test]
    public function repository_resolution_is_read_only_when_entity_schema_is_missing(): void
    {
        $database = DBALDatabase::createSqlite();
        $fieldRegistry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManagerFactory()->build(
            database: $database,
            dispatcher: new SymfonyEventDispatcherAdapter(),
            fieldRegistry: $fieldRegistry,
            logger: new LogManager(new ErrorLogHandler()),
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn() => null,
            accountContextAttacher: static function (object $repository): void {},
            fieldReadScope: new AccountFieldReadScope(),
        );
        $manager->registerEntityType($this->entityType());

        try {
            $manager->getRepository('authority_probe');
            self::fail('Repository resolution must validate schema without creating it.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB106', $exception->getMessage());
        }

        self::assertFalse($database->schema()->tableExists('authority_probe'));
    }

    private function entityType(): EntityType
    {
        return new EntityType(
            id: 'authority_probe',
            label: 'Authority probe',
            class: \stdClass::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
        );
    }
}
