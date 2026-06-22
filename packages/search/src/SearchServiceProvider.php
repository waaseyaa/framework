<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
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

        $this->singleton(SearchProviderInterface::class, function (): SearchProviderInterface {
            return new Fts5SearchProvider(
                $this->getSearchDatabase(),
                $this->resolve(SearchIndexerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        $indexer = $this->resolve(SearchIndexerInterface::class);
        $subscriber = new SearchIndexSubscriber($indexer);

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
