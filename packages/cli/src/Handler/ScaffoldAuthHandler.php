<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Scaffold\AuthUiScaffoldManager;

final class ScaffoldAuthHandler
{
    public function __construct(private readonly string $projectRoot) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $force = (bool) $io->option('force');
        $dryRun = (bool) $io->option('dry-run');
        $check = (bool) $io->option('check');
        $strict = (bool) $io->option('strict');
        $acceptCurrent = (bool) $io->option('accept-current');

        if ($strict && !$check) {
            $io->error('--strict may only be used with --check.');

            return 2;
        }
        if (($check ? 1 : 0) + ($acceptCurrent ? 1 : 0) + ($dryRun ? 1 : 0) > 1
            || (($check || $acceptCurrent) && $force)
        ) {
            $io->error('--check, --accept-current, --dry-run, and --force publishing modes cannot be combined.');

            return 2;
        }

        $manager = new AuthUiScaffoldManager($this->projectRoot);

        try {
            if ($check) {
                return $this->check($io, $manager, $strict);
            }
            if ($acceptCurrent) {
                $accepted = $manager->acceptCurrent();
                $io->writeln(sprintf('Accepted current reviewed auth UI baselines for %d file(s).', $accepted));
                $io->writeln('No application file was overwritten. Commit the reviewed files and scaffold manifest together.');

                return 0;
            }

            return $this->publish($io, $manager, $force, $dryRun);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return 1;
        }
    }

    private function check(SymfonyCommandIO $io, AuthUiScaffoldManager $manager, bool $strict): int
    {
        $report = $manager->inspect();
        if ($report['status'] === 'error') {
            $io->error((string) $report['error']);

            return 1;
        }
        if ($report['status'] === 'not-published') {
            $io->writeln('Auth UI is framework-owned; no published consumer files were detected.');

            return 0;
        }
        if ($report['legacy']) {
            $io->writeln('NOTICE legacy checksum manifest detected; review, then run scaffold:auth --accept-current to record Framework provenance.');
        }
        if ($report['findings'] === []) {
            $io->writeln('No auth UI scaffold drift detected.');

            return 0;
        }

        foreach ($report['findings'] as $finding) {
            $io->writeln(sprintf('%-18s %s — %s', $finding['state'], $finding['path'], $finding['detail']));
        }
        $counts = array_fill_keys(['added', 'removed', 'changed-upstream', 'changed-consumer', 'conflict'], 0);
        foreach ($report['findings'] as $finding) {
            if (array_key_exists($finding['state'], $counts)) {
                ++$counts[$finding['state']];
            }
        }
        $io->writeln(sprintf(
            'Summary: added=%d removed=%d changed-upstream=%d changed-consumer=%d conflict=%d current=%d',
            $counts['added'],
            $counts['removed'],
            $counts['changed-upstream'],
            $counts['changed-consumer'],
            $counts['conflict'],
            $report['current'],
        ));
        $io->writeln('Review upstream and consumer files manually; after merging, run scaffold:auth --accept-current.');
        $io->writeln('No application file was overwritten.');

        return $strict ? 1 : 0;
    }

    private function publish(
        SymfonyCommandIO $io,
        AuthUiScaffoldManager $manager,
        bool $force,
        bool $dryRun,
    ): int {
        $result = $manager->publish($force, $dryRun);

        foreach ($result['actions'] as $action) {
            if ($action['action'] === 'missing') {
                $io->writeln('<comment>MISSING source: ' . $action['source'] . '</comment>');
            } elseif ($action['action'] === 'skip') {
                $io->writeln('<comment>SKIP ' . $action['path'] . ' (already exists, use --force to overwrite)</comment>');
            } else {
                $io->writeln(($dryRun ? 'COPY ' . $action['source'] . ' → ' : '<info>COPY</info> ') . $action['path']);
            }
        }

        $io->writeln('');
        if ($dryRun) {
            $io->writeln('<info>Dry run complete. No files written.</info>');
        } else {
            $io->writeln(sprintf('<info>Done. %d copied, %d skipped.</info>', $result['copied'], $result['skipped']));
            if ($result['copied'] > 0) {
                $io->writeln('You now own these files. Framework updates will no longer flow to them.');
                $io->writeln('Run scaffold:auth --check to review Framework and consumer drift.');
            }
        }

        return 0;
    }
}
