<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SkeletonLayoutTest extends TestCase
{
    #[Test]
    public function skeletonPhpUnitDirectoriesExist(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $config = simplexml_load_file($repoRoot . '/skeleton/phpunit.xml.dist');

        self::assertInstanceOf(\SimpleXMLElement::class, $config);

        $directories = $config->xpath('//testsuite/directory');

        self::assertIsArray($directories);
        self::assertNotEmpty($directories);

        foreach ($directories as $directory) {
            $path = $repoRoot . '/skeleton/' . trim((string) $directory);
            self::assertDirectoryExists($path, sprintf('Skeleton PHPUnit path missing: %s', $path));
        }
    }

    #[Test]
    public function skeletonDevScriptIsWiredAndValid(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $devScript = $repoRoot . '/skeleton/bin/dev.sh';
        $composerJson = $repoRoot . '/skeleton/composer.json';

        self::assertFileExists($devScript);
        self::assertFileExists($composerJson);

        $composer = json_decode((string) file_get_contents($composerJson), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        $scripts = $composer['scripts'] ?? null;
        self::assertIsArray($scripts);

        $dev = $scripts['dev'] ?? null;
        self::assertIsArray($dev);
        self::assertContains('Composer\\Config::disableProcessTimeout', $dev);
        self::assertContains('bash bin/dev.sh', $dev);

        $command = sprintf('bash -n %s 2>&1', escapeshellarg('bin/dev.sh'));
        exec('cd ' . escapeshellarg($repoRoot . '/skeleton') . ' && ' . $command, $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    #[Test]
    public function skeletonIncludesEssentialFirstBootFiles(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $requiredFiles = [
            '/skeleton/.env.example',
            '/skeleton/bin/post-create-setup.php',
            '/skeleton/bin/dev.sh',
            '/skeleton/public/index.php',
            '/skeleton/config/waaseyaa.php',
            '/skeleton/composer.json',
        ];

        foreach ($requiredFiles as $relativePath) {
            self::assertFileExists(
                $repoRoot . $relativePath,
                sprintf('Missing first-boot skeleton artifact: %s', $relativePath),
            );
        }
    }

    /**
     * Consumers use ./vendor/bin/waaseyaa (Composer-generated proxy to
     * waaseyaa/cli's bin). The skeleton must NOT ship its own bin/waaseyaa
     * wrapper: such a wrapper duplicates the proxy and, historically, was
     * a workaround for CLI-bootstrap bugs fixed by ADR-005.
     */
    #[Test]
    public function skeletonDoesNotShipDeprecatedWaaseyaaBinWrapper(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        self::assertFileDoesNotExist(
            $repoRoot . '/skeleton/bin/waaseyaa',
            'skeleton/bin/waaseyaa must not exist — use ./vendor/bin/waaseyaa (see ADR-005)',
        );
    }

    /** Packagist installs must not require a monorepo-relative path repository. */
    #[Test]
    public function skeletonComposerJsonHasNoCheckedInPathRepositories(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $composer = json_decode((string) file_get_contents($repoRoot . '/skeleton/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayNotHasKey('repositories', $composer, 'skeleton must resolve waaseyaa/* from Packagist; use composer.local.json for local path overrides');
    }

    #[Test]
    public function skeletonPostCreateProjectChmodDoesNotTargetRemovedBinWrapper(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $composer = json_decode((string) file_get_contents($repoRoot . '/skeleton/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $scripts = $composer['scripts'] ?? [];
        $postCreate = $scripts['post-create-project-cmd'] ?? null;
        self::assertIsArray($postCreate);
        $joined = implode("\n", $postCreate);
        $hasRemovedWrapper = (bool) preg_match('/(?:^|\s)bin\/waaseyaa(?:\s|$)/', $joined);
        self::assertFalse(
            $hasRemovedWrapper,
            'post-create must not reference project-root bin/waaseyaa; use ./vendor/bin/waaseyaa (ADR-005). bin/waaseyaa-version is still allowed.',
        );
    }

    #[Test]
    public function waaseyaaAuditSitePrefersVendorBinCli(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $path = $repoRoot . '/skeleton/bin/maintenance/waaseyaa-audit-site';
        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('vendor/bin/waaseyaa', $contents);
        self::assertStringNotContainsString('[[ -f bin/waaseyaa ]]', $contents);
    }
}
