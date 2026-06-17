<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Config;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Sync\ConfigSyncValidator;
use Waaseyaa\Config\Sync\ConfigValidateEntry;

/**
 * `bin/waaseyaa config:validate` — validate every sync-store file against
 * entity-type field constraints, surfacing per-entity / per-field
 * violations (FR-037..FR-040, contracts/cli-namespace.md §config:validate).
 *
 * Output shape per the contract:
 *
 * ```
 * role.admin: OK
 * taxonomy_vocabulary.community_categories:
 *   - field 'description': must be at least 1 character
 *   - field 'weight': must be non-negative
 * ```
 *
 * Exit codes (FR-040, CI gate semantics):
 *  - `0` — every entity valid.
 *  - `1` — any entity invalid.
 *
 * **Spec §10.1 status:** `FieldDefinition::validators()` (ADR-013) is not
 * yet shipped on `waaseyaa/field`. {@see ConfigSyncValidator} therefore
 * accepts a duck-typed field-validation hook that the application wires
 * once that pipeline lands; until then, the default fallback runs a
 * structural required-fields check. CI gate semantics are unchanged.
 *
 * @spec FR-037 — validate against entity-type field definitions
 * @spec FR-038 — block `config:import` (precondition wiring lives in the
 *                importer; this command is the standalone CI gate)
 * @spec FR-039 — per-entity / per-field output detail
 * @spec FR-040 — independent CI-runnable gate, exit 0/1
 * @api
 */
final class ConfigValidateCommand
{
    public function __construct(
        private readonly ConfigSyncValidator $validator,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $result = $this->validator->validate();

        if ($result->entries === []) {
            $io->writeln('No sync-store files found; nothing to validate.');

            return 0;
        }

        foreach ($result->entries as $entry) {
            $this->renderEntry($io, $entry);
        }

        return $result->isValid() ? 0 : 1;
    }

    private function renderEntry(SymfonyCommandIO $io, ConfigValidateEntry $entry): void
    {
        if ($entry->isValid()) {
            $io->writeln(sprintf('%s: OK', $entry->ref));

            return;
        }

        $io->writeln(sprintf('%s:', $entry->ref));
        foreach ($entry->violations as $violation) {
            $line = sprintf(
                "  - field '%s': %s",
                $violation->field,
                $violation->message,
            );
            // Failures land on STDERR per contracts/cli-namespace.md so
            // operators can pipe stdout to logs while CI still sees the
            // detail line.
            $io->error($line);
        }
    }
}
