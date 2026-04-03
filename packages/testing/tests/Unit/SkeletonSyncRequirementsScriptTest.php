<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SkeletonSyncRequirementsScriptTest extends TestCase
{
    #[Test]
    public function syncKeepsOnlyWaaseyaaPackagesDeclaredBySkeleton(): void
    {
        $tempDir = sys_get_temp_dir() . '/waaseyaa-sync-' . bin2hex(random_bytes(8));

        mkdir($tempDir, 0777, true);

        $skeletonComposer = $tempDir . '/skeleton.json';
        $targetComposer = $tempDir . '/target.json';
        $script = dirname(__DIR__, 4) . '/tools/sync-skeleton-requirements.php';

        file_put_contents($skeletonComposer, json_encode([
            'require' => [
                'php' => '>=8.4',
                'waaseyaa/foundation' => '^0.1',
                'waaseyaa/entity' => '^0.1',
                'waaseyaa/cache' => '^0.1',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($targetComposer, json_encode([
            'require' => [
                'php' => '>=8.4',
                'waaseyaa/foundation' => '^0.1',
                'waaseyaa/entity' => '^0.1',
                'waaseyaa/cache' => '^0.1',
                'waaseyaa/notification' => '^0.1',
                'waaseyaa/oauth-provider' => '^0.1',
                'waaseyaa/scheduler' => '^0.1',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        exec(sprintf(
            'php %s %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($skeletonComposer),
            escapeshellarg($targetComposer),
        ), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));

        /** @var array{require: array<string, string>} $updated */
        $updated = json_decode((string) file_get_contents($targetComposer), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            'php' => '>=8.4',
            'waaseyaa/cache' => '^0.1',
            'waaseyaa/entity' => '^0.1',
            'waaseyaa/foundation' => '^0.1',
        ], $updated['require']);

        self::assertArrayNotHasKey('waaseyaa/notification', $updated['require']);
        self::assertArrayNotHasKey('waaseyaa/oauth-provider', $updated['require']);
        self::assertArrayNotHasKey('waaseyaa/scheduler', $updated['require']);
    }
}
