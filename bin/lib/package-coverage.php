<?php

declare(strict_types=1);

/**
 * FW-PACKAGE-CONVERGENCE-01 (#3118): shared reading of the package coverage
 * index and its captured evidence files.
 *
 * Evidence under docs/audits/packages/evidence/ is captured byte-for-byte with
 * a provenance header:
 *
 *   source: commit <40-hex> <path> | issue <owner>/<repo>#<n>
 *   captured: YYYY-MM-DD
 *   body-sha256: <64-hex>
 *   ---
 *   <body bytes>
 *
 * The shallow-safe Architecture test checks header shape and the body digest.
 * bin/check-package-coverage-history checks the commit-sourced copies and each
 * row's dependency identity against full history, failing closed.
 *
 * Plain functions, no autoloader.
 */

const PACKAGE_COVERAGE_INDEX = 'docs/audits/packages/coverage-index.json';
const PACKAGE_COVERAGE_EVIDENCE = 'docs/audits/packages/evidence/';

/**
 * @return array<string, mixed>
 */
function packageCoverageIndex(string $root): array
{
    $bytes = file_get_contents($root . '/' . PACKAGE_COVERAGE_INDEX);
    if ($bytes === false) {
        throw new RuntimeException('package-coverage: cannot read ' . PACKAGE_COVERAGE_INDEX);
    }

    return json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
}

/**
 * Parse a captured evidence file.
 *
 * @return array{source_kind: string, commit: ?string, path: ?string, issue: ?string, captured: string, sha256: string, body: string}
 */
function packageCoverageEvidence(string $bytes): array
{
    if (preg_match('/\Asource: (commit ([0-9a-f]{40}) ([A-Za-z0-9_][A-Za-z0-9._\/-]*)|issue ([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+#\d+))\ncaptured: (\d{4}-\d{2}-\d{2})\nbody-sha256: ([0-9a-f]{64})\n---\n/', $bytes, $match) !== 1) {
        throw new RuntimeException('package-coverage: evidence file does not start with a valid provenance header');
    }
    $body = substr($bytes, strlen($match[0]));
    if (hash('sha256', $body) !== $match[6]) {
        throw new RuntimeException('package-coverage: evidence body does not match its body-sha256');
    }

    return [
        'source_kind' => $match[2] !== '' ? 'commit' : 'issue',
        'commit' => $match[2] !== '' ? $match[2] : null,
        'path' => $match[3] !== '' ? $match[3] : null,
        'issue' => ($match[4] ?? '') !== '' ? $match[4] : null,
        'captured' => $match[5],
        'sha256' => $match[6],
        'body' => $body,
    ];
}
