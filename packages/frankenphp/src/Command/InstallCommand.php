<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Waaseyaa\FrankenPhp\Binary\AssetSelector;
use Waaseyaa\FrankenPhp\Binary\BinaryResolver;
use Waaseyaa\FrankenPhp\Binary\Installer;
use Waaseyaa\FrankenPhp\Binary\InstallOutcome;

/**
 * `frankenphp:install` — download the correct FrankenPHP binary for this
 * OS/arch into the project's managed path (`vendor/bin/frankenphp[.exe]`),
 * idempotently. After this the binary's location is never the operator's
 * problem: `waaseyaa dev` finds it automatically.
 *
 * Pure-PHP, cross-platform, no shell. Runs under SYSTEM PHP (it only fetches +
 * unpacks the frankenphp binary; it never invokes FrankenPHP's bundled php).
 */
final class InstallCommand extends Command
{
    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct('frankenphp:install');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Download the FrankenPHP binary for this OS/arch into vendor/bin (for `composer run dev`).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-download even if a managed binary already exists.')
            ->addOption('frankenphp-version', null, InputOption::VALUE_REQUIRED, 'FrankenPHP release tag to install (default: pinned ' . AssetSelector::DEFAULT_VERSION . '; or set FRANKENPHP_VERSION).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $version = $this->resolveVersion($input->getOption('frankenphp-version'));

        try {
            $outcome = self::performInstall($this->projectRoot, (bool) $input->getOption('force'), $version, $io);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->report($io, $outcome);

        return Command::SUCCESS;
    }

    /**
     * Reusable install routine (also called by `dev` when it offers to install).
     *
     * @throws \RuntimeException on unsupported platform, download, or checksum failure
     */
    public static function performInstall(string $projectRoot, bool $force, ?string $version, ?OutputInterface $output = null): InstallOutcome
    {
        $asset = new AssetSelector()->select(\PHP_OS_FAMILY, php_uname('m'), $version);

        if ($output !== null) {
            $output->writeln(sprintf(
                '<info>FrankenPHP %s</info> for <info>%s/%s</info> → asset <info>%s</info>',
                $asset->version,
                \PHP_OS_FAMILY,
                php_uname('m'),
                $asset->assetName,
            ));
        }

        $baseDir = BinaryResolver::managedBaseDir($projectRoot);

        return new Installer()->install($asset, $baseDir, $force);
    }

    private function resolveVersion(mixed $optionValue): ?string
    {
        if (is_string($optionValue) && $optionValue !== '') {
            return $optionValue;
        }
        $env = getenv('FRANKENPHP_VERSION');

        return is_string($env) && $env !== '' ? $env : null;
    }

    private function report(SymfonyStyle $io, InstallOutcome $outcome): void
    {
        if ($outcome->skipped) {
            $io->success(sprintf('FrankenPHP already installed at %s (use --force to re-download).', $outcome->path));

            return;
        }

        if (!$outcome->checksumVerified) {
            $io->warning('Could not verify the download checksum (GitHub API unreachable or rate-limited); installed unverified.');
        }

        $io->success(sprintf('FrankenPHP installed at %s. Run `composer run dev` to serve.', $outcome->path));
    }
}
