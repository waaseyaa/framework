<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Storage;

use Waaseyaa\Telescope\CodifiedContext\CodifiedContextEntry;
use Waaseyaa\Telescope\Storage\TelescopeStoreInterface;
use Waaseyaa\Telescope\TelescopeEntry;

/**
 * Metrics-only adapter that tracks counters/gauges in-process.
 *
 * Optionally delegates storage to an inner TelescopeStoreInterface for dual-write.
 * Does not persist entries itself — use alongside a real store for durable storage.
 */
final class PrometheusCodifiedContextStore implements CodifiedContextStoreInterface
{
    private int $sessionsTotal = 0;
    private int $eventsTotal = 0;
    private int $driftEventsTotal = 0;
    private int $validationsTotal = 0;
    private float $driftScoreSum = 0.0;
    private int $driftScoreCount = 0;

    /** @var array<string, int> */
    private array $sessionsSeen = [];

    public function __construct(
        private readonly ?TelescopeStoreInterface $inner = null,
    ) {
    }

    public function store(string $type, array $data): void
    {
        $this->eventsTotal++;

        // Track unique sessions.
        $sessionId = $data['session_id'] ?? '';
        if ($sessionId !== '' && !isset($this->sessionsSeen[$sessionId])) {
            $this->sessionsSeen[$sessionId] = 1;
            $this->sessionsTotal++;
        }

        // Track drift events.
        if (str_contains($type, 'drift') || isset($data['severity'])) {
            $this->driftEventsTotal++;
        }

        // Track validations.
        if (str_contains($type, 'validation') || str_contains($type, 'validate')) {
            $this->validationsTotal++;
        }

        // Track drift score.
        if (isset($data['drift_score']) && is_numeric($data['drift_score'])) {
            $this->driftScoreSum += (float) $data['drift_score'];
            $this->driftScoreCount++;
        }

        $this->inner?->store($type, $data);
    }

    public function query(string $type, int $limit = 50, int $offset = 0): array
    {
        if ($this->inner !== null) {
            return $this->inner->query($type, $limit, $offset);
        }

        return [];
    }

    public function queryBySession(string $sessionId, int $limit = 100, int $offset = 0): array
    {
        if ($this->inner instanceof CodifiedContextStoreInterface) {
            return $this->inner->queryBySession($sessionId, $limit, $offset);
        }

        return [];
    }

    public function queryByEventType(string $eventType, int $limit = 50, int $offset = 0): array
    {
        if ($this->inner instanceof CodifiedContextStoreInterface) {
            return $this->inner->queryByEventType($eventType, $limit, $offset);
        }

        return [];
    }

    public function queryByTimeRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 100): array
    {
        if ($this->inner instanceof CodifiedContextStoreInterface) {
            return $this->inner->queryByTimeRange($from, $to, $limit);
        }

        return [];
    }

    public function queryByDriftSeverity(string $severity, int $limit = 50, int $offset = 0): array
    {
        if ($this->inner instanceof CodifiedContextStoreInterface) {
            return $this->inner->queryByDriftSeverity($severity, $limit, $offset);
        }

        return [];
    }

    public function prune(\DateTimeInterface $before): int
    {
        if ($this->inner !== null) {
            return $this->inner->prune($before);
        }

        return 0;
    }

    public function clear(): void
    {
        $this->inner?->clear();
    }

    /**
     * Get all tracked metric values.
     *
     * @return array<string, int|float>
     */
    public function getMetrics(): array
    {
        return [
            'waaseyaa_cc_sessions_total' => $this->sessionsTotal,
            'waaseyaa_cc_events_total' => $this->eventsTotal,
            'waaseyaa_cc_drift_events_total' => $this->driftEventsTotal,
            'waaseyaa_cc_validations_total' => $this->validationsTotal,
            'waaseyaa_cc_drift_score_avg' => $this->driftScoreCount > 0
                ? $this->driftScoreSum / $this->driftScoreCount
                : 0.0,
        ];
    }

    /**
     * Render metrics in Prometheus text exposition format.
     */
    public function renderPrometheusOutput(): string
    {
        $metrics = $this->getMetrics();

        $lines = [];

        $lines[] = '# HELP waaseyaa_cc_sessions_total Total number of unique codified context sessions observed.';
        $lines[] = '# TYPE waaseyaa_cc_sessions_total counter';
        $lines[] = 'waaseyaa_cc_sessions_total ' . $metrics['waaseyaa_cc_sessions_total'];

        $lines[] = '# HELP waaseyaa_cc_events_total Total number of codified context events stored.';
        $lines[] = '# TYPE waaseyaa_cc_events_total counter';
        $lines[] = 'waaseyaa_cc_events_total ' . $metrics['waaseyaa_cc_events_total'];

        $lines[] = '# HELP waaseyaa_cc_drift_events_total Total number of drift-related events observed.';
        $lines[] = '# TYPE waaseyaa_cc_drift_events_total counter';
        $lines[] = 'waaseyaa_cc_drift_events_total ' . $metrics['waaseyaa_cc_drift_events_total'];

        $lines[] = '# HELP waaseyaa_cc_validations_total Total number of validation events observed.';
        $lines[] = '# TYPE waaseyaa_cc_validations_total counter';
        $lines[] = 'waaseyaa_cc_validations_total ' . $metrics['waaseyaa_cc_validations_total'];

        $lines[] = '# HELP waaseyaa_cc_drift_score_avg Running average of drift scores.';
        $lines[] = '# TYPE waaseyaa_cc_drift_score_avg gauge';
        $lines[] = 'waaseyaa_cc_drift_score_avg ' . $metrics['waaseyaa_cc_drift_score_avg'];

        return implode("\n", $lines) . "\n";
    }
}
