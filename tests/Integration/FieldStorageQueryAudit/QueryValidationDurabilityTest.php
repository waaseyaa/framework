<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FieldStorageQueryAudit;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Audit\Writer\DatabaseStrictFieldStorageGatewayAudit;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Backend\BackendRegistrarFactory;
use Waaseyaa\EntityStorage\Backend\FrameworkFieldStorageBackendProvider;
use Waaseyaa\EntityStorage\BackendResolver;
use Waaseyaa\EntityStorage\Query\DefinitionValidator;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

final class QueryValidationDurabilityTest extends TestCase
{
    public function test_validation_groups_durability_without_losing_field_events(): void
    {
        $logger = new class extends AbstractLogger {
            public array $events = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->events[] = (string) $message;
            }
        };
        $config = new Configuration();
        $config->setMiddlewares([new Middleware($logger)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $database = new DBALDatabase($connection);
        RuntimeSchemaMigrations::audit($database);
        $registrar = new BackendRegistrarFactory(
            [FrameworkFieldStorageBackendProvider::class],
            gatewayAudit: new DatabaseStrictFieldStorageGatewayAudit($database),
        )->create();
        $registrar->build();
        $manager = new EntityTypeManager(new EventDispatcher());
        for ($type = 0; $type < 3; ++$type) {
            $fields = [];
            for ($field = 0; $field < 20; ++$field) {
                $fields['field_' . $field] = new FieldDefinition(name: 'field_' . $field, type: 'string')->storedIn('sql-column')->indexed();
            }
            $manager->registerEntityType(new EntityType(id: 'type_' . $type, label: 'Synthetic', class: \stdClass::class, _fieldDefinitions: $fields));
        }
        $logger->events = [];
        new DefinitionValidator($manager, new BackendResolver($registrar))->validateAll();
        $counts = array_count_values($logger->events);
        self::assertSame(2, $counts['Committing transaction'] ?? 0, 'Reservations and outcomes each need one durable commit, not 120 autocommits.');
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(60, (int) $connection->fetchOne("SELECT COUNT(*) FROM privileged_read_ledger WHERE event_type = 'reserved'"));
        self::assertSame(60, (int) $connection->fetchOne("SELECT COUNT(*) FROM privileged_read_ledger WHERE event_type = 'finalized' AND outcome = 'succeeded'"));
    }
}
