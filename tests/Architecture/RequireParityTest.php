<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Install-closure / require-parity gate.
 *
 * `bin/check-package-layers` only rejects UPWARD layer edges; it never checks
 * that a same-or-lower-layer `use Waaseyaa\X\…` import is actually declared in
 * the importing package's composer.json `require`. A consumer that installs a
 * single package off the monorepo therefore hits a fatal missing dependency for
 * every undeclared, otherwise-legal import.
 *
 * This test asserts require-parity for the packages remediated in this work
 * package. Each imported sibling package that sits at the same layer or below
 * (i.e. a legal, declarable dependency) MUST appear in `require` unless the
 * shared PL007 baseline records a reviewed optional integration. Higher-layer
 * imports are layer violations owned by `check-package-layers` and are out of
 * scope here.
 */
#[CoversNothing]
final class RequireParityTest extends TestCase
{
    /** Packages whose require-parity is enforced by this gate. */
    private const COVERED_PACKAGES = ['foundation', 'field', 'api', 'structured-import'];

    /**
     * Kernel bootstrap reaches across every layer by design and cannot declare
     * upward edges; these files are exempt exactly as in check-package-layers.
     */
    private const KERNEL_EXEMPT = [
        'foundation/src/Diagnostic/HealthChecker.php',
        'foundation/src/Diagnostic/BootDiagnosticReport.php',
        'foundation/src/Http/Router/BroadcastRouter.php',
        'foundation/src/Http/Router/EntityTypeLifecycleRouter.php',
        'foundation/src/Http/Router/JsonApiRouter.php',
        'foundation/src/Http/Router/SchemaRouter.php',
        'foundation/src/Http/Router/SearchRouter.php',
        'foundation/src/Http/Router/TranslationRouter.php',
        'foundation/src/Http/Router/WorkflowDefinitionsApiRouter.php',
        'foundation/src/Http/Router/WaaseyaaContext.php',
        'foundation/src/Http/Inbound/ChannelInspector.php',
        'foundation/src/Http/Inbound/EventStreamReadModel.php',
        'foundation/src/Http/Inbound/SubscriberObserver.php',
        'foundation/src/MercureMonitorServiceProvider.php',
        'foundation/src/Kernel/EventListenerRegistrar.php',
        'foundation/src/ServiceProvider/Capability/HasCommandsInterface.php',
        'foundation/src/ServiceProvider/Capability/HasMiddlewareInterface.php',
        'foundation/src/ServiceProvider/Capability/HasGraphqlMutationOverridesInterface.php',
    ];

    /** @return iterable<string, array{string}> */
    public static function coveredPackageProvider(): iterable
    {
        foreach (self::COVERED_PACKAGES as $package) {
            yield $package => [$package];
        }
    }

    #[Test]
    #[DataProvider('coveredPackageProvider')]
    public function every_same_or_lower_layer_import_is_declared(string $package): void
    {
        $root = dirname(__DIR__, 2);
        $packagesDir = $root . '/packages';
        [$namespaceToPackage, $layerByPackage] = self::buildMaps($packagesDir);

        $manifest = $packagesDir . '/' . $package . '/composer.json';
        self::assertFileExists($manifest);
        $data = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);

        $declared = [];
        foreach (array_keys($data['require'] ?? []) as $depName) {
            if (preg_match('#^waaseyaa/([a-z0-9-]+)$#', (string) $depName, $m) === 1) {
                $declared[$m[1]] = true;
            }
        }

        $selfLayer = $layerByPackage[$package] ?? null;
        self::assertNotNull($selfLayer, "Package {$package} has no known layer.");
        $allowedOptionalEdges = self::optionalEdges($root);

        $undeclared = [];
        foreach (self::importsForPackage($packagesDir, $package, $namespaceToPackage) as $dep => $exampleFile) {
            if ($dep === $package || isset($declared[$dep]) || isset($allowedOptionalEdges[$package][$dep])) {
                continue;
            }
            $depLayer = $layerByPackage[$dep] ?? null;
            // Higher-layer imports are layer violations (a different gate); only
            // declarable same-or-lower-layer imports are require-parity issues.
            if ($depLayer === null || $depLayer > $selfLayer) {
                continue;
            }
            $undeclared[$dep] = $exampleFile;
        }

        self::assertSame(
            [],
            $undeclared,
            sprintf(
                "Package '%s' imports waaseyaa packages it does not declare in require: %s",
                $package,
                json_encode($undeclared, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ),
        );
    }

    /** @return array<string, array<string, true>> */
    private static function optionalEdges(string $root): array
    {
        $edges = [];
        $lines = file($root . '/tools/package-layers-undeclared-baseline.txt', FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^([a-z0-9-]+)\s+([a-z0-9-]+)\s+#/', $line, $matches) !== 1) {
                continue;
            }
            $edges[$matches[1]][$matches[2]] = true;
        }

        return $edges;
    }

    /**
     * @param array<string, string> $namespaceToPackage
     * @return array<string, string> dep-package => example file path
     */
    private static function importsForPackage(string $packagesDir, string $package, array $namespaceToPackage): array
    {
        $src = $packagesDir . '/' . $package . '/src';
        if (!is_dir($src)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($packagesDir) + 1));
            if (in_array($relative, self::KERNEL_EXEMPT, true) || str_contains('/' . $relative, '/src/Kernel/')) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            preg_match_all(
                '/^\s*use(?:\s+function|\s+const)?\s+(Waaseyaa\\\\[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)?)/m',
                $text,
                $matches,
            );
            foreach ($matches[1] as $imported) {
                $dep = self::resolveNamespace($imported, $namespaceToPackage);
                if ($dep !== null && !isset($found[$dep])) {
                    $found[$dep] = $relative;
                }
            }
        }

        return $found;
    }

    /** @param array<string, string> $namespaceToPackage */
    private static function resolveNamespace(string $imported, array $namespaceToPackage): ?string
    {
        // Try the two-segment prefix first (e.g. Waaseyaa\AI\Vector), then one.
        $parts = explode('\\', $imported);
        $two = isset($parts[2]) ? $parts[0] . '\\' . $parts[1] . '\\' . $parts[2] : null;
        $one = $parts[0] . '\\' . $parts[1];

        if ($two !== null && isset($namespaceToPackage[$two])) {
            return $namespaceToPackage[$two];
        }

        return $namespaceToPackage[$one] ?? null;
    }

    /**
     * Build namespace->package from each package's PSR-4 autoload, and a
     * package->layer map from check-package-layers' authoritative index.
     *
     * @return array{0: array<string, string>, 1: array<string, int>}
     */
    private static function buildMaps(string $packagesDir): array
    {
        $namespaceToPackage = [];
        foreach (glob($packagesDir . '/*/composer.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            $name = (string) ($data['name'] ?? '');
            if (preg_match('#^waaseyaa/([a-z0-9-]+)$#', $name, $m) !== 1) {
                continue;
            }
            $package = $m[1];
            foreach (array_keys($data['autoload']['psr-4'] ?? []) as $prefix) {
                $namespaceToPackage[rtrim((string) $prefix, '\\')] = $package;
            }
        }

        return [$namespaceToPackage, self::layerMap()];
    }

    /** @return array<string, int> */
    private static function layerMap(): array
    {
        return [
            'foundation' => 0, 'cache' => 0, 'plugin' => 0, 'typed-data' => 0, 'database-legacy' => 0,
            'i18n' => 0, 'queue' => 0, 'scheduler' => 0, 'state' => 0, 'validation' => 0, 'mail' => 0,
            'http-client' => 0, 'ingestion' => 0, 'error-handler' => 0, 'geo' => 0, 'mercure' => 0,
            'analytics' => 0, 'oauth-provider' => 0,
            'entity' => 1, 'entity-storage' => 1, 'access' => 1, 'audit' => 1, 'user' => 1, 'config' => 1,
            'field' => 1, 'auth' => 1, 'testing' => 1, 'oidc' => 1,
            'attachment' => 2, 'node' => 2, 'taxonomy' => 2, 'media' => 2, 'path' => 2, 'menu' => 2,
            'note' => 2, 'relationship' => 2, 'groups' => 2, 'engagement' => 2,
            'workflows' => 3, 'search' => 3, 'seo' => 3, 'notification' => 3, 'billing' => 3, 'github' => 3,
            'structured-import' => 3, 'migration' => 3, 'listing' => 3, 'messaging' => 3,
            'api' => 4, 'routing' => 4, 'bimaaji' => 4, 'wayfinding' => 4,
            'ai-schema' => 5, 'ai-agent' => 5, 'ai-pipeline' => 5, 'ai-tools' => 5, 'ai-vector' => 5,
            'ai-observability' => 5,
            'cli' => 6, 'frankenphp' => 6, 'admin-surface' => 6, 'graphql' => 6, 'mcp' => 6, 'ssr' => 6,
            'genealogy' => 6, 'telescope' => 6, 'deployer' => 6, 'inertia' => 6, 'debug' => 6, 'workspace' => 6,
        ];
    }
}
