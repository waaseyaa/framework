<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Migration\StorageMigrationEmitter;
use Waaseyaa\CLI\Command\Migration\StorageMigrationTemplate;
use Waaseyaa\CLI\Command\Migration\UnmappedFieldTypeException;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\Backend\TypeMapping;

/**
 * Handler for the `make:storage-migration` command.
 *
 * Generates a PHP migration file that transitions the given entity type from
 * the sql-blob storage backend to the sql-column backend. The emitted file
 * follows the project's anonymous-class migration convention and includes a
 * BackfillHelper call in `up()` to migrate data from the `_data` JSON blob.
 *
 * Exit codes (per contracts/migration-generator-cli.md):
 *   0 — success (file written, or --dry-run printed content)
 *   1 — unknown entity type id
 *   2 — unsupported --target value
 *   3 — migration file already exists (use --force to overwrite)
 *   4 — field type without a §8.2 mapping
 *
 * @api
 */
final class MakeStorageMigrationHandler
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly StorageMigrationEmitter $emitter,
        private readonly StorageMigrationTemplate $template,
    ) {}

    /**
     * @api
     */
    public function execute(SymfonyCommandIO $io): int
    {
        $entityTypeId = (string) $io->argument('entity_type_id');
        $target       = (string) ($io->option('target') ?? 'sql-column');
        $dryRun       = (bool) $io->option('dry-run');
        $force        = (bool) $io->option('force');

        // Exit code 2 — unsupported target.
        if (!in_array($target, StorageMigrationEmitter::SUPPORTED_TARGETS, true)) {
            $io->error(sprintf(
                'Unsupported target backend: %s. Only sql-column is supported.',
                $target,
            ));
            return 2;
        }

        // Exit code 1 — unknown entity type.
        try {
            $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        } catch (\InvalidArgumentException) {
            $io->error(sprintf('Unknown entity type: %s', $entityTypeId));
            return 1;
        }

        // Exit code 1 — defense in depth. $entityTypeId feeds the generated
        // migration filename and doc-comments (StorageMigrationTemplate). A
        // registered entity type is expected to already be a machine name,
        // but the entity layer does not itself enforce that format, so this
        // handler must not trust it blindly before building a filesystem
        // path from it.
        // Unicode-aware machine-name allowlist; `u`+`D` flags keep it
        // injection-safe (no quote/backslash/newline/`.`/`/` can appear in
        // `\p{L}\p{N}_`, and `D` stops a trailing-`\n` bypass) while accepting
        // Indigenous-orthography entity-type ids.
        if (preg_match('/^[\p{L}_][\p{L}\p{N}_]*$/uD', $entityTypeId) !== 1) {
            $io->error(sprintf(
                'Entity type id "%s" is not a safe machine name (expected Unicode letters/digits/underscore, letter/underscore start).',
                $entityTypeId,
            ));
            return 1;
        }

        // Exit code 4 — unmapped field type.
        try {
            $columnMap = $this->emitter->emitColumnMap($entityType, TypeMapping::PLATFORM_SQLITE);
        } catch (UnmappedFieldTypeException $e) {
            $io->error(sprintf(
                'Field %s has type %s which has no sql-column mapping. '
                . 'Route it to an alternate backend via FieldDefinition::storedIn(%s).',
                $e->fieldId,
                $e->fieldType,
                $e->fieldType,
            ));
            return 4;
        }

        $entityTable = $this->resolveTableName($entityTypeId);
        $content     = $this->template->render($entityType, $columnMap, $entityTable);

        // --dry-run: print to stdout and exit 0.
        if ($dryRun) {
            $io->writeln($content);
            return 0;
        }

        $filename  = $this->template->filename($entityTypeId, $target);
        $targetDir = $this->projectRoot . '/migrations';

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o755, true);
        }

        $targetPath = $targetDir . '/' . $filename;

        // Exit code 3 — file already exists without --force.
        if (file_exists($targetPath) && !$force) {
            $io->error(sprintf('Migration %s exists. Use --force to overwrite.', $filename));
            return 3;
        }

        file_put_contents($targetPath, $content);

        $relativePath = 'migrations/' . $filename;
        $io->writeln(sprintf('Created: %s', $relativePath));

        return 0;
    }

    /**
     * Derive the SQL table name for an entity type id.
     *
     * Follows the convention used by SqlEntityStorage: the entity type id is
     * used directly as the table name (snake_case, no prefix by default).
     */
    private function resolveTableName(string $entityTypeId): string
    {
        return $entityTypeId;
    }
}
