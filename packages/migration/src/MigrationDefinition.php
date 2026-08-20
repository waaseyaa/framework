<?php

declare(strict_types=1);

namespace Waaseyaa\Migration;

use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Security\MigrationFieldReadManifest;

/**
 * Canonical manifest value object — the single PHP object every migration
 * author writes to declare a migration (FR-011, FR-012, FR-016).
 *
 * Migrations are discovered via the {@see \Waaseyaa\Migration\Discovery\HasMigrationsInterface}
 * provider capability or by scanning filesystem paths declared in
 * `config/waaseyaa.php` `migration.manifest_paths` (FR-013). Either way, the
 * end result is a stream of `MigrationDefinition` instances handed to
 * {@see \Waaseyaa\Migration\Discovery\MigrationRegistry::boot()}.
 *
 * The constructor performs *per-instance* validation only — collision and
 * dependency-graph validation are *registry-level* concerns and live in
 * `MigrationRegistry::boot()` instead.
 *
 * Note: bundle metadata (e.g. node bundle name) is declared once on this
 * definition (see {@see self::$bundle}) and threaded by
 * {@see \Waaseyaa\Migration\Runner\MigrationRunner} into every
 * {@see \Waaseyaa\Migration\Plugin\DestinationRecord} it builds for the
 * migration — NOT through the process map. The process map keys are *field*
 * names only.
 *
 * @api
 */
final readonly class MigrationDefinition
{
    /** @var list<string> */
    public array $acknowledgedSaveAdvisoryCodes;

    /** Pattern every migration id must match (snake_case, starts with a letter). */
    public const string ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** Default per-migration memory budget — 256 MiB (Q4 resolution). */
    public const int DEFAULT_MEMORY_BUDGET_BYTES = 268_435_456;

    /** Default fraction of records that may fail before a warning is logged (Q5 resolution). */
    public const float DEFAULT_ERROR_RATE_WARN = 0.01;

    /** Default fraction of records that may fail before the runner halts (Q5 resolution). */
    public const float DEFAULT_ERROR_RATE_HALT = 0.10;

    /**
     * @param string $id Globally-unique migration id (snake_case). Matches {@see self::ID_PATTERN}.
     * @param SourcePluginInterface $source Producer end of the pipeline.
     * @param array<string, ProcessPluginInterface|string|array<ProcessPluginInterface|string>> $process Destination-field-keyed process map (FR-016). Each value is either a {@see ProcessPluginInterface} instance, a non-empty source field name string (resolved to PassThrough at runtime in WP03), or a list-shaped chain of those.
     * @param DestinationPluginInterface $destination Consumer end of the pipeline.
     * @param list<string> $dependencies Ids of migrations that must run first. May not contain self-references or duplicates.
     * @param ?string $description Human-readable description for operator UIs.
     * @param int $memoryBudgetBytes Soft memory budget per migration (Q4 resolution). Runner emits a warning if peak usage exceeds budget by 20%.
     * @param float $errorRateWarn Fraction of records that may fail before a warning is logged (range `[0.0, 1.0]`).
     * @param float $errorRateHalt Fraction of records that may fail before the runner halts (range `[0.0, 1.0]`). Must be `>=` {@see $errorRateWarn}.
     * @param ?string $bundle Optional destination bundle id (e.g. `article`) for this migration. Threaded verbatim by {@see \Waaseyaa\Migration\Runner\MigrationRunner} into `DestinationRecord::$bundle` for every record; `null` opts out (destination-resolved or bundle-less destinations).
     * @param array<int|string, mixed> $acknowledgedSaveAdvisoryCodes Explicit operator-approved advisory codes for canonical entity writes.
     *
     * @throws \InvalidArgumentException On any per-instance validation failure (empty id, malformed id, empty process map, malformed process values, self-referential or duplicate dependency, out-of-range error rates, negative memory budget, empty-string bundle).
     */
    public function __construct(
        public string $id,
        public SourcePluginInterface $source,
        public array $process,
        public DestinationPluginInterface $destination,
        public array $dependencies = [],
        public ?string $description = null,
        public int $memoryBudgetBytes = self::DEFAULT_MEMORY_BUDGET_BYTES,
        public float $errorRateWarn = self::DEFAULT_ERROR_RATE_WARN,
        public float $errorRateHalt = self::DEFAULT_ERROR_RATE_HALT,
        public ?string $bundle = null,
        public ?MigrationFieldReadManifest $fieldReads = null,
        array $acknowledgedSaveAdvisoryCodes = [],
    ) {
        $this->validateId($id);
        $this->validateProcessMap($process);
        $this->validateDependencies($dependencies, $id);
        $this->validateMemoryBudget($memoryBudgetBytes);
        $this->validateErrorRates($errorRateWarn, $errorRateHalt);
        $this->validateBundle($bundle);
        $this->acknowledgedSaveAdvisoryCodes = $this->validateAcknowledgedSaveAdvisoryCodes($acknowledgedSaveAdvisoryCodes);
        if ($fieldReads !== null && $fieldReads->migrationId !== $id) {
            throw new \InvalidArgumentException('Migration field-read manifest id must match the migration definition id.');
        }
    }

    /** @param array<int|string, mixed> $codes @return list<string> */
    private function validateAcknowledgedSaveAdvisoryCodes(array $codes): array
    {
        if (!array_is_list($codes) || count($codes) > 32) {
            throw new \InvalidArgumentException(
                'MigrationDefinition::$acknowledgedSaveAdvisoryCodes must be a list of at most 32 codes.',
            );
        }
        $seen = [];
        foreach ($codes as $code) {
            if (!is_string($code)) {
                throw new \InvalidArgumentException(
                    'MigrationDefinition::$acknowledgedSaveAdvisoryCodes must contain only strings.',
                );
            }
            try {
                SaveAdvisory::assertCode($code);
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException(
                    'MigrationDefinition::$acknowledgedSaveAdvisoryCodes contains an invalid code.',
                    previous: $exception,
                );
            }
            if (isset($seen[$code])) {
                throw new \InvalidArgumentException(
                    'MigrationDefinition::$acknowledgedSaveAdvisoryCodes must not contain duplicates.',
                );
            }
            $seen[$code] = true;
        }

        return $codes;
    }

    /**
     * Normalize the heterogeneous process-map entry for a destination field
     * into a flat list of `ProcessPluginInterface | string` steps.
     *
     * - A bare {@see ProcessPluginInterface} normalizes to `[$plugin]`.
     * - A bare `string` source-field name normalizes to `[$string]` — the
     *   runner (WP03/WP06) resolves it to `PassThroughProcessor` against the
     *   named source field.
     * - An existing chain (list) is returned verbatim.
     *
     * @return list<ProcessPluginInterface|string>
     *
     * @throws \OutOfBoundsException When `$destinationField` is not declared in the process map.
     */
    public function processForField(string $destinationField): array
    {
        if (!\array_key_exists($destinationField, $this->process)) {
            throw new \OutOfBoundsException(\sprintf(
                'Migration %s has no process entry for destination field %s.',
                \var_export($this->id, true),
                \var_export($destinationField, true),
            ));
        }

        $entry = $this->process[$destinationField];

        if (\is_array($entry)) {
            return \array_values($entry);
        }

        return [$entry];
    }

    private function validateId(string $id): void
    {
        if ($id === '') {
            throw new \InvalidArgumentException('MigrationDefinition::$id must be a non-empty string.');
        }
        if (\preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'MigrationDefinition::$id %s must match %s (snake_case, starts with a lower-case letter).',
                \var_export($id, true),
                self::ID_PATTERN,
            ));
        }
    }

    /**
     * @param array<string, ProcessPluginInterface|string|array<ProcessPluginInterface|string>> $process
     */
    private function validateProcessMap(array $process): void
    {
        if ($process === []) {
            throw new \InvalidArgumentException(
                'MigrationDefinition::$process must declare at least one destination field.',
            );
        }
        foreach ($process as $destinationField => $entry) {
            if ($destinationField === '') {
                throw new \InvalidArgumentException(
                    'MigrationDefinition::$process keys must be non-empty destination field names.',
                );
            }
            $this->validateProcessEntry($destinationField, $entry);
        }
    }

    private function validateProcessEntry(string $destinationField, mixed $entry): void
    {
        if ($entry instanceof ProcessPluginInterface) {
            return;
        }
        if (\is_string($entry)) {
            if ($entry === '') {
                throw new \InvalidArgumentException(\sprintf(
                    'MigrationDefinition::$process[%s] is a string but is empty; expected a source field name.',
                    \var_export($destinationField, true),
                ));
            }
            return;
        }
        if (\is_array($entry)) {
            if ($entry === []) {
                throw new \InvalidArgumentException(\sprintf(
                    'MigrationDefinition::$process[%s] is an empty chain; provide at least one ProcessPluginInterface or source field name.',
                    \var_export($destinationField, true),
                ));
            }
            foreach ($entry as $index => $step) {
                if ($step instanceof ProcessPluginInterface) {
                    continue;
                }
                if (\is_string($step) && $step !== '') {
                    continue;
                }
                throw new \InvalidArgumentException(\sprintf(
                    'MigrationDefinition::$process[%s] chain entry at index %s must be a ProcessPluginInterface or a non-empty source field name.',
                    \var_export($destinationField, true),
                    \var_export($index, true),
                ));
            }
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'MigrationDefinition::$process[%s] must be a ProcessPluginInterface, a non-empty source field name, or a chain (list) of those; got %s.',
            \var_export($destinationField, true),
            \get_debug_type($entry),
        ));
    }

    /**
     * @param list<string> $dependencies
     */
    private function validateDependencies(array $dependencies, string $id): void
    {
        $seen = [];
        foreach ($dependencies as $index => $dependency) {
            if ($dependency === '') {
                throw new \InvalidArgumentException(\sprintf(
                    'MigrationDefinition::$dependencies[%s] must be a non-empty string id.',
                    \var_export($index, true),
                ));
            }
            if ($dependency === $id) {
                throw new \InvalidArgumentException(\sprintf(
                    'MigrationDefinition::$dependencies may not contain a self-reference; %s lists itself.',
                    \var_export($id, true),
                ));
            }
            if (isset($seen[$dependency])) {
                throw new \InvalidArgumentException(\sprintf(
                    'MigrationDefinition::$dependencies for %s contains duplicate id %s.',
                    \var_export($id, true),
                    \var_export($dependency, true),
                ));
            }
            $seen[$dependency] = true;
        }
    }

    private function validateMemoryBudget(int $memoryBudgetBytes): void
    {
        if ($memoryBudgetBytes < 0) {
            throw new \InvalidArgumentException(\sprintf(
                'MigrationDefinition::$memoryBudgetBytes must be >= 0; got %d.',
                $memoryBudgetBytes,
            ));
        }
    }

    private function validateErrorRates(float $errorRateWarn, float $errorRateHalt): void
    {
        if ($errorRateWarn < 0.0 || $errorRateWarn > 1.0) {
            throw new \InvalidArgumentException(\sprintf(
                'MigrationDefinition::$errorRateWarn must lie in [0.0, 1.0]; got %s.',
                \var_export($errorRateWarn, true),
            ));
        }
        if ($errorRateHalt < 0.0 || $errorRateHalt > 1.0) {
            throw new \InvalidArgumentException(\sprintf(
                'MigrationDefinition::$errorRateHalt must lie in [0.0, 1.0]; got %s.',
                \var_export($errorRateHalt, true),
            ));
        }
        if ($errorRateWarn > $errorRateHalt) {
            throw new \InvalidArgumentException(\sprintf(
                'MigrationDefinition::$errorRateWarn (%s) must be <= $errorRateHalt (%s).',
                \var_export($errorRateWarn, true),
                \var_export($errorRateHalt, true),
            ));
        }
    }

    private function validateBundle(?string $bundle): void
    {
        if ($bundle === '') {
            throw new \InvalidArgumentException('MigrationDefinition::$bundle must be null or a non-empty string.');
        }
    }
}
