<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\StaleManifestException;

#[CoversClass(StaleManifestException::class)]
final class StaleManifestExceptionTest extends TestCase
{
    #[Test]
    public function exposes_missing_providers_manifest_path_and_recovery_command(): void
    {
        $exception = new StaleManifestException(
            missingProviders: ['App\\Provider\\MissingProvider'],
            manifestPath: '/tmp/project/storage/framework/packages.php',
            recoveryCommand: 'php bin/waaseyaa optimize:manifest',
        );

        $this->assertSame(['App\\Provider\\MissingProvider'], $exception->missingProviders());
        $this->assertSame('/tmp/project/storage/framework/packages.php', $exception->manifestPath());
        $this->assertSame('php bin/waaseyaa optimize:manifest', $exception->recoveryCommand());
        $this->assertStringContainsString('Package manifest is stale.', $exception->getMessage());
        $this->assertStringContainsString('App\\Provider\\MissingProvider', $exception->getMessage());
        $this->assertStringContainsString('/tmp/project/storage/framework/packages.php', $exception->getMessage());
        $this->assertStringContainsString('php bin/waaseyaa optimize:manifest', $exception->getMessage());
    }
}
