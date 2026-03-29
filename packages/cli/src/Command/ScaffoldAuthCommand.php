<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:auth',
    description: 'Copy framework auth UI files into your app for customization',
)]
final class ScaffoldAuthCommand extends Command
{
    /** @var array<string, string> source (relative to packages/admin/app/) => dest (relative to app/) */
    private const FILE_MAP = [
        'pages/login.vue' => 'pages/login.vue',
        'components/auth/LoginForm.vue' => 'components/auth/LoginForm.vue',
        'components/auth/BrandPanel.vue' => 'components/auth/BrandPanel.vue',
        'composables/useAuth.ts' => 'composables/useAuth.ts',
        'assets/auth.css' => 'assets/auth.css',
    ];

    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be copied without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $sourceBase = $this->projectRoot . '/packages/admin/app';
        $destBase = $this->projectRoot . '/app';

        $copied = 0;
        $skipped = 0;
        $checksums = [];

        foreach (self::FILE_MAP as $srcRel => $destRel) {
            $srcPath = $sourceBase . '/' . $srcRel;
            $destPath = $destBase . '/' . $destRel;

            if (!file_exists($srcPath)) {
                $output->writeln('<comment>MISSING source: ' . $srcRel . '</comment>');
                continue;
            }

            if (file_exists($destPath) && !$force) {
                $output->writeln('<comment>SKIP ' . $destRel . ' (already exists, use --force to overwrite)</comment>');
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $output->writeln('COPY ' . $srcRel . ' → ' . $destRel);
                continue;
            }

            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            copy($srcPath, $destPath);
            $checksums[$destRel] = md5_file($destPath);
            ++$copied;
            $output->writeln('<info>COPY</info> ' . $destRel);
        }

        if (!$dryRun && $checksums !== []) {
            $this->writeManifest($destBase, $checksums);
        }

        $output->writeln('');
        if ($dryRun) {
            $output->writeln('<info>Dry run complete. No files written.</info>');
        } else {
            $output->writeln(sprintf('<info>Done. %d copied, %d skipped.</info>', $copied, $skipped));
            if ($copied > 0) {
                $output->writeln('You now own these files. Framework updates will no longer flow to them.');
            }
        }

        return Command::SUCCESS;
    }

    /** @param array<string, string> $newChecksums */
    private function writeManifest(string $destBase, array $newChecksums): void
    {
        $manifestDir = $destBase . '/.waaseyaa';
        $manifestPath = $manifestDir . '/scaffold-manifest.json';

        if (!is_dir($manifestDir)) {
            mkdir($manifestDir, 0755, true);
        }

        $existing = [];
        if (file_exists($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $merged = array_merge($existing, $newChecksums);
        ksort($merged);

        $tmp = $manifestPath . '.tmp';
        file_put_contents($tmp, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($tmp, $manifestPath);
    }
}
