<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Foundation\Diagnostic\CleanUrlProbe;
use Waaseyaa\Routing\WaaseyaaRouter;

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

    /**
     * Guard: `composer run dev` routes to the discoverable `waaseyaa dev`
     * command (provided by the optional waaseyaa/frankenphp package) via
     * Composer's OWN PHP (`@php`), so it works identically in Git Bash,
     * PowerShell, cmd, and POSIX. It must NEVER regress to a shell script or a
     * standalone PHP launcher (the superseded `bin/dev` / `bin/dev.sh`).
     */
    #[Test]
    public function skeletonDevScriptRoutesToTheCliDevCommandWithNoShellDependency(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $composerJson = $repoRoot . '/skeleton/composer.json';
        self::assertFileExists($composerJson);

        $composer = json_decode((string) file_get_contents($composerJson), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        $scripts = $composer['scripts'] ?? null;
        self::assertIsArray($scripts);

        $dev = $scripts['dev'] ?? null;
        self::assertIsArray($dev);
        self::assertContains('Composer\\Config::disableProcessTimeout', $dev);
        self::assertContains('@php vendor/bin/waaseyaa dev', $dev);

        // No regression to a shell/launcher: no `.sh`, no standalone `bin/dev`.
        $joined = implode("\n", $dev);
        self::assertStringNotContainsString('.sh', $joined, 'the dev script must not depend on a shell script');
        self::assertStringNotContainsString('@php bin/dev', $joined, 'the dev script must route to `waaseyaa dev`, not a bin/dev launcher');

        // The superseded launcher files must be gone.
        self::assertFileDoesNotExist($repoRoot . '/skeleton/bin/dev', 'skeleton/bin/dev is superseded by the `waaseyaa dev` command');
        self::assertFileDoesNotExist($repoRoot . '/skeleton/bin/dev.sh', 'skeleton/bin/dev.sh is superseded by the `waaseyaa dev` command');
    }

    #[Test]
    public function skeletonIncludesEssentialFirstBootFiles(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $requiredFiles = [
            '/skeleton/.env.example',
            '/skeleton/bin/post-create-setup.php',
            '/skeleton/public/index.php',
            '/skeleton/public/.htaccess',
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

    #[Test]
    public function skeleton_declares_and_generates_the_canonical_application_secret(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $example = (string) file_get_contents($repoRoot . '/skeleton/.env.example');
        $setup = (string) file_get_contents($repoRoot . '/skeleton/bin/post-create-setup.php');

        self::assertStringContainsString("WAASEYAA_APP_SECRET=\n", $example);
        self::assertStringContainsString("'base64:' . base64_encode(random_bytes(32))", $setup);
        self::assertStringContainsString("str_replace('WAASEYAA_APP_SECRET='", $setup);
    }

    #[Test]
    public function skeletonShipsTheFrontControllerDeploymentContract(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $htaccess = (string) file_get_contents($repoRoot . '/skeleton/public/.htaccess');
        $provider = (string) file_get_contents($repoRoot . '/skeleton/src/Provider/AppServiceProvider.php');
        $probe = (string) file_get_contents($repoRoot . '/packages/foundation/src/Diagnostic/CleanUrlProbe.php');
        $readme = (string) file_get_contents($repoRoot . '/skeleton/README.md');
        $deployment = (string) file_get_contents($repoRoot . '/docs/deployment-web-servers.md');

        self::assertStringContainsString('RewriteRule ^ index.php [L]', $htaccess);
        self::assertStringContainsString('CleanUrlProbe::PATH', $provider);
        self::assertStringContainsString('/.well-known/waaseyaa/clean-url', $probe);
        self::assertStringContainsString('docs/deployment-web-servers.md', $readme);
        self::assertStringContainsString('FallbackResource /index.php', $deployment);
        self::assertStringContainsString('try_files $uri $uri/ /index.php?$query_string;', $deployment);
        self::assertStringContainsString('try_files {path} /index.php?{query}', $deployment);
    }

    #[Test]
    public function skeletonCleanUrlDiagnosticRouteReturnsTheExpectedSentinel(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        require_once $repoRoot . '/skeleton/src/Controller/HomeController.php';
        require_once $repoRoot . '/skeleton/src/Provider/AppServiceProvider.php';

        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $provider = new \App\Provider\AppServiceProvider();
        $provider->routes($router);

        $parameters = $router->match(CleanUrlProbe::PATH);
        $controller = $parameters['_controller'];

        self::assertIsCallable($controller);
        $response = $controller();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(CleanUrlProbe::SENTINEL, $response->getContent());
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
