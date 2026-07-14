<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Reaper;

use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Recover {@see \Waaseyaa\AI\Agent\Entity\AgentRun} rows abandoned before
 * reaching a terminal state (NFR-004, FR-007).
 *
 * Running, approval, and cancellation rows are aged from `started_at`;
 * never-claimed queued rows are aged from `queued_at`. Expired rows move to
 * a status-appropriate terminal outcome, append an audit row, and broadcast
 * the terminal event.
 *
 * Honours C-014: transitions go through
 * {@see AgentRunRepository::markTerminal()}, which is a compare-and-swap
 * that refuses to overwrite an already-terminal row. A worker that
 * completed in the window between selection and update therefore "wins"
 * — the reaper sees `markTerminal() === false` and skips that row.
 *
 * @api
 */
final class StalledRunReaper
{
    private readonly LoggerInterface $logger;

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $now;

    /** @var \Closure(): string */
    private \Closure $idFactory;

    public function __construct(
        private readonly AgentRunRepository $runRepository,
        private readonly AgentAuditLogRepository $auditRepository,
        private readonly AgentRunBroadcasterInterface $broadcaster,
        ?LoggerInterface $logger = null,
        ?\Closure $now = null,
        ?\Closure $idFactory = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->now = $now ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now');
        $this->idFactory = $idFactory ?? static fn(): string => self::uuidV4();
    }

    /**
     * Scan for stalled rows and flip them to terminal `failed`.
     *
     * @param int $maxRuntimeSeconds Threshold: rows whose `started_at`
     *     is older than this many seconds count as stalled.
     * @return int Count of rows successfully transitioned (excludes
     *     races where another worker reached terminal first).
     */
    public function reap(int $maxRuntimeSeconds): int
    {
        if ($maxRuntimeSeconds <= 0) {
            throw new \InvalidArgumentException(\sprintf(
                'StalledRunReaper: maxRuntimeSeconds must be positive; got %d.',
                $maxRuntimeSeconds,
            ));
        }

        $now = ($this->now)();
        $threshold = $now->sub(new \DateInterval('PT' . $maxRuntimeSeconds . 'S'));

        $rows = [
            ...$this->runRepository->findStuckRunning($threshold),
            ...$this->runRepository->findAbandoned($threshold, $now),
        ];
        $flipped = 0;

        foreach ($rows as $run) {
            $runId = (string) $run->get('id');

            [$terminalStatus, $errorCode, $errorMessage] = $this->terminalOutcome(
                $run->getStatus(),
                $maxRuntimeSeconds,
                $threshold,
            );
            $advanced = $this->runRepository->markTerminal(
                $runId,
                $terminalStatus,
                $now,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
                expectedStatus: $run->getStatus(),
            );

            if (!$advanced) {
                // C-014: the row reached terminal between our select
                // and our update. Leave it; the winner's terminal data
                // is authoritative.
                continue;
            }

            $this->appendErrorAudit($runId, $errorCode);
            $this->broadcastTerminal($runId, $terminalStatus, $errorCode, $errorMessage);
            $flipped++;
        }

        if ($flipped > 0) {
            $this->logger->info(\sprintf(
                'StalledRunReaper: terminalized %d abandoned run(s).',
                $flipped,
            ));
        }

        return $flipped;
    }

    private function appendErrorAudit(string $runId, string $errorCode): void
    {
        try {
            $entry = AgentAuditLog::for(
                id: ($this->idFactory)(),
                runId: $runId,
                iteration: 0,
                eventType: EventType::Error,
                occurredAt: ($this->now)(),
                success: false,
                toolName: null,
                toolResultSummary: $errorCode,
            );
            $this->auditRepository->append($entry);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf(
                'StalledRunReaper: failed to append error audit for run "%s": %s',
                $runId,
                $e->getMessage(),
            ));
        }
    }

    private function broadcastTerminal(
        string $runId,
        RunStatus $status,
        string $errorCode,
        string $errorMessage,
    ): void {
        try {
            $this->broadcaster->push($runId, $status === RunStatus::Cancelled ? 'run_cancelled' : 'run_failed', [
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf(
                'StalledRunReaper: failed to push run_failed SSE for run "%s": %s',
                $runId,
                $e->getMessage(),
            ));
        }
    }

    /** @return array{RunStatus, string, string} */
    private function terminalOutcome(
        RunStatus $status,
        int $maxRuntimeSeconds,
        \DateTimeImmutable $threshold,
    ): array {
        return match ($status) {
            RunStatus::Queued => [
                RunStatus::Failed,
                'queue_timeout',
                \sprintf('No worker claimed the run within %d seconds.', $maxRuntimeSeconds),
            ],
            RunStatus::AwaitingApproval => [
                RunStatus::Failed,
                'approval_timeout',
                \sprintf('Approval did not complete before the %d second worker TTL.', $maxRuntimeSeconds),
            ],
            RunStatus::Cancelling => [
                RunStatus::Cancelled,
                'cancellation_timeout',
                \sprintf('Cancellation was terminalized after the %d second worker TTL.', $maxRuntimeSeconds),
            ],
            default => [
                RunStatus::Failed,
                'worker_crashed',
                \sprintf(
                    'Worker crashed: started_at older than %d seconds (last started_at < %s).',
                    $maxRuntimeSeconds,
                    $threshold->format(\DateTimeInterface::ATOM),
                ),
            ],
        };
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
