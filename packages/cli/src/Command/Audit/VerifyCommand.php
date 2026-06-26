<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Audit;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Integrity\AuditChainVerifier;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * `bin/waaseyaa audit:verify`
 *
 * Verifies the audit-log hash chain and all sealed checkpoints. Exits 0 when
 * the chain is intact and 1 when tamper or corruption is detected.
 *
 * After completing the check the command records a self-audit event of kind
 * `audit.verify` so that verification runs themselves are auditable. The event
 * outcome is `allowed` for an intact chain and `denied` for a broken one.
 *
 * Options:
 *   --json   Print the result as a JSON object and suppress the human-readable
 *            summary line.
 *
 * @api
 */
final class VerifyCommand
{
    public function __construct(
        private readonly AuditChainVerifier $verifier,
        private readonly AuditWriterInterface $writer,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $result = $this->verifier->verify();

        // Self-audit: record the verification run regardless of outcome.
        $attributes = [
            'segments_verified'    => $result->segmentsVerified,
            'rows_verified'        => $result->rowsVerified,
            'pending_unsealed_rows' => $result->pendingUnsealedRows,
        ];

        if (!$result->ok) {
            $attributes['failure_kind']    = $result->failureKind;
            $attributes['first_broken_id'] = $result->firstBrokenId;
        }

        $this->writer->record(new AuditEventDescriptor(
            kind: AuditEventKind::AuditVerified,
            accountUid: 0,
            subjectUri: 'audit:verify',
            outcome: $result->ok ? 'allowed' : 'denied',
            severity: $result->ok ? 'info' : 'warning',
            attributes: $attributes,
        ));

        $wantsJson = (bool) $io->option('json');

        if ($wantsJson) {
            $io->writeln(json_encode([
                'ok'                    => $result->ok,
                'segments_verified'     => $result->segmentsVerified,
                'rows_verified'         => $result->rowsVerified,
                'pending_unsealed_rows' => $result->pendingUnsealedRows,
                'failure_kind'          => $result->failureKind,
                'first_broken_id'       => $result->firstBrokenId,
                'message'               => $result->message,
            ], JSON_THROW_ON_ERROR));

            return $result->ok ? 0 : 1;
        }

        if ($result->ok) {
            $io->writeln(sprintf(
                'audit:verify OK — %d segment(s), %d row(s) verified, %d pending.',
                $result->segmentsVerified,
                $result->rowsVerified,
                $result->pendingUnsealedRows,
            ));

            return 0;
        }

        $io->error(sprintf(
            'audit:verify TAMPER DETECTED — %s at id %d: %s',
            (string) $result->failureKind,
            (int) $result->firstBrokenId,
            $result->message,
        ));

        return 1;
    }
}
