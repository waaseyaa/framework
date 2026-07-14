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
 * Running and cancellation rows are aged from `started_at`, never-claimed
 * queued rows from `queued_at`, and approval rows from their persisted HITL
 * deadline. Expired rows move to a status-appropriate terminal outcome.
 *
 * Honours C-014: transitions go through
 * {@see AgentRunRepository::markTerminal()}, which is a compare-and-swap
 * that compares the status and lifecycle identity captured by candidate
 * selection. Worker claims, terminal completion, and renewed approval cycles
 * that win the selection-to-update race remain authoritative.
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
     * Scan abandoned rows and apply their status-specific terminal outcome.
     *
     * @param int $maxRuntimeSeconds Age threshold for running/cancelling rows,
     *     queued rows, and upgrade-era approvals without a deadline. Modern
     *     approvals are selected by their persisted HITL deadline instead.
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
        return $this->reapSelected($rows, $maxRuntimeSeconds, $now);
    }

    /**
     * Terminalize an immutable candidate batch selected by the repository.
     *
     * @param list<StalledRunCandidate> $rows
     * @internal Selection-to-CAS test seam; normal callers use {@see reap()}.
     */
    public function reapSelected(array $rows, int $maxRuntimeSeconds, \DateTimeImmutable $now): int
    {
        $threshold = $now->sub(new \DateInterval('PT' . $maxRuntimeSeconds . 'S'));
        $flipped = 0;

        foreach ($rows as $candidate) {
            $runId = $candidate->id;

            [$terminalStatus, $errorCode, $errorMessage] = $this->terminalOutcome(
                $candidate,
                $maxRuntimeSeconds,
                $threshold,
            );
            $advanced = $this->runRepository->markTerminal(
                $runId,
                $terminalStatus,
                $now,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
                expectedCandidate: $candidate,
            );

            if (!$advanced) {
                // Source state changed after selection. Leave the winning
                // worker or approval transition authoritative.
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
        StalledRunCandidate $candidate,
        int $maxRuntimeSeconds,
        \DateTimeImmutable $threshold,
    ): array {
        return match ($candidate->sourceStatus) {
            RunStatus::Queued => [
                RunStatus::Failed,
                'queue_timeout',
                \sprintf('No worker claimed the run within %d seconds.', $maxRuntimeSeconds),
            ],
            RunStatus::AwaitingApproval => [
                RunStatus::Failed,
                'approval_timeout',
                $candidate->approvalExpiresAt !== null
                    ? \sprintf('Approval deadline expired at %s.', $candidate->approvalExpiresAt)
                    : \sprintf(
                        'Approval deadline was unavailable; legacy started_at fallback exceeded %d seconds (started_at < %s).',
                        $maxRuntimeSeconds,
                        $threshold->format(\DateTimeInterface::ATOM),
                    ),
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
