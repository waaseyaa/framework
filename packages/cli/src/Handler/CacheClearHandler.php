<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\Cache\CacheFactoryInterface;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class CacheClearHandler
{
    private const array DEFAULT_BINS = ['default', 'render', 'discovery', 'config'];

    public function __construct(
        private readonly CacheFactoryInterface $cacheFactory,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $bin = $io->option('bin');
        $tagsOption = $io->option('tags');
        $tags = $this->parseTags($tagsOption);

        if ($tags !== []) {
            $targetBins = $bin !== null ? [(string) $bin] : self::DEFAULT_BINS;
            $invalidatedBins = 0;

            foreach ($targetBins as $binName) {
                $backend = $this->cacheFactory->get($binName);
                if (!$backend instanceof TagAwareCacheInterface) {
                    $io->writeln(sprintf('Cache bin "%s" is not tag-aware; skipping.', $binName));
                    continue;
                }

                $backend->invalidateByTags($tags);
                $io->writeln(sprintf(
                    'Cache bin "%s" invalidated by tags: %s',
                    $binName,
                    implode(', ', $tags),
                ));
                $invalidatedBins++;
            }

            if ($invalidatedBins === 0) {
                $io->writeln('No selected cache bins support tag invalidation.');
            }

            return 0;
        }

        if ($bin !== null) {
            $this->cacheFactory->get((string) $bin)->deleteAll();
            $io->writeln(sprintf('Cache bin "%s" cleared.', $bin));

            return 0;
        }

        foreach (self::DEFAULT_BINS as $binName) {
            $this->cacheFactory->get($binName)->deleteAll();
            $io->writeln(sprintf('Cache bin "%s" cleared.', $binName));
        }

        $io->writeln('All cache bins cleared.');

        return 0;
    }

    /**
     * @return list<string>
     */
    private function parseTags(mixed $tagsOption): array
    {
        if (!is_string($tagsOption) || trim($tagsOption) === '') {
            return [];
        }

        $tags = array_filter(
            array_map('trim', explode(',', $tagsOption)),
            static fn(string $t) => $t !== '',
        );

        return array_values($tags);
    }
}
