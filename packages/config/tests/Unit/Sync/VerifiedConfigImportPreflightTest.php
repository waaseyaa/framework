<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Sync\ConfigImportPreflightException;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\VerifiedConfigImportPreflight;
use Waaseyaa\Config\Tests\Fixtures\VerifiedConfigBundleFixture;

final class VerifiedConfigImportPreflightTest extends TestCase
{
    #[Test]
    public function itRevalidatesAndReturnsTheExactCompleteDirectoryBundle(): void
    {
        [$directory, $preflight, $expectedGenerationId] = $this->fixture();
        try {
            $bundle = $preflight->assertReady(
                syncFiles: [],
                activeRefs: ['stale.input'],
                dryRun: true,
                deleteOrphans: true,
                noDependencyCheck: true,
            );

            self::assertSame($expectedGenerationId, $bundle->effectiveManifest->generationId);
            self::assertSame(['system.site'], array_map(
                static fn(ConfigSyncFile $file): string => $file->ref(),
                $bundle->files(),
            ));
        } finally {
            $this->removeFixture($directory);
        }
    }

    #[Test]
    public function itRejectsExactByteDriftAfterEnvelopeVerification(): void
    {
        [$directory, $preflight] = $this->fixture();
        try {
            file_put_contents($directory . '/system.site.yml', "# unauthenticated drift\n", \FILE_APPEND);

            $this->expectException(ConfigImportPreflightException::class);
            $this->expectExceptionMessage('does not authorize the current complete sync directory');
            $preflight->assertReady([], [], false, false, false);
        } finally {
            $this->removeFixture($directory);
        }
    }

    /** @return array{string, VerifiedConfigImportPreflight, string} */
    private function fixture(): array
    {
        $directory = sys_get_temp_dir() . '/waaseyaa_cfg03_preflight_' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);
        $source = ConfigSyncFile::writable(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: ['name' => 'Waaseyaa'],
            schemaId: 'fixture.replaced',
            schemaVersion: 1,
            schemaHash: 'sha256:' . str_repeat('a', 64),
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
        [$registry, $compatibility, $bundle] = VerifiedConfigBundleFixture::withAuthorities([$source]);
        foreach ($bundle->entries() as $entry) {
            file_put_contents($directory . '/' . $entry->file->filename(), $entry->exactBytes);
        }

        return [
            $directory,
            new VerifiedConfigImportPreflight(
                $directory,
                new ConfigSyncBundleValidator($registry),
                $registry,
                $compatibility,
                $bundle->verification,
            ),
            $bundle->effectiveManifest->generationId,
        ];
    }

    private function removeFixture(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($directory);
    }
}
