<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Testing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;

/**
 * Subclass-and-pass contract test for {@see SourcePluginInterface} implementations.
 *
 * Third-party migration plugin authors extend this class and provide three
 * factory methods that wire their plugin against in-memory fixtures. The
 * eight `#[Test]` gates below codify the FR-049 / FR-051 contract: every
 * source plugin shipped against this framework must answer "yes" to all of
 * them.
 *
 * ## Subclass contract
 *
 * - {@see buildPluginUnderTest()} returns the plugin instance backed by
 *   {@see buildSmallFixturePath()}.
 * - {@see buildSmallFixturePath()} returns an absolute path to a fixture
 *   that yields ≤ 100 records. The implementer owns the file's lifecycle
 *   (committed, generated in `setUp()`, etc.).
 * - {@see buildLargeFixturePath()} returns an absolute path to a fixture
 *   large enough to stress streaming (the conformance contract is
 *   "memory bound ≤ 50 MB above baseline"). Implementers commonly generate
 *   this in `setUp()` and clean it up in `tearDown()`.
 *
 * ## Notes for implementers
 *
 * - The base class never opens a database or touches the filesystem beyond
 *   the paths your factory methods return. Each gate runs against a fresh
 *   plugin instance constructed via {@see buildPluginUnderTest()}.
 * - C5's memory assertion uses a generous absolute threshold (50 MB) and
 *   triggers `gc_collect_cycles()` to stabilise the baseline. CI noise is
 *   the principal risk; tune `LARGE_FIXTURE_MEMORY_BUDGET_BYTES` in a
 *   subclass if your platform behaves differently.
 * - C6 documents the rewind contract: most generator-backed sources cannot
 *   `rewind()`. The base asserts a **fresh-instance** equivalence instead —
 *   build two plugin instances against the same fixture and assert the
 *   first record matches.
 * - C7's error path expects {@see SourceReadException}. Plugins documenting
 *   a different exception class must override {@see expectedMissingSourceException()}.
 *
 * @api Conformance suite — public contract for third-party plugin authors.
 *
 * @spec FR-049 — stable source plugin contract
 * @spec FR-051 — streaming memory bound
 * @spec FR-052 — reference CSV source plugin demonstrates this base
 */
abstract class SourceConformanceTestCase extends TestCase
{
    /**
     * Absolute memory ceiling for C5's streaming assertion. Override in a
     * subclass if your CI runner shows different baseline noise.
     */
    protected const int LARGE_FIXTURE_MEMORY_BUDGET_BYTES = 50 * 1024 * 1024;

    /**
     * Build the plugin instance under test, backed by {@see buildSmallFixturePath()}.
     */
    abstract protected function buildPluginUnderTest(): SourcePluginInterface;

    /**
     * Absolute path to a small fixture (≤ 100 records).
     */
    abstract protected function buildSmallFixturePath(): string;

    /**
     * Absolute path to a fixture large enough to exercise the streaming
     * memory bound (C5). Often generated at test time — implementers own
     * the lifecycle.
     */
    abstract protected function buildLargeFixturePath(): string;

    /**
     * Build a plugin instance pointed at the (large) fixture. Override if
     * your plugin needs different construction args for large vs small.
     */
    protected function buildPluginUnderTestForLargeFixture(): SourcePluginInterface
    {
        return $this->buildPluginForFixture($this->buildLargeFixturePath());
    }

    /**
     * Build a plugin instance pointed at the supplied fixture path. Most
     * subclasses only need to override this single helper; the rest of the
     * conformance base reuses it.
     *
     * Default implementation calls {@see buildPluginUnderTest()} — override
     * if the small/large variants need different keys or delimiters.
     */
    protected function buildPluginForFixture(string $fixturePath): SourcePluginInterface
    {
        unset($fixturePath); // unused in the default branch; subclasses override

        return $this->buildPluginUnderTest();
    }

    /**
     * Exception class the plugin raises when its source target is missing.
     * Override if your plugin documents a different typed exception.
     *
     * @return class-string<\Throwable>
     */
    protected function expectedMissingSourceException(): string
    {
        return SourceReadException::class;
    }

    /**
     * Build a plugin instance configured to point at a non-existent target
     * so C7 can assert the typed exception. Override if your plugin's
     * "missing" path is constructed differently.
     */
    protected function buildPluginPointingAtMissingSource(): SourcePluginInterface
    {
        $missing = \sys_get_temp_dir() . '/waaseyaa_conformance_missing_' . \uniqid('', true);

        return $this->buildPluginForFixture($missing);
    }

    /**
     * @gate C1 — `records()` is a lazy iterable yielding SourceRecord instances (FR-049).
     */
    #[Test]
    public function c1_records_yields_source_record_instances_lazily(): void
    {
        $plugin = $this->buildPluginUnderTest();
        $records = $plugin->records();

        self::assertInstanceOf(
            \Traversable::class,
            $records,
            'SourcePluginInterface::records() MUST return a Traversable.',
        );
        self::assertNotInstanceOf(
            \ArrayAccess::class,
            $records,
            'SourcePluginInterface::records() MUST NOT return a pre-loaded ArrayAccess collection — generator-style streaming is the contract.',
        );

        $first = null;
        foreach ($records as $record) {
            $first = $record;
            break;
        }

        self::assertInstanceOf(
            SourceRecord::class,
            $first,
            'SourcePluginInterface::records() MUST yield SourceRecord instances.',
        );
    }

    /**
     * @gate C2 — `sourceIdFor()` is deterministic for the same record (FR-049).
     */
    #[Test]
    public function c2_source_id_for_is_deterministic_per_record(): void
    {
        $plugin = $this->buildPluginUnderTest();
        $first = $this->firstRecord($plugin);

        $a = $plugin->sourceIdFor($first);
        $b = $plugin->sourceIdFor($first);

        self::assertSame(
            $a->hash(),
            $b->hash(),
            'sourceIdFor() MUST be pure — identical records must yield identical SourceId hashes.',
        );
    }

    /**
     * @gate C3 — SourceId hashes are stable across multiple plugin instances (FR-049, FR-052).
     */
    #[Test]
    public function c3_source_id_hash_is_stable_across_plugin_instances(): void
    {
        $first = $this->firstRecord($this->buildPluginUnderTest());
        $second = $this->firstRecord($this->buildPluginUnderTest());

        $firstId = $this->buildPluginUnderTest()->sourceIdFor($first);
        $secondId = $this->buildPluginUnderTest()->sourceIdFor($second);

        self::assertSame(
            $firstId->hash(),
            $secondId->hash(),
            'Two plugin instances pointed at the same fixture must compute identical SourceId hashes for the first record.',
        );
    }

    /**
     * @gate C4 — `count()` returns a non-negative int or null (FR-049).
     */
    #[Test]
    public function c4_count_is_null_or_non_negative_int(): void
    {
        $plugin = $this->buildPluginUnderTest();
        $count = $plugin->count();

        if ($count === null) {
            self::assertNull($count);

            return;
        }

        self::assertGreaterThanOrEqual(
            0,
            $count,
            'SourcePluginInterface::count() must return a non-negative int when it is not null.',
        );
    }

    /**
     * @gate C5 — Streaming memory bound: large fixture stays under
     *           LARGE_FIXTURE_MEMORY_BUDGET_BYTES above baseline (FR-051).
     */
    #[Test]
    public function c5_streaming_memory_bound_for_large_fixture(): void
    {
        \gc_collect_cycles();
        // Peak usage is process-wide. Reset it so randomized predecessors do
        // not get charged to this plugin's streaming-memory delta.
        \memory_reset_peak_usage();
        $baseline = \memory_get_usage(true);

        $plugin = $this->buildPluginUnderTestForLargeFixture();

        $count = 0;
        foreach ($plugin->records() as $record) {
            // Force a minimal touch so the iteration isn't optimised away;
            // do not retain references.
            self::assertInstanceOf(SourceRecord::class, $record);
            ++$count;
            unset($record);
        }

        \gc_collect_cycles();
        $peak = \memory_get_peak_usage(true);
        $growth = $peak - $baseline;

        self::assertGreaterThan(
            0,
            $count,
            'Large fixture must yield at least one record so C5 actually exercises streaming.',
        );
        self::assertLessThanOrEqual(
            self::LARGE_FIXTURE_MEMORY_BUDGET_BYTES,
            $growth,
            \sprintf(
                'Streaming memory growth %d bytes exceeded conformance budget %d bytes (FR-051).',
                $growth,
                self::LARGE_FIXTURE_MEMORY_BUDGET_BYTES,
            ),
        );
    }

    /**
     * @gate C6 — Fresh-instance idempotent re-iteration (FR-049).
     *
     * Most plugins back `records()` with a generator and CANNOT be rewound.
     * The contract is therefore "a fresh plugin instance yields the same
     * first record" — which is what every callable iteration shape can
     * honour. Implementers whose generator IS rewindable still pass this
     * gate because identical first records emerge from any starting point.
     */
    #[Test]
    public function c6_fresh_plugin_instance_is_idempotent_for_first_record(): void
    {
        $first = $this->firstRecord($this->buildPluginUnderTest());
        $second = $this->firstRecord($this->buildPluginUnderTest());

        self::assertSame(
            $first->sourceType,
            $second->sourceType,
            'Fresh plugin instances must yield the same sourceType for the first record.',
        );
        self::assertSame(
            $first->fields,
            $second->fields,
            'Fresh plugin instances must yield identical fields for the first record (sources MUST be deterministic — FR-049).',
        );
    }

    /**
     * @gate C7 — Error path: missing source target raises the documented
     *           typed exception (defaults to SourceReadException, FR-049).
     */
    #[Test]
    public function c7_missing_source_raises_typed_exception(): void
    {
        $expected = $this->expectedMissingSourceException();
        $plugin = $this->buildPluginPointingAtMissingSource();

        try {
            foreach ($plugin->records() as $_record) {
                // Drain — most plugins fail on first read, generators may fail
                // on iteration rather than construction.
                unset($_record);
            }
            self::fail(\sprintf('Expected %s when source target is missing.', $expected));
        } catch (\Throwable $e) {
            self::assertInstanceOf(
                $expected,
                $e,
                \sprintf(
                    'Missing source must raise %s, got %s: %s',
                    $expected,
                    $e::class,
                    $e->getMessage(),
                ),
            );
        }
    }

    /**
     * @gate C8 — `id()` is a non-empty, stable string (FR-049).
     */
    #[Test]
    public function c8_id_is_non_empty_and_stable(): void
    {
        $plugin = $this->buildPluginUnderTest();

        $id = $plugin->id();
        self::assertNotSame('', $id, 'SourcePluginInterface::id() must be non-empty.');
        self::assertSame(
            $id,
            $plugin->id(),
            'SourcePluginInterface::id() must be stable across calls on the same instance.',
        );
        self::assertMatchesRegularExpression(
            '/^[a-z][a-z0-9_]*$/',
            $id,
            'SourcePluginInterface::id() must be a snake_case token (FR-049).',
        );
    }

    /**
     * Drain the first record from the plugin. Helper for the gates above.
     */
    private function firstRecord(SourcePluginInterface $plugin): SourceRecord
    {
        foreach ($plugin->records() as $record) {
            return $record;
        }

        self::fail('Plugin yielded zero records — fixture must contain at least one row for conformance.');
    }
}
