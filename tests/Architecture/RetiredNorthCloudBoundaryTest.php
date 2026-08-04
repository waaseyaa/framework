<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class RetiredNorthCloudBoundaryTest extends TestCase
{
    private const FORBIDDEN_ACTIVE_REFERENCES = [
        'Waaseyaa\\NorthCloud',
        'NorthCloudServiceProvider',
        'NcSync',
        'northcloud:sync',
        'NORTHCLOUD_',
        '/api/staff/nc-sync-status',
        '/staff/ingestion',
    ];

    public function testRetiredPackageAndActiveWiringAreAbsent(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertDirectoryDoesNotExist($root . '/packages/northcloud');

        $activeFiles = [
            $root . '/composer.json',
            $root . '/composer.lock',
            $root . '/packages/cli/composer.json',
            $root . '/.github/workflows/split.yml',
            $root . '/.symfony-import-allowlist.json',
            $root . '/bin/check-package-layers',
            $root . '/packages/admin/app/components/IngestSummaryWidget.vue',
        ];

        foreach ($activeFiles as $file) {
            $this->assertFileContainsNoRetiredReference($file);
        }

        $dist = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root . '/packages/admin-surface/dist',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($dist as $file) {
            if ($file->isFile()) {
                $this->assertFileContainsNoRetiredReference($file->getPathname());
            }
        }
    }

    private function assertFileContainsNoRetiredReference(string $file): void
    {
        $contents = file_get_contents($file);
        self::assertIsString($contents, sprintf('Could not read %s.', $file));

        foreach (self::FORBIDDEN_ACTIVE_REFERENCES as $reference) {
            self::assertStringNotContainsString(
                $reference,
                $contents,
                sprintf('Retired NorthCloud reference %s remains in %s.', $reference, $file),
            );
        }
    }
}
