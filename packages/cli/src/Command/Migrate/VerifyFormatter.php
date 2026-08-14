<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

/**
 * Renders {@see VerifyOutcome} in either a human-readable text form or
 * structured JSON.
 *
 * **JSON shape (locked):**
 *
 * ```json
 * {
 *   "kind": "verify",
 *   "results": [
 *     {
 *       "migration": "waaseyaa/groups:v2:add-archived-flag",
 *       "status": "match",
 *       "stored_checksum": "...",
 *       "computed_checksum": "..."
 *     }
 *   ],
 *   "summary": {
 *     "match": 1, "mismatch": 0, "unknown": 0, "orphan": 0
 *   }
 * }
 * ```
 *
 * `stored_checksum` and `computed_checksum` are JSON nulls when absent.
 * The CLI exit code is non-zero when any mismatch, unknown, orphan, or
 * authority mismatch is present; consumers parsing JSON use the same rule.
 */
final readonly class VerifyFormatter
{
    public function __construct(private OutputSanitizer $sanitizer) {}

    public function toText(VerifyOutcome $outcome): string
    {
        $lines = ['Verify report (ledger, source, plan, and live-schema comparison):', ''];

        if ($outcome->rows === []) {
            $lines[] = '  (ledger empty)';
        }

        foreach ($outcome->rows as $row) {
            $stored = $row->storedChecksum ?? '—';
            $computed = $row->computedChecksum ?? '—';
            $lines[] = sprintf(
                '  [%s] %s  stored=%s  computed=%s',
                $row->status,
                $this->sanitizer->sanitize($row->migration),
                $this->shorten($stored),
                $this->shorten($computed),
            );
        }

        $lines[] = sprintf(
            '  [authority:%s] schema=%s/%s ledger=%s/%s catalogue=%s/%s',
            $outcome->authority->status,
            $this->shorten($outcome->authority->storedSchemaFingerprint ?? '—'),
            $this->shorten($outcome->authority->computedSchemaFingerprint),
            $this->shorten($outcome->authority->storedLedgerFingerprint ?? '—'),
            $this->shorten($outcome->authority->computedLedgerFingerprint),
            $this->shorten($outcome->authority->storedSourceCatalogFingerprint ?? '—'),
            $this->shorten($outcome->authority->computedSourceCatalogFingerprint),
        );

        $lines[] = '';
        $lines[] = sprintf(
            'Summary: match=%d mismatch=%d unknown=%d orphan=%d authority_mismatch=%d',
            $outcome->summary->match,
            $outcome->summary->mismatch,
            $outcome->summary->unknown,
            $outcome->summary->orphan,
            $outcome->summary->authorityMismatch,
        );

        if ($outcome->summary->hasFailure()) {
            $lines[] = 'STATUS: FAIL — drift or orphans detected.';
        } else {
            $lines[] = 'STATUS: OK';
        }

        return implode("\n", $lines) . "\n";
    }

    public function toJson(VerifyOutcome $outcome): string
    {
        $results = [];
        foreach ($outcome->rows as $row) {
            $results[] = [
                'migration' => $this->sanitizer->sanitize($row->migration),
                'status' => $row->status,
                'stored_checksum' => $row->storedChecksum,
                'computed_checksum' => $row->computedChecksum,
            ];
        }

        $payload = [
            'kind' => 'verify',
            'results' => $results,
            'authority' => [
                'status' => $outcome->authority->status,
                'stored_schema_fingerprint' => $outcome->authority->storedSchemaFingerprint,
                'computed_schema_fingerprint' => $outcome->authority->computedSchemaFingerprint,
                'stored_ledger_fingerprint' => $outcome->authority->storedLedgerFingerprint,
                'computed_ledger_fingerprint' => $outcome->authority->computedLedgerFingerprint,
                'stored_source_catalog_fingerprint' => $outcome->authority->storedSourceCatalogFingerprint,
                'computed_source_catalog_fingerprint' => $outcome->authority->computedSourceCatalogFingerprint,
            ],
            'summary' => [
                'match' => $outcome->summary->match,
                'mismatch' => $outcome->summary->mismatch,
                'unknown' => $outcome->summary->unknown,
                'orphan' => $outcome->summary->orphan,
                'authority_mismatch' => $outcome->summary->authorityMismatch,
            ],
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function shorten(string $hash): string
    {
        if (strlen($hash) <= 12) {
            return $hash;
        }
        return substr($hash, 0, 12) . '…';
    }
}
