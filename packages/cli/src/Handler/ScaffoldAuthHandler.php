<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

final class ScaffoldAuthHandler
{
    /** @var array<string, string> source (relative to packages/admin/app/) => dest (relative to app/) */
    private const FILE_MAP = [
        'pages/login.vue' => 'pages/login.vue',
        'components/auth/LoginForm.vue' => 'components/auth/LoginForm.vue',
        'components/auth/BrandPanel.vue' => 'components/auth/BrandPanel.vue',
        'composables/useAuth.ts' => 'composables/useAuth.ts',
        'assets/auth.css' => 'assets/auth.css',
    ];

    public function __construct(private readonly string $projectRoot) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $force = (bool) $io->option('force');
        $dryRun = (bool) $io->option('dry-run');

        $sourceBase = $this->projectRoot . '/packages/admin/app';
        $destBase = $this->projectRoot . '/app';

        $copied = 0;
        $skipped = 0;
        $checksums = [];

        foreach (self::FILE_MAP as $srcRel => $destRel) {
            $srcPath = $sourceBase . '/' . $srcRel;
            $destPath = $destBase . '/' . $destRel;

            if (!file_exists($srcPath)) {
                $io->writeln('<comment>MISSING source: ' . $srcRel . '</comment>');
                continue;
            }

            if (file_exists($destPath) && !$force) {
                $io->writeln('<comment>SKIP ' . $destRel . ' (already exists, use --force to overwrite)</comment>');
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $io->writeln('COPY ' . $srcRel . ' → ' . $destRel);
                continue;
            }

            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0o755, true);
            }

            copy($srcPath, $destPath);
            $checksums[$destRel] = (string) md5_file($destPath);
            ++$copied;
            $io->writeln('<info>COPY</info> ' . $destRel);
        }

        if (!$dryRun && $checksums !== []) {
            $this->writeManifest($destBase, $checksums);
        }

        $io->writeln('');
        if ($dryRun) {
            $io->writeln('<info>Dry run complete. No files written.</info>');
        } else {
            $io->writeln(sprintf('<info>Done. %d copied, %d skipped.</info>', $copied, $skipped));
            if ($copied > 0) {
                $io->writeln('You now own these files. Framework updates will no longer flow to them.');
            }
        }

        return 0;
    }

    /** @param array<string, string> $newChecksums */
    private function writeManifest(string $destBase, array $newChecksums): void
    {
        $manifestDir = $destBase . '/.waaseyaa';
        $manifestPath = $manifestDir . '/scaffold-manifest.json';

        if (!is_dir($manifestDir)) {
            mkdir($manifestDir, 0o755, true);
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
