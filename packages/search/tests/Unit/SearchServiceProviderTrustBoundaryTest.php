<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Search\Fts5\Fts5SearchContentCatalogue;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchCandidateResolverRegistry;
use Waaseyaa\Search\SearchContentCatalogueInterface;
use Waaseyaa\Search\SearchIndexerInterface;
use Waaseyaa\Search\SearchServiceProvider;

#[CoversClass(SearchServiceProvider::class)]
final class SearchServiceProviderTrustBoundaryTest extends TestCase
{
    #[Test]
    public function configuredProjectionRejectsAnAlternateDatabaseDsn(): void
    {
        $provider = new SearchServiceProvider();
        $provider->setKernelContext('/tmp', [
            'search' => ['database' => 'postgresql://db/waaseyaa'],
        ], []);
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return null;
            }
        });
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S1-DB001');
        $provider->resolve(SearchIndexerInterface::class);
    }

    #[Test]
    public function configuredProjectionRejectsMemoryOutsideAnExplicitDevelopmentEnvironment(): void
    {
        $provider = new SearchServiceProvider();
        $provider->setKernelContext('/tmp', [
            'environment' => 'staging',
            'search' => ['database' => ':memory:'],
        ], []);
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return null;
            }
        });
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S1-DB002');
        $provider->resolve(SearchContentCatalogueInterface::class);
    }

    #[Test]
    public function configuredRelativeProjectionPathResolvesAgainstProjectRoot(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa-search-root-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        $provider = new SearchServiceProvider();
        $provider->setKernelContext($root, [
            'environment' => 'testing',
            'search' => ['database' => 'storage/search.sqlite'],
        ], []);
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return null;
            }
        });
        $provider->register();

        try {
            $provider->resolve(SearchIndexerInterface::class);
            self::assertFileExists($root . '/storage/search.sqlite');
        } finally {
            foreach (glob($root . '/storage/search.sqlite*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($root . '/storage');
            @rmdir($root);
        }
    }

    #[Test]
    public function production_wiring_resolves_the_fail_closed_candidate_registry(): void
    {
        $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
        $accessHandler = new EntityAccessHandler([]);
        $fieldReadScope = new AccountFieldReadScope();
        $database = DBALDatabase::createSqlite();
        $services = new class ($entityTypeManager, $accessHandler, $fieldReadScope, $database) implements KernelServicesInterface {
            public function __construct(
                private readonly EntityTypeManagerInterface $entityTypeManager,
                private readonly EntityAccessHandler $accessHandler,
                private readonly AccountFieldReadScopeInterface $fieldReadScope,
                private readonly DatabaseInterface $database,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    EntityTypeManagerInterface::class => $this->entityTypeManager,
                    EntityAccessHandler::class => $this->accessHandler,
                    AccountFieldReadScopeInterface::class => $this->fieldReadScope,
                    DatabaseInterface::class => $this->database,
                    default => null,
                };
            }
        };

        $provider = new SearchServiceProvider();
        $provider->setKernelServices($services);
        $provider->register();

        self::assertInstanceOf(
            SearchCandidateResolverRegistry::class,
            $provider->resolve(SearchCandidateResolverInterface::class),
        );
        self::assertInstanceOf(
            Fts5SearchContentCatalogue::class,
            $provider->resolve(SearchContentCatalogueInterface::class),
        );
    }
}
