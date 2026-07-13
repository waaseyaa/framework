<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Search\Access\EntitySearchAccessChecker;
use Waaseyaa\Search\Access\SearchAccessChecker;
use Waaseyaa\Search\EventSubscriber\SearchIndexSubscriber;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;

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

        $this->singleton(SearchAccessChecker::class, function (): SearchAccessChecker {
            return new EntitySearchAccessChecker(
                $this->resolve(EntityTypeManagerInterface::class),
                $this->resolve(EntityAccessHandler::class),
                $this->resolve(AccountContextInterface::class),
            );
        });

        $this->singleton(SearchProviderInterface::class, function (): SearchProviderInterface {
            return new Fts5SearchProvider(
                $this->getSearchDatabase(),
                $this->resolve(SearchIndexerInterface::class),
                accessChecker: $this->resolve(SearchAccessChecker::class),
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
        );

        $dispatcher = $this->resolve(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if ($dispatcher instanceof EventDispatcherInterface) {
            $dispatcher->addSubscriber($subscriber);
        }
    }

    private function getSearchDatabase(): DatabaseInterface
    {
        if ($this->searchDatabase !== null) {
            return $this->searchDatabase;
        }

        $searchDb = $this->config['search']['database'] ?? null;

        $this->searchDatabase = $searchDb !== null
            ? DBALDatabase::createSqlite($searchDb)
            : $this->resolve(DatabaseInterface::class);

        return $this->searchDatabase;
    }
}
