<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

use Waaseyaa\Foundation\Migration\LedgerRow;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationCatalogFingerprint;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Compiler\Validation\PlanPolicy;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;

/**
 * Read-only strict verification of ledger rows, exact source/package/compiled
 * plan identity, and the coordinator-recorded live schema authority manifest.
 *
 * **Status mapping:**
 *
 * | Stored checksum | Source present | Source kind | Computed matches | Status     |
 * |-----------------|----------------|-------------|------------------|------------|
 * | non-null        | yes            | v2          | yes              | `match`    |
 * | non-null        | yes            | v2          | no               | `mismatch` |
 * | non-null        | yes            | legacy      | n/a              | `unknown`  |
 * | non-null        | no             | n/a         | n/a              | `orphan`   |
 * | null            | yes or no      | n/a         | n/a              | `unknown` (failure) |
 *
 * New legacy rows bind the exact executable class body and a domain-separated
 * procedural-plan identity. Only historical null rows remain unknown.
 */
final readonly class VerifyRunner
{
    public function __construct(
        private MigrationRepository $repository,
        private SqliteCompiler $compiler,
    ) {}

    /**
     * @param array<string, array<string, Migration>> $legacy
     * @param list<MigrationInterfaceV2>              $v2
     */
    public function verify(array $legacy, array $v2): VerifyOutcome
    {
        $sources = $this->indexSources($legacy, $v2);
        $catalogFingerprint = MigrationCatalogFingerprint::capture($legacy, $v2);

        $rows = [];
        $counts = ['match' => 0, 'mismatch' => 0, 'unknown' => 0, 'orphan' => 0];

        foreach ($this->repository->allWithChecksums() as $ledgerRow) {
            $row = $this->classify($ledgerRow, $sources);
            $rows[] = $row;
            if ($row->status === 'match') {
                $counts['match']++;
            } elseif ($row->status === 'unknown') {
                $counts['unknown']++;
            } elseif ($row->status === 'orphan') {
                $counts['orphan']++;
            } else {
                $counts['mismatch']++;
            }
        }

        $authority = $this->verifyAuthority($catalogFingerprint);

        return new VerifyOutcome(
            rows: $rows,
            summary: new VerifySummary(
                match: $counts['match'],
                mismatch: $counts['mismatch'],
                unknown: $counts['unknown'],
                orphan: $counts['orphan'],
                authorityMismatch: $authority->isMatch() ? 0 : 1,
            ),
            authority: $authority,
        );
    }

    /**
     * @param array<string, array<string, Migration>> $legacy
     * @param list<MigrationInterfaceV2>              $v2
     * @return array<string, array{kind: 'legacy'|'v2', package: string, source: Migration|MigrationInterfaceV2}>
     */
    private function indexSources(array $legacy, array $v2): array
    {
        $sources = [];

        foreach ($legacy as $package => $packageMigrations) {
            foreach ($packageMigrations as $name => $migration) {
                $sources[$name] = ['kind' => 'legacy', 'package' => $package, 'source' => $migration];
            }
        }
        foreach ($v2 as $v) {
            $sources[$v->migrationId()] = ['kind' => 'v2', 'package' => $v->package(), 'source' => $v];
        }

        return $sources;
    }

    /**
     * @param array<string, array{kind: 'legacy'|'v2', package: string, source: Migration|MigrationInterfaceV2}> $sources
     */
    private function classify(LedgerRow $ledger, array $sources): VerifyResultRow
    {
        $source = $sources[$ledger->migration] ?? null;

        if ($source === null) {
            return new VerifyResultRow($ledger->migration, 'orphan', $ledger->checksum, null);
        }

        if ($ledger->package !== $source['package']) {
            return new VerifyResultRow($ledger->migration, 'package_mismatch', $ledger->checksum, null);
        }

        if ($ledger->checksum === null || $ledger->diffHash === null) {
            return new VerifyResultRow($ledger->migration, 'unknown', $ledger->checksum, null);
        }

        if ($source['kind'] === 'legacy') {
            $legacy = $source['source'];
            \assert($legacy instanceof Migration);
            $computed = MigrationCatalogFingerprint::legacySourceChecksum($legacy);
            if ($ledger->checksum !== $computed) {
                return new VerifyResultRow($ledger->migration, 'mismatch', $ledger->checksum, $computed);
            }
            if ($ledger->diffHash !== MigrationCatalogFingerprint::legacyPlanHash($computed)) {
                return new VerifyResultRow($ledger->migration, 'plan_mismatch', $ledger->diffHash, MigrationCatalogFingerprint::legacyPlanHash($computed));
            }

            return new VerifyResultRow($ledger->migration, 'match', $ledger->checksum, $computed);
        }

        $migration = $source['source'];
        \assert($migration instanceof MigrationInterfaceV2);
        $computed = $migration->plan()->checksum();
        if ($ledger->checksum !== $computed) {
            return new VerifyResultRow($ledger->migration, 'mismatch', $ledger->checksum, $computed);
        }
        $compiled = $this->compiler->compile(
            $migration->plan()->root,
            new PlanPolicy(allowDestructive: true),
        )->diffHash();
        if ($ledger->diffHash !== $compiled) {
            return new VerifyResultRow($ledger->migration, 'plan_mismatch', $ledger->diffHash, $compiled);
        }

        return new VerifyResultRow(
            $ledger->migration,
            'match',
            $ledger->checksum,
            $computed,
        );
    }

    private function verifyAuthority(string $catalogFingerprint): VerifyAuthorityResult
    {
        $manifest = $this->repository->schemaAuthorityManifest();
        $schema = $this->repository->currentLogicalSchemaFingerprint();
        $ledger = $this->repository->currentLedgerFingerprint();
        $status = match (true) {
            $manifest === null,
            $manifest->schemaFingerprint === null,
            $manifest->ledgerFingerprint === null,
            $manifest->sourceCatalogFingerprint === null => 'authority_missing',
            $manifest->schemaFingerprint !== $schema => 'schema_drift',
            $manifest->ledgerFingerprint !== $ledger => 'ledger_drift',
            $manifest->sourceCatalogFingerprint !== $catalogFingerprint => 'source_catalog_mismatch',
            default => 'match',
        };

        return new VerifyAuthorityResult(
            status: $status,
            storedSchemaFingerprint: $manifest?->schemaFingerprint,
            computedSchemaFingerprint: $schema,
            storedLedgerFingerprint: $manifest?->ledgerFingerprint,
            computedLedgerFingerprint: $ledger,
            storedSourceCatalogFingerprint: $manifest?->sourceCatalogFingerprint,
            computedSourceCatalogFingerprint: $catalogFingerprint,
        );
    }
}
