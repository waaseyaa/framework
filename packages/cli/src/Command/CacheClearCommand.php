<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Cache\CacheFactoryInterface;
use Waaseyaa\Cache\TagAwareCacheInterface;

#[AsCommand(
    name: 'cache:clear',
    description: 'Clear one or all cache bins',
)]
class CacheClearCommand extends Command
{
    private const array DEFAULT_BINS = ['default', 'render', 'discovery', 'config'];

    public function __construct(
        private readonly CacheFactoryInterface $cacheFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'bin',
            'b',
            InputOption::VALUE_REQUIRED,
            'Clear a specific cache bin instead of all bins',
        );
        $this->addOption(
            'tags',
            null,
            InputOption::VALUE_REQUIRED,
            'Invalidate cache entries by comma-separated tags (requires a tag-aware backend)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bin = $input->getOption('bin');
        $tagsOption = $input->getOption('tags');
        $tags = $this->parseTags($tagsOption);

        if ($tags !== []) {
            $targetBins = $bin !== null ? [(string) $bin] : self::DEFAULT_BINS;
            $invalidatedBins = 0;

            foreach ($targetBins as $binName) {
                $backend = $this->cacheFactory->get($binName);
                if (!$backend instanceof TagAwareCacheInterface) {
                    $output->writeln(sprintf('Cache bin "%s" is not tag-aware; skipping.', $binName));
                    continue;
                }

                $backend->invalidateByTags($tags);
                $output->writeln(sprintf(
                    'Cache bin "%s" invalidated by tags: %s',
                    $binName,
                    implode(', ', $tags),
                ));
                $invalidatedBins++;
            }

            if ($invalidatedBins === 0) {
                $output->writeln('No selected cache bins support tag invalidation.');
            }

            return Command::SUCCESS;
        }

        if ($bin !== null) {
            $this->cacheFactory->get($bin)->deleteAll();
            $output->writeln(sprintf('Cache bin "%s" cleared.', $bin));

            return Command::SUCCESS;
        }

        foreach (self::DEFAULT_BINS as $binName) {
            $this->cacheFactory->get($binName)->deleteAll();
            $output->writeln(sprintf('Cache bin "%s" cleared.', $binName));
        }

        $output->writeln('All cache bins cleared.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function parseTags(mixed $tagsOption): array
    {
        if (!is_string($tagsOption) || trim($tagsOption) === '') {
            return [];
        }

        $tags = [];
        foreach (explode(',', $tagsOption) as $tag) {
            $trimmed = trim($tag);
            if ($trimmed !== '') {
                $tags[] = $trimmed;
            }
        }

        return array_values(array_unique($tags));
    }
}
