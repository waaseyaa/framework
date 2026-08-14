<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\SqliteTopology;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Search\Access\EntitySearchCandidateResolver;
use Waaseyaa\Search\EventSubscriber\SearchIndexSubscriber;
use Waaseyaa\Search\Fts5\Fts5SearchContentCatalogue;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\Projection\EntitySearchProjectionRegistry;
use Waaseyaa\Search\Projection\NodeSearchProjector;

final class SearchServiceProvider extends ServiceProvider
{
    private ?DatabaseInterface $searchDatabase = null;

    public function register(): void
    {
        $this->singleton(SearchIndexerInterface::class, function (): SearchIndexerInterface {
            $database = $this->getSearchDatabase();

            // Do NOT call ensureSchema() here. Resolving the indexer at boot (to
            // wire the SearchIndexSubscriber) must not run DDL — the indexer
            // creates its schema lazily on first write instead. (D-35)
            return new Fts5SearchIndexer($database);
        });

        // #2270: one shared projection registry serves full reindex, the
        // lifecycle subscriber, and query-time candidate resolution, so
        // index-time and query-time semantics cannot drift. Application
        // projectors (ProvidesEntitySearchProjectorsInterface) are consulted
        // ahead of the built-in node default.
        $this->singleton(EntitySearchProjectionRegistry::class, function (): EntitySearchProjectionRegistry {
            $projectorProvider = $this->resolveOptional(ProvidesEntitySearchProjectorsInterface::class);
            if ($projectorProvider !== null && !$projectorProvider instanceof ProvidesEntitySearchProjectorsInterface) {
                throw new \LogicException('Search projector provider must implement ProvidesEntitySearchProjectorsInterface.');
            }

            return new EntitySearchProjectionRegistry([
                ...($projectorProvider?->entitySearchProjectors() ?? []),
                new NodeSearchProjector(),
            ]);
        });

        $this->singleton(SearchCandidateResolverInterface::class, function (): SearchCandidateResolverInterface {
            $entityTypeManager = $this->resolve(EntityTypeManagerInterface::class);
            if (!$entityTypeManager instanceof EntityTypeManagerInterface) {
                throw new \LogicException('Search requires EntityTypeManagerInterface.');
            }
            $accessHandler = $this->resolve(EntityAccessHandler::class);
            if (!$accessHandler instanceof EntityAccessHandler) {
                throw new \LogicException('Search requires EntityAccessHandler.');
            }
            $fieldReadScope = $this->resolve(AccountFieldReadScopeInterface::class);
            if (!$fieldReadScope instanceof AccountFieldReadScopeInterface) {
                throw new \LogicException('Search requires AccountFieldReadScopeInterface.');
            }
            $sourceProvider = $this->resolveOptional(ProvidesSearchSourceResolversInterface::class);
            if ($sourceProvider !== null && !$sourceProvider instanceof ProvidesSearchSourceResolversInterface) {
                throw new \LogicException('Search source resolver provider must implement ProvidesSearchSourceResolversInterface.');
            }
            $logger = $this->resolveOptional(\Waaseyaa\Foundation\Log\LoggerInterface::class);

            return new SearchCandidateResolverRegistry(
                $entityTypeManager,
                new EntitySearchCandidateResolver(
                    $entityTypeManager,
                    $accessHandler,
                    $fieldReadScope,
                    $this->projectionRegistry(),
                    $logger instanceof \Waaseyaa\Foundation\Log\LoggerInterface ? $logger : null,
                ),
                $sourceProvider?->searchSourceResolvers() ?? [],
            );
        });

        $this->singleton(SearchProviderInterface::class, function (): SearchProviderInterface {
            $logger = $this->resolveOptional(\Waaseyaa\Foundation\Log\LoggerInterface::class);

            return new Fts5SearchProvider(
                $this->getSearchDatabase(),
                $this->resolve(SearchIndexerInterface::class),
                candidateResolver: $this->resolve(SearchCandidateResolverInterface::class),
                logger: $logger instanceof \Waaseyaa\Foundation\Log\LoggerInterface ? $logger : null,
            );
        });

        $this->singleton(SearchContentCatalogueInterface::class, function (): SearchContentCatalogueInterface {
            return new Fts5SearchContentCatalogue(
                $this->getSearchDatabase(),
                $this->resolve(SearchCandidateResolverInterface::class),
            );
        });
    }

    public function boot(): void
    {
        $indexer = $this->resolve(SearchIndexerInterface::class);
        // CW-v1 option-1 (#1920 PR-2): resolved optionally, mirroring the
        // other collaborators' convention elsewhere in the framework — a
        // missing EntityTypeManagerInterface degrades the subscriber to its
        // pre-option-1 fallback (index the in-memory event entity directly),
        // never a boot crash.
        $entityTypeManager = $this->resolveOptional(EntityTypeManagerInterface::class);
        $subscriber = new SearchIndexSubscriber(
            $indexer,
            entityTypeManager: $entityTypeManager instanceof EntityTypeManagerInterface ? $entityTypeManager : null,
            projectionRegistry: $this->projectionRegistry(),
        );

        $dispatcher = $this->resolve(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if ($dispatcher instanceof EventDispatcherInterface) {
            $dispatcher->addSubscriber($subscriber);
        }
    }

    private function projectionRegistry(): EntitySearchProjectionRegistry
    {
        $registry = $this->resolve(EntitySearchProjectionRegistry::class);
        if (!$registry instanceof EntitySearchProjectionRegistry) {
            throw new \LogicException('Search requires its own EntitySearchProjectionRegistry binding.');
        }

        return $registry;
    }

    private function getSearchDatabase(): DatabaseInterface
    {
        if ($this->searchDatabase !== null) {
            return $this->searchDatabase;
        }

        $searchDb = $this->config['search']['database'] ?? null;
        $environment = SqliteTopology::resolveEnvironment($this->config);
        if ($searchDb !== null && !is_string($searchDb)) {
            throw new \RuntimeException('S1-DB001: Search database must be a local SQLite path string.');
        }
        if (is_string($searchDb)) {
            SqliteTopology::assertEnvironmentAllowsPath($searchDb, $environment);
            $searchDb = DatabaseBootstrapper::absolutize($searchDb, $this->projectRoot);
            $directory = dirname($searchDb);
            if ($searchDb !== ':memory:' && !is_dir($directory)
                && !@mkdir($directory, 0o755, recursive: true) && !is_dir($directory)
            ) {
                throw new \RuntimeException(sprintf(
                    'Failed to create the search projection directory "%s".',
                    $directory,
                ));
            }
        }

        $this->searchDatabase = $searchDb !== null
            ? DBALDatabase::createSqlite($searchDb, $environment)
            : $this->resolve(DatabaseInterface::class);

        return $this->searchDatabase;
    }
}
