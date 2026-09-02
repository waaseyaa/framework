<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Foundation\Diagnostic\CleanUrlProbe;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
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
     * #2438 / ADR-024: the skeleton ships minimal and bootable, with no
     * placeholder directories. `.gitkeep` was the only mechanism ever used to
     * scaffold an empty directory into `skeleton/`, so a `.gitkeep` reappearing
     * anywhere under it is exactly the regression this guards: an optional
     * architectural area must appear only when a deterministic generator
     * writes a real file into it, never as a pre-scaffolded empty directory.
     */
    #[Test]
    public function skeletonShipsNoPlaceholderGitkeepFiles(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $skeletonRoot = $repoRoot . '/skeleton';

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($skeletonRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getFilename() === '.gitkeep') {
                $found[] = substr((string) $file->getPathname(), strlen($repoRoot) + 1);
            }
        }

        self::assertSame(
            [],
            $found,
            'skeleton/ must ship no placeholder .gitkeep files (#2438, ADR-024); '
            . 'an optional area is created only when a generator writes a real file into it.',
        );
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

    /**
     * The skeleton's own route table, asserted whole: it registers the clean-URL
     * diagnostic probe and nothing else.
     *
     * The probe route is load-bearing — `waaseyaa-audit-site` and the operator
     * clean-URL diagnostic depend on its sentinel.
     *
     * `/` must NOT be in that table. The framework's `public.home` route already
     * binds it to `render.page`, and an application route on `/` shadows it: the
     * removed `HomeController` served `home.html.twig` as raw bytes, so any Twig
     * expression an app author added reached the browser unevaluated (#2651).
     * Both assertions live in one test because both read the same route table
     * from a single load of the skeleton provider.
     *
     * The kernel-level proof lives in
     * tests/Integration/SkeletonHomepage/SkeletonHomepageRendererTest.php; this
     * is the cheap structural guard.
     */
    #[Test]
    public function skeletonRegistersTheCleanUrlProbeAndDoesNotClaimTheHomepage(): void
    {
        $repoRoot = dirname(__DIR__, 4);
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

        try {
            $router->match('/');
            self::fail('The skeleton must not register an application route on "/" — the SSR renderer owns it (#2651).');
        } catch (RouteNotFoundException) {
            // Expected: `/` falls through to the framework's public.home route.
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

    /**
     * Guard: every skeleton Composer script must be runnable on native
     * Windows (#2644).
     *
     * Composer hands a script that is not an `@`-directive to the OS as a
     * command. Windows has no shebang support and honours PATHEXT, so an
     * extensionless POSIX script — which is what `site-verify` and `audit-site`
     * both were — is not a runnable image there at all. The portable forms are
     * `@php <file.php>`, `@composer …`, another `@script`, or a PHP callable.
     *
     * `audit-site` is a known, deliberate exception: it is an optional
     * convergence preflight, documented as POSIX-only, and is not part of the
     * fresh-project lifecycle. It is listed here so that adding a fourth
     * shebang script is a red test rather than a silent regression.
     */
    #[Test]
    public function skeletonComposerScriptsAreRunnableOnNativeWindows(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $composer = json_decode((string) file_get_contents($repoRoot . '/skeleton/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        $posixOnly = ['audit-site'];

        foreach ((array) ($composer['scripts'] ?? []) as $name => $script) {
            if (in_array($name, $posixOnly, true)) {
                continue;
            }
            foreach ((array) $script as $line) {
                self::assertMatchesRegularExpression(
                    '/^(@|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::)/',
                    (string) $line,
                    sprintf(
                        'Skeleton Composer script "%s" runs "%s" as an OS command; use @php, @composer, another @script, or a callable.',
                        $name,
                        (string) $line,
                    ),
                );
            }
        }

        self::assertSame('@php .ci/site-verify.php', $composer['scripts']['site-verify'] ?? null);
    }

    /**
     * Guard: the skeleton README documents one fresh-project lifecycle, in
     * order, and it is the lifecycle the reference-consumer gate proves
     * (#2644). A README that presents a competing sequence — or that names a
     * materialization command other than `install:init` — sends a new project
     * to a state that verifies successfully while having no active
     * configuration generation.
     */
    #[Test]
    public function skeletonDocumentsTheCanonicalFreshProjectLifecycleInOrder(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $readme = (string) file_get_contents($repoRoot . '/skeleton/README.md');

        $previous = -1;
        foreach (['create-project', 'site:init', 'install:init', 'composer site-verify', 'composer run dev'] as $phase) {
            $offset = strpos($readme, $phase);
            self::assertIsInt($offset, sprintf('Skeleton README must document the %s phase.', $phase));
            self::assertGreaterThan(
                $previous,
                $offset,
                sprintf('Skeleton README documents %s out of lifecycle order.', $phase),
            );
            $previous = $offset;
        }

        self::assertStringNotContainsString(
            'waaseyaa db:init',
            $readme,
            'db:init is a database-administration command, not part of the documented lifecycle.',
        );
    }
}
