<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Migration\MigrationLoader;

/**
 * Mission #529 / WP11 / T066 + T068 (loader half).
 *
 * Locks `MigrationLoader`'s handling of the WP11 ordered-array
 * manifest shape. Path entries continue to load as legacy migrations
 * via `loadAll()`; FQCN entries route through `loadAllV2()`. Order is
 * preserved end-to-end, and empty / no-match cases are handled safely
 * (no silent skip — warnings are emitted via the optional logger).
 */
#[CoversClass(MigrationLoader::class)]
final class MigrationLoaderArrayFormTest extends TestCase
{
    private string $basePath;

    /** @var list<string> */
    private array $createdDirs = [];

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/waaseyaa_loader_test_' . uniqid();
        mkdir($this->basePath . '/migrations', 0o777, true);
        $this->createdDirs[] = $this->basePath . '/migrations';
        $this->createdDirs[] = $this->basePath;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdDirs) as $dir) {
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '/*.php') ?: []);
                @rmdir($dir);
            }
        }
    }

    #[Test]
    public function stringFormStillLoadsLegacyMigrationsAsBefore(): void
    {
        $packageDir = $this->basePath . '/vendor/legacy/foo/migrations';
        mkdir($packageDir, 0o777, true);
        $this->createdDirs[] = $packageDir;
        $this->createdDirs[] = dirname($packageDir);
        $this->createdDirs[] = dirname(dirname($packageDir));

        $this->writeLegacyMigration($packageDir . '/2026_01_01_init.php');

        $manifest = new PackageManifest(
            providers: [],
            migrations: ['legacy/foo' => 'migrations'],
        );

        $loader = new MigrationLoader($this->basePath, $manifest);
        $loaded = $loader->loadAll();

        self::assertArrayHasKey('legacy/foo', $loaded);
        self::assertArrayHasKey('legacy/foo:2026_01_01_init', $loaded['legacy/foo']);
    }

    #[Test]
    public function arrayFormPreservesOrderEvenWhenAuthoredNonAlphabetically(): void
    {
        // Two path entries authored in non-alphabetical order — the
        // loader must respect the authored order.
        $dirZ = $this->basePath . '/vendor/legacy/foo/migrations_z';
        $dirA = $this->basePath . '/vendor/legacy/foo/migrations_a';
        mkdir($dirZ, 0o777, true);
        mkdir($dirA, 0o777, true);
        $this->createdDirs[] = $dirZ;
        $this->createdDirs[] = $dirA;
        $this->createdDirs[] = dirname($dirZ);
        $this->createdDirs[] = dirname(dirname($dirZ));

        $this->writeLegacyMigration($dirZ . '/01_init.php');
        $this->writeLegacyMigration($dirA . '/01_init.php');

        $manifest = new PackageManifest(
            providers: [],
            migrations: [
                'legacy/foo' => ['migrations_z', 'migrations_a'],
            ],
        );

        $loader = new MigrationLoader($this->basePath, $manifest);
        $loaded = $loader->loadAll();

        // Both load under the same package key; loadAll combines
        // entries with array-union semantics. Both files appear.
        self::assertArrayHasKey('legacy/foo', $loaded);
        self::assertArrayHasKey('legacy/foo:01_init', $loaded['legacy/foo']);
    }

    #[Test]
    public function emptyArrayLoadsNothingForThatPackage(): void
    {
        $manifest = new PackageManifest(
            providers: [],
            migrations: ['legacy/foo' => []],
        );

        $loader = new MigrationLoader($this->basePath, $manifest);

        self::assertSame([], $loader->loadAll(), 'No legacy migrations should load.');
        self::assertSame([], $loader->loadAllV2(), 'No v2 migrations should load.');
    }

    #[Test]
    public function declared_root_path_resolves_from_project_and_suppresses_legacy_fallback(): void
    {
        file_put_contents(
            $this->basePath . '/composer.json',
            json_encode(['name' => 'acme/application'], JSON_THROW_ON_ERROR),
        );
        $this->writeLegacyMigration($this->basePath . '/migrations/01_init.php');

        $loader = new MigrationLoader(
            $this->basePath,
            new PackageManifest(
                providers: [],
                migrations: ['acme/application' => 'migrations'],
            ),
        );

        $loaded = $loader->loadAll();

        self::assertSame(['app'], array_keys($loaded));
        self::assertArrayHasKey('app:01_init', $loaded['app']);
        self::assertArrayNotHasKey('acme/application', $loaded);
    }

    #[Test]
    public function fqcnEntryWithNoMatchingClassesLogsWarning(): void
    {
        $logger = self::collectingLogger();

        $manifest = new PackageManifest(
            providers: [],
            migrations: ['vendor/pkg' => ['Vendor\\NonExistent\\Migrations']],
        );

        $loader = new MigrationLoader($this->basePath, $manifest, $logger);
        $v2 = $loader->loadAllV2();

        self::assertSame([], $v2);
        $warnings = array_filter($logger->records, static fn(array $r): bool => $r['level'] === LogLevel::WARNING);
        self::assertNotEmpty($warnings, 'Expected a warning for the no-match namespace.');
        $messages = array_column($warnings, 'message');
        self::assertStringContainsString('Vendor\\NonExistent\\Migrations', implode("\n", $messages));
    }

    #[Test]
    public function default_root_aliases_do_not_rename_other_root_or_installed_paths(): void
    {
        file_put_contents($this->basePath . '/composer.json', '{"name":"acme/application"}');
        $this->writeLegacyMigration($this->basePath . '/migrations/01_init.php');
        $extra = $this->basePath . '/patches';
        $installed = $this->basePath . '/vendor/acme/plugin/migrations';
        mkdir($extra);
        mkdir($installed, 0o755, true);
        $this->createdDirs[] = $extra;
        $this->createdDirs[] = $installed;
        $this->writeLegacyMigration($extra . '/02_patch.php');
        $this->writeLegacyMigration($installed . '/01_init.php');
        $loader = new MigrationLoader($this->basePath, new PackageManifest(providers: [], migrations: [
            'acme/application' => ['migrations', './migrations/', $this->basePath . '/migrations', 'patches'],
            'acme/plugin' => 'migrations',
        ]));

        $loaded = $loader->loadAll();
        self::assertSame(['app', 'acme/application', 'acme/plugin'], array_keys($loaded));
        self::assertSame(['app:01_init'], array_keys($loaded['app']));
        self::assertSame(['acme/application:02_patch'], array_keys($loaded['acme/application']));
        self::assertSame(['acme/plugin:01_init'], array_keys($loaded['acme/plugin']));
    }

    #[Test]
    public function loadAllSkipsFqcnEntriesAndLoadAllV2SkipsPathEntries(): void
    {
        // Mixed manifest: one path, one FQCN. loadAll() sees only the
        // path; loadAllV2() sees only the FQCN.
        $packageDir = $this->basePath . '/vendor/mixed/pkg/migrations';
        mkdir($packageDir, 0o777, true);
        $this->createdDirs[] = $packageDir;
        $this->createdDirs[] = dirname($packageDir);
        $this->createdDirs[] = dirname(dirname($packageDir));

        $this->writeLegacyMigration($packageDir . '/01_init.php');

        $manifest = new PackageManifest(
            providers: [],
            migrations: [
                'mixed/pkg' => ['Vendor\\Mixed\\Migrations\\v2', 'migrations'],
            ],
        );

        $loader = new MigrationLoader($this->basePath, $manifest);

        $legacy = $loader->loadAll();
        self::assertArrayHasKey('mixed/pkg', $legacy);
        self::assertArrayHasKey('mixed/pkg:01_init', $legacy['mixed/pkg']);

        // FQCN entry resolves to zero v2 classes (none registered in
        // the test classmap); loader returns an empty list rather than
        // crashing.
        $v2 = $loader->loadAllV2();
        self::assertSame([], $v2);
    }

    private function writeLegacyMigration(string $absolutePath): void
    {
        $content = <<<'PHP'
            <?php

            use Waaseyaa\Foundation\Migration\Migration;
            use Waaseyaa\Foundation\Migration\SchemaBuilder;

            return new class extends Migration {
                public function up(SchemaBuilder $schema): void {}
            };
            PHP;
        file_put_contents($absolutePath, $content);
    }

    private static function collectingLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            /** @var list<array{level: LogLevel, message: string}> */
            public array $records = [];
            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::DEBUG, $message);
            }
            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::INFO, $message);
            }
            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::NOTICE, $message);
            }
            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::WARNING, $message);
            }
            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::ERROR, $message);
            }
            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::CRITICAL, $message);
            }
            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::ALERT, $message);
            }
            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::EMERGENCY, $message);
            }
            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
    }
}
