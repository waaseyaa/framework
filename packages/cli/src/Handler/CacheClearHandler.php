<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactoryInterface;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class CacheClearHandler
{
    public function __construct(
        private readonly CacheFactoryInterface $cacheFactory,
        private readonly CacheConfiguration $cacheConfiguration,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $bin = $io->option('bin');
        $tagsOption = $io->option('tags');
        $tags = $this->parseTags($tagsOption);

        if ($tags !== []) {
            return $this->invalidateByTags($io, is_string($bin) ? $bin : null, $tags);
        }

        if ($bin !== null) {
            return $this->clearOne($io, (string) $bin);
        }

        return $this->clearConfigured($io);
    }

    /**
     * @param list<string> $tags
     */
    private function invalidateByTags(SymfonyCommandIO $io, ?string $bin, array $tags): int
    {
        $targetBins = $bin !== null ? [$bin] : $this->cacheConfiguration->getConfiguredBins();
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

    /**
     * Clear a single, explicitly named bin.
     *
     * An unconfigured bin name is not silently reported as cleared: the
     * factory still hands back a usable (fresh, empty) default backend for
     * any name, so clearing it is truthfully a no-op against real state.
     * Reported as a failure with a non-zero exit rather than a false
     * "cleared" message (#3025).
     */
    private function clearOne(SymfonyCommandIO $io, string $bin): int
    {
        if (!in_array($bin, $this->cacheConfiguration->getConfiguredBins(), true)) {
            $io->writeln(sprintf('Cache bin "%s" is not configured; nothing to clear.', $bin));

            return 1;
        }

        try {
            $this->cacheFactory->get($bin)->deleteAll();
        } catch (\Throwable $e) {
            $io->writeln(sprintf('Cache bin "%s" failed to clear: %s', $bin, $e->getMessage()));

            return 1;
        }

        $io->writeln(sprintf('Cache bin "%s" cleared.', $bin));

        return 0;
    }

    /**
     * Clear every bin the application actually configures.
     *
     * Discovered from {@see CacheConfiguration::getConfiguredBins()} — the
     * canonical accessor — rather than a hand-kept default list, so a bin the
     * application registers is never silently skipped and a bin it does not
     * register is never falsely reported as cleared (#3025).
     *
     * One bin failing to clear does not abort the others: each bin is
     * attempted independently, failures are reported per bin, and the exit
     * status truthfully reflects total success (0), total failure (all bins
     * failed, non-zero), or partial failure (some cleared, some failed,
     * non-zero) — never a blanket "All cache bins cleared." when that is not
     * what happened.
     */
    private function clearConfigured(SymfonyCommandIO $io): int
    {
        $bins = $this->cacheConfiguration->getConfiguredBins();

        if ($bins === []) {
            $io->writeln('No cache bins are configured.');

            return 0;
        }

        $cleared = [];
        $failed = [];

        foreach ($bins as $binName) {
            try {
                $this->cacheFactory->get($binName)->deleteAll();
            } catch (\Throwable $e) {
                $io->writeln(sprintf('Cache bin "%s" failed to clear: %s', $binName, $e->getMessage()));
                $failed[] = $binName;
                continue;
            }

            $io->writeln(sprintf('Cache bin "%s" cleared.', $binName));
            $cleared[] = $binName;
        }

        if ($failed === []) {
            $io->writeln('All cache bins cleared.');

            return 0;
        }

        if ($cleared === []) {
            $io->writeln('No cache bins were cleared.');

            return 1;
        }

        $io->writeln(sprintf(
            'Partially cleared: %d of %d cache bins failed (%s).',
            count($failed),
            count($bins),
            implode(', ', $failed),
        ));

        return 1;
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
