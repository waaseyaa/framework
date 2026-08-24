<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Migration\Discovery\CycleDetector;
use Waaseyaa\Migration\Discovery\DependencyGraph;
use Waaseyaa\Migration\Discovery\FilesystemManifestLoader;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\Exception\MigrationCycleException;
use Waaseyaa\Migration\Exception\MigrationDependencyMissingException;
use Waaseyaa\Migration\Exception\MigrationPluginCollisionException;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\PluginFixtures\PluginFixturesTrait;

#[CoversClass(MigrationRegistry::class)]
#[CoversClass(MigrationCycleException::class)]
#[CoversClass(MigrationDependencyMissingException::class)]
#[CoversClass(DependencyGraph::class)]
#[CoversClass(CycleDetector::class)]
final class MigrationRegistryTest extends TestCase
{
    use PluginFixturesTrait;

    #[Test]
    public function boot_indexes_definitions_from_a_single_provider(): void
    {
        $a = $this->definition('a');
        $b = $this->definition('b', ['a']);
        $registry = new MigrationRegistry([$this->provider([$a, $b])]);

        $registry->boot();

        self::assertTrue($registry->has('a'));
        self::assertTrue($registry->has('b'));
        self::assertSame($a, $registry->get('a'));
        self::assertSame($b, $registry->get('b'));
        self::assertCount(2, $registry->all());
    }

    #[Test]
    public function topologically_sorted_returns_definitions_in_dependency_order(): void
    {
        $a = $this->definition('a');
        $b = $this->definition('b', ['a']);
        $c = $this->definition('c', ['b']);

        $registry = new MigrationRegistry([$this->provider([$c, $a, $b])]);
        $registry->boot();

        $ordered = $registry->topologicallySorted();
        self::assertSame(['a', 'b', 'c'], \array_map(static fn ($d) => $d->id, $ordered));
    }

    #[Test]
    public function boot_supports_multiple_providers(): void
    {
        $a = $this->definition('a');
        $b = $this->definition('b', ['a']);

        $registry = new MigrationRegistry([
            $this->provider([$a]),
            $this->provider([$b]),
        ]);
        $registry->boot();

        self::assertCount(2, $registry->all());
    }

    #[Test]
    public function duplicate_definition_id_across_providers_raises_collision(): void
    {
        $first = $this->definition('shared');
        $second = $this->definition('shared');

        $registry = new MigrationRegistry([
            $this->provider([$first]),
            $this->provider([$second]),
        ]);

        $this->expectException(MigrationPluginCollisionException::class);
        $this->expectExceptionMessage("'shared'");

        $registry->boot();
    }

    #[Test]
    public function missing_dependency_raises_typed_exception_with_both_ids(): void
    {
        $b = $this->definition('b', ['a']);
        $registry = new MigrationRegistry([$this->provider([$b])]);

        try {
            $registry->boot();
            self::fail('Expected MigrationDependencyMissingException.');
        } catch (MigrationDependencyMissingException $exception) {
            self::assertSame('a', $exception->missingDependencyId);
            self::assertSame('b', $exception->requestingMigrationId);
            self::assertStringContainsString("'a'", $exception->getMessage());
            self::assertStringContainsString("'b'", $exception->getMessage());
        }
    }

    #[Test]
    public function two_cycle_raises_typed_exception_with_full_path(): void
    {
        $a = $this->definition('a', ['b']);
        $b = $this->definition('b', ['a']);

        $registry = new MigrationRegistry([$this->provider([$a, $b])]);

        try {
            $registry->boot();
            self::fail('Expected MigrationCycleException.');
        } catch (MigrationCycleException $exception) {
            self::assertSame(['a', 'b', 'a'], $exception->cyclePath);
            self::assertStringContainsString('a -> b -> a', $exception->getMessage());
        }
    }

    #[Test]
    public function three_cycle_raises_typed_exception_with_full_path(): void
    {
        $a = $this->definition('a', ['b']);
        $b = $this->definition('b', ['c']);
        $c = $this->definition('c', ['a']);

        $registry = new MigrationRegistry([$this->provider([$a, $b, $c])]);

        try {
            $registry->boot();
            self::fail('Expected MigrationCycleException.');
        } catch (MigrationCycleException $exception) {
            self::assertSame(['a', 'b', 'c', 'a'], $exception->cyclePath);
        }
    }

    #[Test]
    public function boot_can_only_run_once(): void
    {
        $registry = new MigrationRegistry([$this->provider([$this->definition('a')])]);
        $registry->boot();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('may only be called once');

        $registry->boot();
    }

    #[Test]
    public function get_before_explicit_boot_builds_lazily_and_succeeds(): void
    {
        // G-024 (#1940): query methods no longer require an explicit boot()
        // call first — the first query builds the index on demand.
        $registry = new MigrationRegistry([$this->provider([$this->definition('a')])]);

        self::assertSame('a', $registry->get('a')->id);
    }

    #[Test]
    public function all_before_explicit_boot_builds_lazily_and_succeeds(): void
    {
        // G-024 (#1940): see get_before_explicit_boot_builds_lazily_and_succeeds.
        $registry = new MigrationRegistry([$this->provider([$this->definition('a')])]);

        self::assertCount(1, $registry->all());
    }

    #[Test]
    public function migrations_are_not_built_until_first_registry_query(): void
    {
        // G-024 (#1940): construction must not eagerly consume providers —
        // AbstractKernel::bootProviders() runs before discoverAccessPolicies(),
        // so a provider whose migrations() resolves access services from the
        // kernel-services bus must not run until the whole kernel is booted.
        $spy = $this->spyProvider([$this->definition('a')]);
        $registry = new MigrationRegistry([$spy]);

        self::assertSame(0, $spy->callCount, 'Constructing the registry must not invoke migrations().');

        self::assertTrue($registry->has('a'));
        self::assertSame(1, $spy->callCount, 'The first query must build the index exactly once.');

        // Further queries — of any kind — must reuse the already-built index.
        self::assertSame($registry->get('a'), $registry->get('a'));
        $registry->all();
        $registry->topologicallySorted();
        $registry->graph();
        self::assertSame(1, $spy->callCount, 'Later queries must not rebuild the index.');
    }

    #[Test]
    public function explicit_boot_still_prevents_lazy_rebuild_on_first_query(): void
    {
        // Backward compatibility: callers that still call boot() explicitly
        // (every other test in this file, plus several integration tests)
        // must keep working exactly as before — the first query afterward
        // must not attempt to boot again.
        $spy = $this->spyProvider([$this->definition('a')]);
        $registry = new MigrationRegistry([$spy]);

        $registry->boot();
        self::assertSame(1, $spy->callCount);

        self::assertTrue($registry->has('a'));
        self::assertSame(1, $spy->callCount, 'A query after an explicit boot() must not rebuild.');
    }

    #[Test]
    public function get_unknown_id_raises_out_of_bounds(): void
    {
        $registry = new MigrationRegistry([$this->provider([$this->definition('a')])]);
        $registry->boot();

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage("'unknown'");

        $registry->get('unknown');
    }

    #[Test]
    public function provider_yielding_non_migration_definition_raises_type_error(): void
    {
        // A misbehaving provider that violates the contract is caught by
        // PHP's native parameter-binding TypeError on `indexDefinition()`.
        $provider = new class() implements HasMigrationsInterface {
            public function migrations(): iterable
            {
                /** @phpstan-ignore-next-line - intentional bad shape */
                yield 'not-a-definition';
            }
        };
        $registry = new MigrationRegistry([$provider]);

        $this->expectException(\TypeError::class);

        $registry->boot();
    }

    #[Test]
    public function filesystem_loader_feeds_registry_alongside_providers(): void
    {
        $tempDir = $this->makeTempDir();
        \file_put_contents($tempDir . '/wp_users.php', $this->definitionFileSource('wp_users'));

        $loader = new FilesystemManifestLoader([$tempDir]);
        $registry = new MigrationRegistry(
            providers: [$this->provider([$this->definition('node_authors', ['wp_users'])])],
            filesystemLoader: $loader,
        );

        $registry->boot();

        self::assertTrue($registry->has('wp_users'));
        self::assertTrue($registry->has('node_authors'));
        self::assertSame(
            ['node_authors', 'wp_users'],
            \array_map(static fn ($d) => $d->id, $registry->all()),
        );

        (new Filesystem())->remove($tempDir);
    }

    #[Test]
    public function graph_accessor_returns_booted_graph(): void
    {
        $registry = new MigrationRegistry([
            $this->provider([
                $this->definition('a'),
                $this->definition('b', ['a']),
            ]),
        ]);
        $registry->boot();

        $graph = $registry->graph();
        self::assertSame(['a', 'b'], $graph->vertices());
        self::assertSame(['a'], $graph->dependencies('b'));
    }

    /**
     * @param list<MigrationDefinition> $definitions
     */
    private function provider(array $definitions): HasMigrationsInterface
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
     * A provider that records how many times migrations() has been called —
     * used to prove the G-024 lazy-build contract (#1940). Return type is
     * `object` (not `HasMigrationsInterface`) because the test reads the
     * anonymous class's `$callCount` property, which is not part of the
     * interface.
     *
     * @param list<MigrationDefinition> $definitions
     */
    private function spyProvider(array $definitions): object
    {
        return new class($definitions) implements HasMigrationsInterface {
            public int $callCount = 0;

            /**
             * @param list<MigrationDefinition> $definitions
             */
            public function __construct(private readonly array $definitions) {}
            public function migrations(): iterable
            {
                $this->callCount++;
                yield from $this->definitions;
            }
        };
    }

    /**
     * @param list<string> $dependencies
     */
    private function definition(string $id, array $dependencies = []): MigrationDefinition
    {
        return new MigrationDefinition(
            id: $id,
            source: $this->fixtureSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->fixtureDestination('node_destination'),
            dependencies: $dependencies,
        );
    }

    private function definitionFileSource(string $id): string
    {
        // Build a self-contained PHP file that returns a MigrationDefinition.
        return <<<PHP
        <?php

        declare(strict_types=1);

        use Waaseyaa\\Migration\\MigrationDefinition;
        use Waaseyaa\\Migration\\Plugin\\DestinationPluginInterface;
        use Waaseyaa\\Migration\\Plugin\\DestinationRecord;
        use Waaseyaa\\Migration\\Plugin\\ProcessContext;
        use Waaseyaa\\Migration\\Plugin\\ProcessPluginInterface;
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
        $base = \sys_get_temp_dir() . '/waaseyaa_migration_registry_' . \uniqid('', true);
        if (!\mkdir($base, 0o700, true) && !\is_dir($base)) {
            throw new \RuntimeException('Could not create temp dir for test.');
        }
        return $base;
    }

}
