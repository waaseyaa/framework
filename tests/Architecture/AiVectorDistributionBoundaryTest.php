<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiVectorDistributionBoundaryTest extends TestCase
{
    #[Test]
    public function framework_cli_and_full_do_not_install_ai_vector_at_runtime(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['composer.json', 'packages/cli/composer.json', 'packages/full/composer.json'] as $manifest) {
            $composer = json_decode((string) file_get_contents($root . '/' . $manifest), true, 512, JSON_THROW_ON_ERROR);

            self::assertArrayNotHasKey('waaseyaa/ai-vector', $composer['require'] ?? [], $manifest);
        }

        $cli = json_decode((string) file_get_contents($root . '/packages/cli/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $full = json_decode((string) file_get_contents($root . '/packages/full/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('waaseyaa/ai-vector', $cli['suggest']);
        self::assertArrayHasKey('waaseyaa/ai-vector', $full['suggest']);

        $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $runtimePackages = array_column($lock['packages'] ?? [], 'name');
        $developmentPackages = array_column($lock['packages-dev'] ?? [], 'name');
        self::assertNotContains('waaseyaa/ai-vector', $runtimePackages, 'root --no-dev installs must exclude ai-vector');
        self::assertContains('waaseyaa/ai-vector', $developmentPackages, 'the monorepo keeps ai-vector for development');
    }

    #[Test]
    public function fake_embedding_provider_is_dev_only(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root . '/packages/ai-vector/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertFileDoesNotExist($root . '/packages/ai-vector/src/Testing/FakeEmbeddingProvider.php');
        self::assertSame('testing/', $composer['autoload-dev']['psr-4']['Waaseyaa\\AI\\Vector\\Testing\\']);
        self::assertDirectoryExists($root . '/packages/ai-vector/testing');
    }
}
