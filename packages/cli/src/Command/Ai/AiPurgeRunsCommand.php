<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Ai;

use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * `bin/waaseyaa ai:purge-runs [--retention-days=<int>]` — drop
 * {@see \Waaseyaa\AI\Agent\Entity\AgentRun} rows whose `queued_at` is
 * older than the retention threshold AND whose status is terminal
 * (`completed`, `failed`, `cancelled`), together with their owning
 * {@see \Waaseyaa\AI\Agent\Entity\AgentAuditLog} rows (FR-006, FR-030).
 *
 * A run still in `queued`, `running`, `awaiting_approval`, or `cancelling`
 * is never age-purged, even past retention: it is logically live, and the
 * `StalledRunReaper` (not this command) is responsible for detecting a
 * stalled `running` row (audit A7 F8).
 *
 * Algorithm:
 *  1. `threshold = now() - retentionDays`
 *  2. Find terminal run ids via {@see AgentRunRepository::findOldByQueuedAt()}.
 *  3. Delete those run rows from the `agent_run` table.
 *  4. Delete all audit rows whose `occurred_at < threshold` via
 *     {@see AgentAuditLogRepository::purgeOlderThan()}.
 *  5. Print: `Deleted X runs and Y audit rows.`
 *
 * **C-014 invariant:** purge is the only allowed mutation of
 * `AgentAuditLog` outside `append`. Audit deletion routes through
 * {@see AgentAuditLogRepository::purgeOlderThan()} — never direct SQL.
 *
 * Exit code: 0 on success.
 *
 * @api
 */
final class AiPurgeRunsCommand
{
    public function __construct(
        private readonly AgentRunRepository $runRepository,
        private readonly AgentAuditLogRepository $auditRepository,
        private readonly EntityRepositoryInterface $runEntityRepository,
        private readonly int $defaultRetentionDays = 30,
        /** @var \Closure(): \DateTimeImmutable */
        private readonly ?\Closure $now = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $retentionDays = $this->resolveRetentionDays($io);
        if ($retentionDays === null) {
            return 1;
        }

        $now = ($this->now ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now'))();
        $threshold = $now->sub(new \DateInterval('P' . $retentionDays . 'D'));

        $oldRuns = $this->runRepository->findOldByQueuedAt($threshold);
        $deletedRuns = 0;

        foreach ($oldRuns as $run) {
            $runId = (string) $run->get('id');
            if ($runId === '') {
                continue;
            }
            // Route through the entity repository so DomainEvents fire
            // and per-entity lifecycle hooks run (entity-storage invariant).
            $this->runEntityRepository->delete($run);
            $deletedRuns++;
        }

        $deletedAudits = $this->auditRepository->purgeOlderThan($threshold);

        $io->writeln(\sprintf(
            'Deleted %d runs and %d audit rows.',
            $deletedRuns,
            $deletedAudits,
        ));

        return 0;
    }

    private function resolveRetentionDays(SymfonyCommandIO $io): ?int
    {
        $option = $io->option('retention-days');
        if ($option === null || $option === '' || $option === false) {
            return $this->defaultRetentionDays;
        }
        if (\is_int($option)) {
            $value = $option;
        } elseif (\is_string($option) && ctype_digit($option)) {
            $value = (int) $option;
        } else {
            $io->error(\sprintf(
                'ai:purge-runs: --retention-days must be a positive integer; got "%s".',
                \is_scalar($option) ? (string) $option : gettype($option),
            ));
            return null;
        }
        if ($value <= 0) {
            $io->error(\sprintf(
                'ai:purge-runs: --retention-days must be > 0; got %d.',
                $value,
            ));
            return null;
        }
        return $value;
    }
}
