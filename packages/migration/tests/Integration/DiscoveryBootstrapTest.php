<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Migration\Discovery\FilesystemManifestLoader;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\Exception\MigrationCycleException;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\PluginFixtures\PluginFixturesTrait;
use Waaseyaa\Migration\ServiceProvider;

/**
 * Integration test for the WP02 discovery surface.
 *
 * Exercises the boot sequence through {@see ServiceProvider} so we catch
 * wiring regressions that pure-unit tests miss — config flow into the
 * filesystem loader, eager registry boot from `boot()`, and propagation of
 * structural manifest errors (cycle / missing dependency) up through the
 * provider.
 *
 * APP_ENV is forced to `testing` so the future kernel boot guard (which
 * refuses `APP_DEBUG=true` in production) cannot trip these tests.
 */
#[CoversNothing]
final class DiscoveryBootstrapTest extends TestCase
{
    use PluginFixturesTrait;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        \putenv('APP_ENV=testing');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            (new Filesystem())->remove($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    #[Test]
    public function service_provider_boots_registry_with_providers_and_filesystem_paths(): void
    {
        $manifestDir = $this->makeTempDir();
        \file_put_contents($manifestDir . '/wp_users.php', $this->definitionFile('wp_users'));

        $provider = new ServiceProvider();
        $provider->setKernelContext(
            projectRoot: \sys_get_temp_dir(),
            config: [
                'migration' => [
                    'manifest_paths' => [$manifestDir],
                ],
            ],
            manifestFormatters: [],
        );
        $provider->withMigrationProviders([
            $this->migrationProvider([
                new MigrationDefinition(
                    id: 'wp_posts_to_teachings',
                    source: $this->fixtureSource('wp_post'),
                    process: ['title' => 'post_title'],
                    destination: $this->fixtureDestination('node_destination'),
                    dependencies: ['wp_users'],
                ),
            ]),
        ]);

        $provider->register();
        $provider->boot();

        $registry = $provider->resolve(MigrationRegistry::class);
        self::assertInstanceOf(MigrationRegistry::class, $registry);
        self::assertTrue($registry->has('wp_users'));
        self::assertTrue($registry->has('wp_posts_to_teachings'));

        // Topological order proves the cross-source DAG was built correctly:
        // filesystem-loaded `wp_users` precedes provider-loaded
        // `wp_posts_to_teachings` even though they came from different sources.
        $ordered = $registry->topologicallySorted();
        self::assertSame(
            ['wp_posts_to_teachings', 'wp_users'],
            $this->idsSorted($ordered),
        );
        self::assertSame('wp_users', $ordered[0]->id);
        self::assertSame('wp_posts_to_teachings', $ordered[1]->id);
    }

    #[Test]
    public function service_provider_boot_no_longer_eagerly_resolves_the_registry(): void
    {
        // G-024 (#1940): ServiceProvider::boot() no longer resolves
        // MigrationRegistry, so structural manifest errors (like this cycle)
        // no longer surface at boot() — they surface at the registry's first
        // query instead. This is the deliberate fail-fast-at-boot trade-off
        // documented on MigrationRegistry's class docblock and in
        // CHANGELOG.md.
        $provider = new ServiceProvider();
        $provider->setKernelContext(
            projectRoot: \sys_get_temp_dir(),
            config: [],
            manifestFormatters: [],
        );

        $provider->withMigrationProviders([
            $this->migrationProvider([
                new MigrationDefinition(
                    id: 'wp_posts',
                    source: $this->fixtureSource('wp_post'),
                    process: ['title' => 'post_title'],
                    destination: $this->fixtureDestination('node_destination'),
                    dependencies: ['wp_terms'],
                ),
                new MigrationDefinition(
                    id: 'wp_terms',
                    source: $this->fixtureSource('wp_term'),
                    process: ['name' => 'term_name'],
                    destination: $this->fixtureDestination('taxonomy_destination'),
                    dependencies: ['wp_posts'],
                ),
            ]),
        ]);

        $provider->register();
        $provider->boot();

        $registry = $provider->resolve(MigrationRegistry::class);
        self::assertInstanceOf(MigrationRegistry::class, $registry);

        try {
            $registry->all();
            self::fail('Expected MigrationCycleException to propagate from the registry\'s first query.');
        } catch (MigrationCycleException $exception) {
            self::assertSame(['wp_posts', 'wp_terms', 'wp_posts'], $exception->cyclePath);
        }
    }

    #[Test]
    public function service_provider_binds_filesystem_loader_singleton(): void
    {
        $manifestDir = $this->makeTempDir();
        $provider = new ServiceProvider();
        $provider->setKernelContext(
            projectRoot: \sys_get_temp_dir(),
            config: [
                'migration' => [
                    'manifest_paths' => [$manifestDir],
                ],
            ],
            manifestFormatters: [],
        );

        $provider->register();

        $loaderA = $provider->resolve(FilesystemManifestLoader::class);
        $loaderB = $provider->resolve(FilesystemManifestLoader::class);
        self::assertSame($loaderA, $loaderB, 'FilesystemManifestLoader must be a singleton.');
    }

    #[Test]
    public function service_provider_tolerates_empty_manifest_paths_config(): void
    {
        $provider = new ServiceProvider();
        $provider->setKernelContext(
            projectRoot: \sys_get_temp_dir(),
            config: [],
            manifestFormatters: [],
        );

        $provider->register();
        $provider->boot();

        $registry = $provider->resolve(MigrationRegistry::class);
        self::assertInstanceOf(MigrationRegistry::class, $registry);
        self::assertSame([], $registry->all());
    }

    /**
     * @param list<MigrationDefinition> $definitions
     */
    private function migrationProvider(array $definitions): HasMigrationsInterface
    {
        return new class($definitions) implements HasMigrationsInterface {
            /**
             * @param list<MigrationDefinition> $definitions
             */
            public function __construct(private readonly array $definitions) {}
            public function migrations(): iterable
            {
                yield from $this->definitions;
            }
        };
    }

    /**
     * @param list<MigrationDefinition> $definitions
     * @return list<string>
     */
    private function idsSorted(array $definitions): array
    {
        $ids = \array_map(static fn ($d) => $d->id, $definitions);
        \sort($ids, \SORT_STRING);
        return $ids;
    }

    private function definitionFile(string $id): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        use Waaseyaa\\Migration\\MigrationDefinition;
        use Waaseyaa\\Migration\\Plugin\\DestinationPluginInterface;
        use Waaseyaa\\Migration\\Plugin\\DestinationRecord;
        use Waaseyaa\\Migration\\Plugin\\SourcePluginInterface;
        use Waaseyaa\\Migration\\Plugin\\SourceRecord;
        use Waaseyaa\\Migration\\Plugin\\WriteResult;
        use Waaseyaa\\Migration\\SourceId;

        \$source = new class() implements SourcePluginInterface {
            public function id(): string { return 'wp_post'; }
            public function stability(): string { return 'stable'; }
            public function records(): iterable { return []; }
            public function count(): ?int { return 0; }
            public function sourceIdFor(SourceRecord \$record): SourceId
            {
                return new SourceId(sourceType: 'wp_post', keys: ['id' => 1]);
            }
        };
        \$destination = new class() implements DestinationPluginInterface {
            public function id(): string { return 'node_destination'; }
            public function stability(): string { return 'stable'; }
            public function write(DestinationRecord \$record): WriteResult
            {
                return new WriteResult(
                    destinationEntityType: 'node',
                    destinationUuid: '00000000-0000-7000-8000-000000000000',
                    sourceRecordHash: 'h',
                    runId: '00000000-0000-7000-8000-000000000001',
                    writtenAt: '2026-05-12T00:00:00Z',
                );
            }
            public function rollback(WriteResult \$writeResult): void {}
            public function lookup(SourceId \$sourceId): ?WriteResult { return null; }
        };

        return new MigrationDefinition(
            id: '{$id}',
            source: \$source,
            process: ['title' => 'post_title'],
            destination: \$destination,
        );
        PHP;
    }

    private function makeTempDir(): string
    {
        $base = \sys_get_temp_dir() . '/waaseyaa_migration_bootstrap_' . \uniqid('', true);
        if (!\mkdir($base, 0o700, true) && !\is_dir($base)) {
            throw new \RuntimeException('Could not create temp dir for test.');
        }
        $this->tempDirs[] = $base;
        return $base;
    }

}
