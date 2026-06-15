<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Audit;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\CLI\CliIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * `bin/waaseyaa audit:prune --older-than=<duration> [--kind=<glob>] [--dry-run] [--confirm]`
 *
 * Deletes audit_event rows older than the given ISO-8601 duration
 * (e.g. `PT1H`, `P30D`, `P1Y`). Emits one self-audit event of kind
 * `audit.retention_pruned` after each real execution (FR-012).
 *
 * Destructive operation: real deletion requires `--confirm`. Without it (and
 * without `--dry-run`) the command echoes the resolved cutoff timestamp and the
 * exact row count it would delete, then refuses and exits 0 — nothing is
 * deleted and no self-audit event is recorded. This guards against typo-prone
 * `--older-than` durations silently destroying the append-only audit log.
 *
 * Options:
 *   --older-than  ISO-8601 duration string (required). Events created before
 *                 `now() - interval` are eligible for pruning.
 *   --kind        Glob pattern matched against AuditEventKind values:
 *                   `*`         → all kinds (default)
 *                   `entity.*`  → all entity.* cases
 *                   literal     → single exact kind
 *   --dry-run     Print the count that would be pruned; do not delete.
 *   --confirm     Required for real deletion. Without it, the command refuses
 *                 to delete and prints the cutoff + row count it would remove.
 *
 * @api
 */
final class PruneCommand
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AuditQueryInterface $query,
        private readonly AuditWriterInterface $writer,
        private readonly DatabaseInterface $db,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function execute(CliIO $io): int
    {
        $olderThanRaw = $io->option('older-than');
        if (!is_string($olderThanRaw) || $olderThanRaw === '') {
            $io->error('audit:prune: --older-than is required (e.g. P30D, PT1H, P1Y).');

            return 1;
        }

        try {
            $interval = new \DateInterval($olderThanRaw);
        } catch (\Exception $e) {
            $io->error(sprintf(
                'audit:prune: --older-than "%s" is not a valid ISO-8601 duration: %s',
                $olderThanRaw,
                $e->getMessage(),
            ));

            return 1;
        }

        $kindRaw = $io->option('kind');
        $kindPattern = (is_string($kindRaw) && $kindRaw !== '') ? $kindRaw : '*';

        $kinds = $this->resolveKinds($kindPattern);
        if ($kinds === []) {
            $io->error(sprintf(
                'audit:prune: --kind "%s" matched no known AuditEventKind values.',
                $kindPattern,
            ));

            return 1;
        }

        $cutoff = new \DateTimeImmutable('now')->sub($interval);

        $isDryRun = (bool) $io->option('dry-run');

        // Build query to count matching events.
        $auditQuery = new AuditQuery(
            kinds: $kinds,
            to: $cutoff,
        );

        $count = $this->query->count($auditQuery);

        if ($isDryRun) {
            $io->writeln(sprintf(
                'Would prune %d audit events (kind: %s, older than: %s, cutoff: %s). [dry-run]',
                $count,
                $kindPattern,
                $olderThanRaw,
                $cutoff->format(\DateTimeInterface::ATOM),
            ));

            return 0;
        }

        // Destructive-op guard (C-31): real deletion requires an explicit
        // --confirm. Without it, echo the resolved cutoff + the exact row count
        // that WOULD be deleted, then refuse — record no self-audit event and
        // delete no rows. This refusal is unconditional (interactive or not),
        // matching the import:reset / import:rollback idiom.
        $isConfirmed = (bool) $io->option('confirm');

        if (!$isConfirmed) {
            $io->writeln(sprintf(
                'Refusing to prune %d audit events without --confirm (kind: %s, older than: %s, cutoff: %s).',
                $count,
                $kindPattern,
                $olderThanRaw,
                $cutoff->format(\DateTimeInterface::ATOM),
            ));
            $io->writeln('Re-run with --confirm to delete, or --dry-run to preview.');

            return 0;
        }

        // Self-audit before deletion (FR-012): record the prune event first
        // so it is included in the pre-deletion count for traceability.
        $this->writer->record(new AuditEventDescriptor(
            kind: AuditEventKind::AuditRetentionPruned,
            accountUid: 0,
            subjectUri: 'audit:prune',
            outcome: 'allowed',
            severity: 'info',
            attributes: [
                'kind_pattern'  => $kindPattern,
                'older_than'    => $olderThanRaw,
                'deleted_count' => $count,
                'cutoff'        => $cutoff->format(\DateTimeInterface::ATOM),
            ],
        ));

        // Execute deletion.
        $delete = $this->db->delete('audit_event');
        $delete->condition('created_at', $cutoff->format('Y-m-d H:i:s'), '<');

        // Apply kind filter when not deleting all.
        if ($kindPattern !== '*') {
            $kindValues = array_map(static fn(AuditEventKind $k): string => $k->value, $kinds);
            $delete->condition('event_kind', $kindValues, 'IN');
        }

        $delete->execute();

        $this->logger->info('audit:prune completed', [
            'deleted_count' => $count,
            'kind_pattern'  => $kindPattern,
            'cutoff'        => $cutoff->format(\DateTimeInterface::ATOM),
        ]);

        $io->writeln(sprintf(
            'Pruned %d audit events (kind: %s, older than: %s).',
            $count,
            $kindPattern,
            $olderThanRaw,
        ));

        return 0;
    }

    /**
     * Resolve glob pattern to a list of matching AuditEventKind cases.
     *
     * - `*`          → all cases
     * - `entity.*`   → all cases whose value starts with `entity.`
     * - `literal`    → single exact match via AuditEventKind::tryFrom()
     *
     * @return AuditEventKind[]
     */
    private function resolveKinds(string $pattern): array
    {
        $all = AuditEventKind::cases();

        if ($pattern === '*') {
            return $all;
        }

        // Prefix glob: "entity.*" → values starting with "entity."
        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -2); // strip ".*"

            return array_values(array_filter(
                $all,
                static fn(AuditEventKind $k): bool => str_starts_with($k->value, $prefix . '.'),
            ));
        }

        // Exact match.
        $case = AuditEventKind::tryFrom($pattern);

        return $case !== null ? [$case] : [];
    }
}
