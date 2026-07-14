<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Plugin\Process;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Plugin\Process\ConcatProcessor;
use Waaseyaa\Migration\Plugin\Process\DefaultValueProcessor;
use Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor;
use Waaseyaa\Migration\Plugin\Process\LookupProcessor;
use Waaseyaa\Migration\Plugin\Process\PassThroughProcessor;
use Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

/**
 * Verifies chain semantics for the original scalar framework process plugins.
 *
 * The migration runner (landing in WP06) threads the output of plugin N into
 * the `$value` argument of plugin N+1, reusing the same {@see ProcessContext}.
 * These tests model that composition explicitly to lock the contract before
 * the runner exists.
 */
#[CoversNothing]
final class ProcessChainCompositionTest extends TestCase
{
    #[Test]
    public function pass_through_to_type_coerce_to_default(): void
    {
        // 'count' is provided as a numeric string; coerce to int; null-safe default.
        $ctx = $this->context(['count' => '42']);

        $chain = [
            new PassThroughProcessor('count'),
            new TypeCoerceProcessor('int'),
            new DefaultValueProcessor(default: 0),
        ];

        self::assertSame(42, $this->runChain($chain, $ctx));
    }

    #[Test]
    public function default_kicks_in_when_source_field_missing(): void
    {
        $ctx = $this->context([]); // 'count' absent

        $chain = [
            new PassThroughProcessor('count'),
            new DefaultValueProcessor(default: 0),
        ];

        self::assertSame(0, $this->runChain($chain, $ctx));
    }

    #[Test]
    public function concat_to_html_sanitize(): void
    {
        $ctx = $this->context([
            'first' => '<strong>Ada</strong>',
            'last' => 'Lovelace<script>x</script>',
        ]);

        $chain = [
            new ConcatProcessor(parts: ['@first', ' ', '@last']),
            new HtmlSanitizeProcessor('first'), // sourceField irrelevant when chain value is provided
        ];

        $result = $this->runChain($chain, $ctx);

        self::assertIsString($result);
        self::assertStringContainsString('Ada', $result);
        self::assertStringContainsString('Lovelace', $result);
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function lookup_to_default_for_missing_reference(): void
    {
        $ctx = $this->context(
            fields: ['author' => 99],
            lookup: static fn (string $m, SourceId $id): ?WriteResult => null,
        );

        $chain = [
            new LookupProcessor(sourceField: 'author', migration: 'wp_users'),
            new DefaultValueProcessor(default: 'anonymous-uuid'),
        ];

        self::assertSame('anonymous-uuid', $this->runChain($chain, $ctx));
    }

    #[Test]
    public function lookup_resolves_then_passes_through_chain(): void
    {
        $write = new WriteResult(
            destinationEntityType: 'user',
            destinationUuid: '0193f4d2-1111-7000-8000-000000000abc',
            sourceRecordHash: 'h',
            runId: 'r',
            writtenAt: '2026-05-13T00:00:00Z',
        );

        $ctx = $this->context(
            fields: ['author' => 42],
            lookup: static fn (string $m, SourceId $id): ?WriteResult => $write,
        );

        $chain = [
            new LookupProcessor(sourceField: 'author', migration: 'wp_users'),
            new DefaultValueProcessor(default: 'fallback'),
        ];

        self::assertSame('0193f4d2-1111-7000-8000-000000000abc', $this->runChain($chain, $ctx));
    }

    #[Test]
    public function chain_threads_one_context_changes_only_value(): void
    {
        // Confirm context identity is invariant across steps — the runner's
        // documented invariant.
        $ctx = $this->context(['n' => '7']);
        $observedContexts = [];

        $chain = [
            new class implements ProcessPluginInterface {
                public function id(): string
                {
                    return 'test_observer_1';
                }

                public function stability(): string
                {
                    return 'experimental';
                }

                public function transform(mixed $value, ProcessContext $context): mixed
                {
                    return $context->sourceRecord->field('n');
                }
            },
            new TypeCoerceProcessor('int'),
        ];

        $current = null;
        foreach ($chain as $plugin) {
            $observedContexts[] = $ctx;
            $current = $plugin->transform($current, $ctx);
        }

        self::assertSame(7, $current);
        self::assertSame($observedContexts[0], $observedContexts[1], 'Context identity preserved across steps.');
    }

    /**
     * @param list<ProcessPluginInterface> $chain
     */
    private function runChain(array $chain, ProcessContext $ctx): mixed
    {
        $value = null;
        foreach ($chain as $plugin) {
            $value = $plugin->transform($value, $ctx);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $fields
     * @param \Closure(string, SourceId): ?WriteResult|null $lookup
     */
    private function context(array $fields, ?\Closure $lookup = null): ProcessContext
    {
        return new ProcessContext(
            sourceRecord: new SourceRecord('wp', $fields),
            migrationId: 'm1',
            destinationField: 'composed',
            lookup: $lookup ?? static fn (string $m, SourceId $id): ?WriteResult => null,
        );
    }
}
