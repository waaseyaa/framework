<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Exports sealed checkpoints as newline-delimited JSON (NDJSON) to a local file.
 *
 * WARNING — LOCAL FILE SINK ONLY AS TRUSTWORTHY AS THE HOST:
 * A host compromise that can edit `audit_event` can also edit this file.
 * Real tamper-evidence REQUIRES shipping checkpoints to an off-box / WORM /
 * external append-only sink. This default exists so the chain is exported
 * somewhere out of the box; production operators MUST configure an external
 * sink. Replace this binding in your ServiceProvider with a sink that writes
 * to an off-box, immutable-log service (e.g. CloudWatch Logs, S3 Object Lock,
 * an external SIEM, or any append-only blob store).
 *
 * @api
 */
final class FileCheckpointSink implements CheckpointSink
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $filePath,
        private readonly bool $echoStdout = true,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function export(AuditCheckpoint $checkpoint): void
    {
        // Convert PHP E_WARNING emissions from filesystem functions into
        // catchable exceptions so the outer \Throwable handler can log them
        // without crashing the calling request.
        set_error_handler(static function (int $errno, string $errstr): never {
            throw new \RuntimeException($errstr, $errno);
        });

        try {
            $line = json_encode([
                'uuid'                 => $checkpoint->getUuid(),
                'segment_start_id'     => $checkpoint->getSegmentStartId(),
                'segment_end_id'       => $checkpoint->getSegmentEndId(),
                'row_count'            => $checkpoint->getRowCount(),
                'segment_hash'         => $checkpoint->getSegmentHash(),
                'prev_checkpoint_hash' => $checkpoint->getPrevCheckpointHash(),
                'checkpoint_hash'      => $checkpoint->getCheckpointHash(),
                'signature'            => $checkpoint->getSignature(),
                'hash_version'         => $checkpoint->getHashVersion(),
                'is_genesis'           => $checkpoint->isGenesis(),
                'created_at'           => $checkpoint->getCreatedAt(),
            ], JSON_THROW_ON_ERROR) . "\n";

            $dir = dirname($this->filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }

            file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);

            if ($this->echoStdout) {
                fwrite(STDOUT, $line);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('audit.checkpoint_sink_failed', [
                'sink'  => self::class,
                'error' => $e->getMessage(),
                'uuid'  => $checkpoint->getUuid(),
            ]);
        } finally {
            restore_error_handler();
        }
    }
}
