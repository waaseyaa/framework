<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Observability\Event\AgentRunIterationCompleted;
use Waaseyaa\AI\Observability\Event\AgentRunProviderCallCompleted;
use Waaseyaa\AI\Observability\Event\AgentRunStarted;
use Waaseyaa\AI\Observability\Event\AgentRunTerminated;
use Waaseyaa\AI\Observability\Event\AgentRunToolCallObserved;
use Waaseyaa\AI\Observability\Pricing\ModelPriceTable;
use Waaseyaa\AI\Observability\Recorder\AgentRunMetricsRecorderInterface;
use Waaseyaa\AI\Observability\Recorder\AgentTelescopeRecorderInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Aggregates per-AgentRun telemetry from domain events and flushes a
 * single envelope to the Telescope recorder + Prometheus metrics on
 * terminal status (FR-029).
 *
 * Subscribed events (see `packages/ai-observability/src/Event/`):
 *
 * - {@see AgentRunStarted} — initialises the aggregator slot.
 * - {@see AgentRunIterationCompleted} — appends to `iteration_durations_ms`.
 * - {@see AgentRunProviderCallCompleted} — sums tokens, computes cost via
 *   {@see ModelPriceTable}, emits provider-tokens metric.
 * - {@see AgentRunToolCallObserved} — increments `tool_call_count`.
 * - {@see AgentRunTerminated} — flushes the record, updates the AgentRun
 *   row, increments the terminal-status counter + wall-clock histogram.
 *
 * **Best-effort:** every handler is wrapped in try-catch; the handler
 * logs via the framework {@see LoggerInterface} and swallows the
 * exception so the primary run cannot crash on a telemetry fault
 * (constitution gotcha "Best-effort side effects").
 *
 * @api
 */
final class AgentRunTelemetryListener implements EventSubscriberInterface
{
    /**
     * Per-run aggregator state, keyed by `runId`.
     *
     * @var array<string, array{
     *   agent_definition_id: string|null,
     *   account_id: int|null,
     *   tokens_in: int,
     *   tokens_out: int,
     *   cost_cents: int,
     *   has_cost: bool,
     *   tool_call_count: int,
     *   iteration_durations_ms: list<int>,
     *   started_at: \DateTimeImmutable|null,
     * }>
     */
    private array $runs = [];

    private readonly LoggerInterface $logger;

    private readonly ModelPriceTable $priceTable;

    private readonly AgentRunMetricsRecorderInterface $metrics;

    public function __construct(
        private readonly AgentTelescopeRecorderInterface $telescope,
        private readonly AgentRunRepository $runRepository,
        ?ModelPriceTable $priceTable = null,
        ?AgentRunMetricsRecorderInterface $metrics = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->priceTable = $priceTable ?? new ModelPriceTable();
        $this->metrics = $metrics ?? new \Waaseyaa\AI\Observability\Recorder\NullAgentRunMetricsRecorder();
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AgentRunStarted::class => 'onRunStarted',
            AgentRunIterationCompleted::class => 'onIterationCompleted',
            AgentRunProviderCallCompleted::class => 'onProviderCallCompleted',
            AgentRunToolCallObserved::class => 'onToolCallObserved',
            AgentRunTerminated::class => 'onRunTerminated',
        ];
    }

    public function onRunStarted(AgentRunStarted $event): void
    {
        $this->safely(__METHOD__, $event->runId, function () use ($event): void {
            // A Messenger worker executes one AgentRun at a time. Replacing the
            // map here reclaims an interrupted run whose terminal event never
            // arrived instead of retaining it for the worker's lifetime.
            $this->runs = [$event->runId => [
                'agent_definition_id' => $event->agentDefinitionId,
                'account_id' => $event->accountId,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'cost_cents' => 0,
                'has_cost' => false,
                'tool_call_count' => 0,
                'iteration_durations_ms' => [],
                'started_at' => $event->startedAt,
            ]];
        });
    }

    public function onIterationCompleted(AgentRunIterationCompleted $event): void
    {
        $this->safely(__METHOD__, $event->runId, function () use ($event): void {
            $this->ensureSlot($event->runId);
            $duration = \max(0, $event->durationMs);
            $this->runs[$event->runId]['iteration_durations_ms'][] = $duration;
        });
    }

    public function onProviderCallCompleted(AgentRunProviderCallCompleted $event): void
    {
        $this->safely(__METHOD__, $event->runId, function () use ($event): void {
            $this->ensureSlot($event->runId);
            $slot = &$this->runs[$event->runId];
            $slot['tokens_in'] += \max(0, $event->tokensIn);
            $slot['tokens_out'] += \max(0, $event->tokensOut);

            $providerModel = $event->provider . ':' . $event->model;
            $cost = $this->priceTable->priceCentsFor(
                $providerModel,
                \max(0, $event->tokensIn),
                \max(0, $event->tokensOut),
            );

            if ($cost !== null) {
                $slot['cost_cents'] += $cost;
                $slot['has_cost'] = true;
            }

            // Best-effort metric fan-out.
            try {
                $this->metrics->recordProviderTokens(
                    $event->provider,
                    $event->model,
                    \max(0, $event->tokensIn),
                    \max(0, $event->tokensOut),
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'AgentRunTelemetryListener: provider-token metric emit failed',
                    ['run_id' => $event->runId, 'exception' => $e::class, 'message' => $e->getMessage()],
                );
            }
        });
    }

    public function onToolCallObserved(AgentRunToolCallObserved $event): void
    {
        $this->safely(__METHOD__, $event->runId, function () use ($event): void {
            $this->ensureSlot($event->runId);
            $this->runs[$event->runId]['tool_call_count']++;
        });
    }

    public function onRunTerminated(AgentRunTerminated $event): void
    {
        $this->safely(__METHOD__, $event->runId, function () use ($event): void {
            if (!isset($this->runs[$event->runId])) {
                // No prior `run_started` observed (test harness, kernel
                // boot race, or replay). Initialise an empty slot so the
                // flush still produces a record with sensible defaults.
                $this->ensureSlot($event->runId);
            }

            $slot = $this->runs[$event->runId];
            $startedAt = $slot['started_at'];
            $finishedAt = $event->finishedAt;
            $wallClockMs = null;
            if ($startedAt !== null) {
                $deltaSeconds = $finishedAt->getTimestamp() - $startedAt->getTimestamp();
                $deltaMicros = (int) $finishedAt->format('u') - (int) $startedAt->format('u');
                $wallClockMs = \max(0, $deltaSeconds * 1000 + \intdiv($deltaMicros, 1000));
            }

            $record = [
                'run_id' => $event->runId,
                'agent_definition_id' => $slot['agent_definition_id'],
                'account_id' => $slot['account_id'],
                'tokens_in' => $slot['tokens_in'],
                'tokens_out' => $slot['tokens_out'],
                'cost_cents' => $slot['has_cost'] ? $slot['cost_cents'] : null,
                'tool_call_count' => $slot['tool_call_count'],
                'wall_clock_ms' => $wallClockMs,
                'iteration_durations_ms' => $slot['iteration_durations_ms'],
                'status' => $event->status,
                'error_code' => $event->errorCode,
                'started_at' => $startedAt?->format(\DateTimeInterface::ATOM),
                'finished_at' => $finishedAt->format(\DateTimeInterface::ATOM),
            ];

            // Persist to Telescope (best-effort, isolated).
            try {
                $this->telescope->recordAgentRun($record);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'AgentRunTelemetryListener: Telescope recordAgentRun failed',
                    ['run_id' => $event->runId, 'exception' => $e::class, 'message' => $e->getMessage()],
                );
            }

            // Update AgentRun row with terminal-status telemetry (sole
            // allowed listener-side write to AgentRun, per WP08 spec).
            try {
                $run = $this->runRepository->find($event->runId);
                if ($run instanceof AgentRun) {
                    $run->set('token_usage_in', $slot['tokens_in']);
                    $run->set('token_usage_out', $slot['tokens_out']);
                    if ($slot['has_cost']) {
                        $run->set('cost_cents', $slot['cost_cents']);
                    }
                    $run->set('tool_call_count', $slot['tool_call_count']);
                    $this->runRepository->save($run);
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'AgentRunTelemetryListener: AgentRun row update failed',
                    ['run_id' => $event->runId, 'exception' => $e::class, 'message' => $e->getMessage()],
                );
            }

            // Prometheus counters (best-effort, isolated).
            try {
                $this->metrics->recordTerminalRun(
                    $event->status,
                    $slot['agent_definition_id'],
                    $wallClockMs,
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'AgentRunTelemetryListener: terminal-run metric emit failed',
                    ['run_id' => $event->runId, 'exception' => $e::class, 'message' => $e->getMessage()],
                );
            }

            // Aggregator slot is consumed — drop to reclaim memory.
            unset($this->runs[$event->runId]);
        });
    }

    /**
     * Best-effort handler wrapper — never propagates listener faults
     * to the primary run. Constitution: "Best-effort side effects".
     */
    private function safely(string $method, string $runId, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logger->error(
                'AgentRunTelemetryListener: handler swallowed exception',
                [
                    'handler' => $method,
                    'run_id' => $runId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );
        }
    }

    private function ensureSlot(string $runId): void
    {
        if (isset($this->runs[$runId])) {
            return;
        }

        $this->runs[$runId] = [
            'agent_definition_id' => null,
            'account_id' => null,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_cents' => 0,
            'has_cost' => false,
            'tool_call_count' => 0,
            'iteration_durations_ms' => [],
            'started_at' => null,
        ];
    }
}
