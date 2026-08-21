<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

#[CoversClass(MigrationDefinition::class)]
final class MigrationDefinitionTest extends TestCase
{
    #[Test]
    public function valid_construction_round_trips_every_property(): void
    {
        $source = $this->makeSource('wp_post');
        $process = new class() implements ProcessPluginInterface {
            public function id(): string { return 'concat'; }
            public function stability(): string { return 'stable'; }
            public function transform(mixed $value, ProcessContext $context): mixed { return $value; }
        };
        $destination = $this->makeDestination('node_destination');

        $definition = new MigrationDefinition(
            id: 'wp_posts_to_teachings',
            source: $source,
            process: [
                'title' => 'post_title',
                'body' => $process,
                'slug' => [$process, 'post_slug'],
            ],
            destination: $destination,
            dependencies: ['wp_users_to_accounts'],
            description: 'Import WordPress posts as Teaching nodes.',
            memoryBudgetBytes: 512 * 1024 * 1024,
            errorRateWarn: 0.02,
            errorRateHalt: 0.20,
        );

        self::assertSame('wp_posts_to_teachings', $definition->id);
        self::assertSame($source, $definition->source);
        self::assertSame($destination, $definition->destination);
        self::assertSame(['wp_users_to_accounts'], $definition->dependencies);
        self::assertSame('Import WordPress posts as Teaching nodes.', $definition->description);
        self::assertSame(512 * 1024 * 1024, $definition->memoryBudgetBytes);
        self::assertSame(0.02, $definition->errorRateWarn);
        self::assertSame(0.20, $definition->errorRateHalt);
    }

    #[Test]
    public function defaults_match_charter_resolutions(): void
    {
        $definition = $this->minimal();

        self::assertSame(MigrationDefinition::DEFAULT_MEMORY_BUDGET_BYTES, $definition->memoryBudgetBytes);
        self::assertSame(268_435_456, $definition->memoryBudgetBytes);
        self::assertSame(0.01, $definition->errorRateWarn);
        self::assertSame(0.10, $definition->errorRateHalt);
        self::assertSame([], $definition->dependencies);
        self::assertNull($definition->description);
        self::assertSame([], $definition->acknowledgedSaveAdvisoryCodes);
    }

    #[Test]
    public function save_advisory_code_allowlist_round_trips(): void
    {
        $definition = new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            acknowledgedSaveAdvisoryCodes: ['EDITORIAL_TITLE_REVIEW', 'RESERVED_PAGE_SLUG'],
        );

        self::assertSame(
            ['EDITORIAL_TITLE_REVIEW', 'RESERVED_PAGE_SLUG'],
            $definition->acknowledgedSaveAdvisoryCodes,
        );
    }

    #[Test]
    public function malformed_duplicate_and_oversized_save_advisory_allowlists_are_rejected(): void
    {
        foreach ([
            ['bad-code'],
            ['GOOD_CODE', 'GOOD_CODE'],
            array_fill(0, 33, 'GOOD_CODE'),
        ] as $codes) {
            try {
                new MigrationDefinition(
                    id: 'wp_posts',
                    source: $this->makeSource('wp_post'),
                    process: ['title' => 'post_title'],
                    destination: $this->makeDestination('node'),
                    acknowledgedSaveAdvisoryCodes: $codes,
                );
                self::fail('An invalid save advisory allowlist was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('acknowledgedSaveAdvisoryCodes', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function process_for_field_normalizes_bare_processor(): void
    {
        $processor = $this->makeProcess('concat');
        $definition = new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => $processor],
            destination: $this->makeDestination('node'),
        );

        self::assertSame([$processor], $definition->processForField('title'));
    }

    #[Test]
    public function process_for_field_normalizes_source_field_string(): void
    {
        $definition = new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
        );

        self::assertSame(['post_title'], $definition->processForField('title'));
    }

    #[Test]
    public function process_for_field_returns_chain_verbatim(): void
    {
        $a = $this->makeProcess('a');
        $b = $this->makeProcess('b');
        $definition = new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['slug' => [$a, 'post_slug', $b]],
            destination: $this->makeDestination('node'),
        );

        self::assertSame([$a, 'post_slug', $b], $definition->processForField('slug'));
    }

    #[Test]
    public function process_for_field_raises_on_unknown_destination_field(): void
    {
        $definition = $this->minimal();

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage("no process entry for destination field 'nope'");

        $definition->processForField('nope');
    }

    #[Test]
    public function empty_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$id must be a non-empty string');

        new MigrationDefinition(
            id: '',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
        );
    }

    #[Test]
    public function malformed_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('snake_case');

        new MigrationDefinition(
            id: 'WP-Posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
        );
    }

    #[Test]
    public function empty_process_map_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$process must declare at least one');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: [],
            destination: $this->makeDestination('node'),
        );
    }

    #[Test]
    public function empty_string_process_entry_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("string but is empty");

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => ''],
            destination: $this->makeDestination('node'),
        );
    }

    #[Test]
    public function malformed_process_entry_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a ProcessPluginInterface');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            /** @phpstan-ignore-next-line - intentional bad shape */
            process: ['title' => 42],
            destination: $this->makeDestination('node'),
        );
    }

    #[Test]
    public function empty_process_chain_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is an empty chain');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => []],
            destination: $this->makeDestination('node'),
        );
    }

    #[Test]
    public function chain_entry_with_invalid_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chain entry at index 1');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            /** @phpstan-ignore-next-line - intentional bad shape */
            process: ['title' => ['post_title', null]],
            destination: $this->makeDestination('node'),
        );
    }

    #[Test]
    public function self_referential_dependency_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('self-reference');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            dependencies: ['wp_posts'],
        );
    }

    #[Test]
    public function duplicate_dependency_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate id');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            dependencies: ['wp_users', 'wp_users'],
        );
    }

    #[Test]
    public function empty_dependency_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty string id');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            dependencies: [''],
        );
    }

    #[Test]
    public function bundle_round_trips_when_provided(): void
    {
        $definition = new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            bundle: 'article',
        );

        self::assertSame('article', $definition->bundle);
    }

    #[Test]
    public function bundle_defaults_to_null(): void
    {
        $definition = $this->minimal();

        self::assertNull($definition->bundle);
    }

    #[Test]
    public function empty_string_bundle_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$bundle must be null or a non-empty string');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            bundle: '',
        );
    }

    #[Test]
    public function negative_memory_budget_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$memoryBudgetBytes must be >= 0');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            memoryBudgetBytes: -1,
        );
    }

    #[Test]
    public function out_of_range_error_rate_warn_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$errorRateWarn must lie in [0.0, 1.0]');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            errorRateWarn: 1.5,
        );
    }

    #[Test]
    public function out_of_range_error_rate_halt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$errorRateHalt must lie in [0.0, 1.0]');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            errorRateHalt: -0.1,
        );
    }

    #[Test]
    public function warn_above_halt_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be <= $errorRateHalt');

        new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
            errorRateWarn: 0.2,
            errorRateHalt: 0.1,
        );
    }

    private function minimal(): MigrationDefinition
    {
        return new MigrationDefinition(
            id: 'wp_posts',
            source: $this->makeSource('wp_post'),
            process: ['title' => 'post_title'],
            destination: $this->makeDestination('node'),
        );
    }

    private function makeSource(string $id): SourcePluginInterface
    {
        return new class($id) implements SourcePluginInterface {
            public function __construct(private readonly string $pluginId) {}
            public function id(): string { return $this->pluginId; }
            public function stability(): string { return 'stable'; }
            public function records(): iterable { return []; }
            public function count(): ?int { return 0; }
            public function sourceIdFor(SourceRecord $record): SourceId
            {
                return new SourceId(sourceType: 'wp_post', keys: ['id' => 1]);
            }
        };
    }

    private function makeProcess(string $id): ProcessPluginInterface
    {
        return new class($id) implements ProcessPluginInterface {
            public function __construct(private readonly string $pluginId) {}
            public function id(): string { return $this->pluginId; }
            public function stability(): string { return 'stable'; }
            public function transform(mixed $value, ProcessContext $context): mixed { return $value; }
        };
    }

    private function makeDestination(string $id): DestinationPluginInterface
    {
        return new class($id) implements DestinationPluginInterface {
            public function __construct(private readonly string $pluginId) {}
            public function id(): string { return $this->pluginId; }
            public function stability(): string { return 'stable'; }
            public function write(DestinationRecord $record): WriteResult
            {
                return new WriteResult(
                    destinationEntityType: 'node',
                    destinationUuid: '00000000-0000-7000-8000-000000000000',
                    sourceRecordHash: 'hash',
                    runId: '00000000-0000-7000-8000-000000000001',
                    writtenAt: '2026-05-12T00:00:00Z',
                );
            }
            public function rollback(WriteResult $writeResult): void {}
            public function lookup(SourceId $sourceId): ?WriteResult { return null; }
        };
    }
}
