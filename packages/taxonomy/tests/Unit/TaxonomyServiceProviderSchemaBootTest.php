<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\ForeignKeySchemaInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Taxonomy\TaxonomyServiceProvider;
use Waaseyaa\Taxonomy\VocabularyReferenceConstraint;

/**
 * #2761: `TaxonomyServiceProvider::boot()` used to call
 * `VocabularyReferenceConstraint::ensure()` unconditionally — schema DDL on
 * every HTTP/kernel boot, including production. Reuses the #2478
 * no-request-DDL contract (mirrors AttachmentServiceProviderTest): local/dev
 * boot may still materialize the foreign key for convenience; production and
 * staging boot must not, and an explicitly blank/invalid environment must
 * not fall back to the process APP_ENV to select the convenience path.
 */
#[CoversClass(TaxonomyServiceProvider::class)]
final class TaxonomyServiceProviderSchemaBootTest extends TestCase
{
    #[Test]
    public function productionBootDoesNotMaterializeTheVocabularyForeignKey(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createBareTaxonomyTables($database);

        $provider = new TaxonomyServiceProvider();
        $provider->setKernelContext('/tmp', ['environment' => 'production'], []);
        $provider->setKernelServices($this->kernelServices([
            DatabaseInterface::class => $database,
        ]));
        $provider->register();
        $provider->boot();

        $schema = $database->schema();
        self::assertInstanceOf(ForeignKeySchemaInterface::class, $schema);
        self::assertFalse(
            $schema->foreignKeyExists('taxonomy_term', VocabularyReferenceConstraint::NAME),
            'Production boot must not create the vocabulary foreign key (#2761).',
        );
    }

    #[Test]
    public function developmentBootStillMaterializesTheVocabularyForeignKeyForConvenience(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createBareTaxonomyTables($database);

        $provider = new TaxonomyServiceProvider();
        $provider->setKernelContext('/tmp', ['environment' => 'local'], []);
        $provider->setKernelServices($this->kernelServices([
            DatabaseInterface::class => $database,
        ]));
        $provider->register();
        $provider->boot();

        $schema = $database->schema();
        self::assertInstanceOf(ForeignKeySchemaInterface::class, $schema);
        self::assertTrue(
            $schema->foreignKeyExists('taxonomy_term', VocabularyReferenceConstraint::NAME),
            'Local/development boot must still materialize the vocabulary foreign key for convenience.',
        );
    }

    #[Test]
    public function invalidExplicitEnvironmentFailsClosedDespiteLocalProcessFallback(): void
    {
        $originalEnvironment = getenv('APP_ENV');
        putenv('APP_ENV=local');

        try {
            $database = DBALDatabase::createSqlite();
            $this->createBareTaxonomyTables($database);

            $provider = new TaxonomyServiceProvider();
            $provider->setKernelContext('/tmp', ['environment' => null], []);
            $provider->setKernelServices($this->kernelServices([
                DatabaseInterface::class => $database,
            ]));
            $provider->register();
            $provider->boot();

            $schema = $database->schema();
            self::assertInstanceOf(ForeignKeySchemaInterface::class, $schema);
            self::assertFalse(
                $schema->foreignKeyExists('taxonomy_term', VocabularyReferenceConstraint::NAME),
                'A blank explicit environment config must resolve to production, not the process APP_ENV.',
            );
        } finally {
            $originalEnvironment === false
                ? putenv('APP_ENV')
                : putenv('APP_ENV=' . $originalEnvironment);
        }
    }

    #[Test]
    public function bootWithoutDatabaseIsANoOp(): void
    {
        $provider = new TaxonomyServiceProvider();
        $provider->setKernelContext('/tmp', ['environment' => 'local'], []);
        $provider->setKernelServices($this->kernelServices([]));
        $provider->register();

        $provider->boot();
        $this->addToAssertionCount(1);
    }

    private function createBareTaxonomyTables(DBALDatabase $database): void
    {
        $database->schema()->createTable('taxonomy_vocabulary', [
            'fields' => ['vid' => ['type' => 'varchar', 'length' => 128, 'not null' => true]],
            'primary key' => ['vid'],
        ]);
        $database->schema()->createTable('taxonomy_term', [
            'fields' => ['id' => ['type' => 'serial'], 'vid' => ['type' => 'varchar', 'length' => 128, 'not null' => true]],
            'primary key' => ['id'],
        ]);
    }

    /**
     * @param array<string, object> $services
     */
    private function kernelServices(array $services): KernelServicesInterface
    {
        return new class ($services) implements KernelServicesInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $abstract): ?object
            {
                return $this->services[$abstract] ?? null;
            }
        };
    }
}
