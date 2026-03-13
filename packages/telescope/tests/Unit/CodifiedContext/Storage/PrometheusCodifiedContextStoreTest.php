<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Storage\CodifiedContextStoreInterface;
use Waaseyaa\Telescope\CodifiedContext\Storage\PrometheusCodifiedContextStore;
use Waaseyaa\Telescope\CodifiedContext\Storage\SqliteCodifiedContextStore;

#[CoversClass(PrometheusCodifiedContextStore::class)]
final class PrometheusCodifiedContextStoreTest extends TestCase
{
    private PrometheusCodifiedContextStore $store;

    protected function setUp(): void
    {
        $this->store = new PrometheusCodifiedContextStore();
    }

    #[Test]
    public function implementsCodifiedContextStoreInterface(): void
    {
        $this->assertInstanceOf(CodifiedContextStoreInterface::class, $this->store);
    }

    #[Test]
    public function initialMetricsAreZero(): void
    {
        $metrics = $this->store->getMetrics();

        $this->assertSame(0, $metrics['waaseyaa_cc_sessions_total']);
        $this->assertSame(0, $metrics['waaseyaa_cc_events_total']);
        $this->assertSame(0, $metrics['waaseyaa_cc_drift_events_total']);
        $this->assertSame(0, $metrics['waaseyaa_cc_validations_total']);
        $this->assertSame(0.0, $metrics['waaseyaa_cc_drift_score_avg']);
    }

    #[Test]
    public function storeIncrementsEventCounter(): void
    {
        $this->store->store('some_event', ['session_id' => 'sess-1']);
        $this->store->store('some_event', ['session_id' => 'sess-2']);

        $this->assertSame(2, $this->store->getMetrics()['waaseyaa_cc_events_total']);
    }

    #[Test]
    public function storeTracksUniqueSessionsOnly(): void
    {
        $this->store->store('event', ['session_id' => 'sess-A']);
        $this->store->store('event', ['session_id' => 'sess-A']); // Same session.
        $this->store->store('event', ['session_id' => 'sess-B']);

        $this->assertSame(2, $this->store->getMetrics()['waaseyaa_cc_sessions_total']);
        $this->assertSame(3, $this->store->getMetrics()['waaseyaa_cc_events_total']);
    }

    #[Test]
    public function storeTracksDriftEventsWhenTypeContainsDrift(): void
    {
        $this->store->store('drift_detected', ['session_id' => 'sess-1']);
        $this->store->store('validation', ['session_id' => 'sess-1']);
        $this->store->store('drift_corrected', ['session_id' => 'sess-1']);

        $this->assertSame(2, $this->store->getMetrics()['waaseyaa_cc_drift_events_total']);
    }

    #[Test]
    public function storeTracksDriftEventsWhenDataHasSeverity(): void
    {
        $this->store->store('some_event', ['session_id' => 'sess-1', 'severity' => 'high']);

        $this->assertSame(1, $this->store->getMetrics()['waaseyaa_cc_drift_events_total']);
    }

    #[Test]
    public function storeTracksValidationEvents(): void
    {
        $this->store->store('validation_passed', ['session_id' => 'sess-1']);
        $this->store->store('validate_schema', ['session_id' => 'sess-2']);
        $this->store->store('drift_detected', ['session_id' => 'sess-3']);

        $this->assertSame(2, $this->store->getMetrics()['waaseyaa_cc_validations_total']);
    }

    #[Test]
    public function storeComputesDriftScoreAverage(): void
    {
        $this->store->store('drift', ['session_id' => 's1', 'drift_score' => 0.4]);
        $this->store->store('drift', ['session_id' => 's2', 'drift_score' => 0.6]);

        $avg = $this->store->getMetrics()['waaseyaa_cc_drift_score_avg'];

        $this->assertEqualsWithDelta(0.5, $avg, 0.0001);
    }

    #[Test]
    public function driftScoreAverageIsZeroWhenNoScores(): void
    {
        $this->store->store('event', ['session_id' => 'sess-1']);

        $this->assertSame(0.0, $this->store->getMetrics()['waaseyaa_cc_drift_score_avg']);
    }

    #[Test]
    public function queryReturnsEmptyWithoutInnerStore(): void
    {
        $this->store->store('event', ['session_id' => 's1']);

        $this->assertSame([], $this->store->query('event'));
    }

    #[Test]
    public function queryBySessionReturnsEmptyWithoutInnerStore(): void
    {
        $this->assertSame([], $this->store->queryBySession('sess-1'));
    }

    #[Test]
    public function queryByEventTypeReturnsEmptyWithoutInnerStore(): void
    {
        $this->assertSame([], $this->store->queryByEventType('drift_detected'));
    }

    #[Test]
    public function queryByTimeRangeReturnsEmptyWithoutInnerStore(): void
    {
        $from = new \DateTimeImmutable('-1 hour');
        $to = new \DateTimeImmutable('+1 hour');

        $this->assertSame([], $this->store->queryByTimeRange($from, $to));
    }

    #[Test]
    public function queryByDriftSeverityReturnsEmptyWithoutInnerStore(): void
    {
        $this->assertSame([], $this->store->queryByDriftSeverity('high'));
    }

    #[Test]
    public function pruneReturnsZeroWithoutInnerStore(): void
    {
        $this->assertSame(0, $this->store->prune(new \DateTimeImmutable()));
    }

    #[Test]
    public function dualWriteDelegatesToInnerStore(): void
    {
        $inner = SqliteCodifiedContextStore::createInMemory();
        $store = new PrometheusCodifiedContextStore($inner);

        $store->store('event', ['session_id' => 'sess-1']);

        // Metrics tracked.
        $this->assertSame(1, $store->getMetrics()['waaseyaa_cc_events_total']);

        // Also written to inner store.
        $entries = $store->query('event');
        $this->assertCount(1, $entries);
    }

    #[Test]
    public function queryBySessionDelegatesToInnerCodifiedStore(): void
    {
        $inner = SqliteCodifiedContextStore::createInMemory();
        $store = new PrometheusCodifiedContextStore($inner);

        $store->store('event', ['session_id' => 'sess-X', 'severity' => 'low']);

        $entries = $store->queryBySession('sess-X');
        $this->assertCount(1, $entries);
        $this->assertSame('sess-X', $entries[0]->sessionId);
    }

    #[Test]
    public function renderPrometheusOutputContainsAllMetrics(): void
    {
        $this->store->store('drift_detected', ['session_id' => 'sess-1', 'drift_score' => 0.75]);
        $this->store->store('validation_passed', ['session_id' => 'sess-2']);

        $output = $this->store->renderPrometheusOutput();

        $this->assertStringContainsString('# HELP waaseyaa_cc_sessions_total', $output);
        $this->assertStringContainsString('# TYPE waaseyaa_cc_sessions_total counter', $output);
        $this->assertStringContainsString('waaseyaa_cc_sessions_total 2', $output);

        $this->assertStringContainsString('# HELP waaseyaa_cc_events_total', $output);
        $this->assertStringContainsString('# TYPE waaseyaa_cc_events_total counter', $output);
        $this->assertStringContainsString('waaseyaa_cc_events_total 2', $output);

        $this->assertStringContainsString('# HELP waaseyaa_cc_drift_events_total', $output);
        $this->assertStringContainsString('# TYPE waaseyaa_cc_drift_events_total counter', $output);
        $this->assertStringContainsString('waaseyaa_cc_drift_events_total 1', $output);

        $this->assertStringContainsString('# HELP waaseyaa_cc_validations_total', $output);
        $this->assertStringContainsString('# TYPE waaseyaa_cc_validations_total counter', $output);
        $this->assertStringContainsString('waaseyaa_cc_validations_total 1', $output);

        $this->assertStringContainsString('# HELP waaseyaa_cc_drift_score_avg', $output);
        $this->assertStringContainsString('# TYPE waaseyaa_cc_drift_score_avg gauge', $output);
        $this->assertStringContainsString('waaseyaa_cc_drift_score_avg', $output);
    }

    #[Test]
    public function renderPrometheusOutputEndsWithNewline(): void
    {
        $output = $this->store->renderPrometheusOutput();

        $this->assertStringEndsWith("\n", $output);
    }
}
