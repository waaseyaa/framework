<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;

/**
 * @api
 */
final class MakeMigrationHandler extends AbstractMakeHandler
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?PackageManifest $manifest = null,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
        private readonly ?AddTranslationsMigrationGenerator $translationsGenerator = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $addTranslations = $io->option('add-translations');
        if ($addTranslations !== null && $addTranslations !== '' && $addTranslations !== false) {
            return $this->executeAddTranslations((string) $addTranslations, $io);
        }

        $name = (string) $io->argument('name');
        try {
            // $name becomes the migration filename below — validating it here
            // (before any path is built) closes off "../evil" traversal.
            $this->validateIdentifier($name, 'name');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $createTable = $io->option('create');
        $modifyTable = $io->option('table');
        $package = $io->option('package');

        $table = (string) ($createTable ?? $modifyTable ?? $this->guessTableName($name));
        try {
            // $table is interpolated raw into a single-quoted
            // `$schema->create('...', ...)` / `dropIfExists('...')` literal in
            // the generated migration stub — a quote here breaks out into
            // arbitrary PHP that runs on autoload.
            $this->validateMachineName($table, 'table name');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $rendered = $this->renderStub('migration', [
            'table' => $table,
        ]);

        $timestamp = date('Ymd_His');
        $filename = "{$timestamp}_{$name}.php";

        $targetDir = $this->resolveMigrationDirectory(
            $package !== null ? (string) $package : null,
            $io,
        );
        if ($targetDir === null) {
            return 1;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o755, true);
        }

        $targetPath = $targetDir . '/' . $filename;
        file_put_contents($targetPath, $rendered);

        $relativePath = str_starts_with($targetDir, $this->projectRoot)
            ? substr($targetDir, strlen($this->projectRoot) + 1) . '/' . $filename
            : $targetPath;
        $io->writeln("Created: {$relativePath}");

        return 0;
    }

    /**
     * Generate an "add translations" migration for an existing entity type.
     *
     * FR-050..FR-053. Six failure modes (see contracts/migration-generator.md):
     *  1. --default-langcode missing.
     *  2. EntityTypeManager unavailable (handler constructed standalone).
     *  3. Entity type not registered.
     *  4. Entity type is a config entity (no uuid key in entityKeys).
     *  5. No fields marked translatable.
     *  6. (Runtime) primary table missing langcode column -> MissingLangcodeColumnException
     *     surfaces on apply, not generation. Generation-time we cannot inspect schema
     *     without a live connection; the exception class is shipped so consumers and
     *     future hooks can wrap the apply step.
     */
    private function executeAddTranslations(string $entityTypeId, SymfonyCommandIO $io): int
    {
        $defaultLangcode = $io->option('default-langcode');
        if ($defaultLangcode === null || $defaultLangcode === '' || $defaultLangcode === false) {
            $io->error('The --default-langcode option is required when using --add-translations.');
            return 1;
        }
        $defaultLangcode = (string) $defaultLangcode;

        if ($this->entityTypeManager === null) {
            $io->error('EntityTypeManager is not available. Run inside a booted Waaseyaa application.');
            return 1;
        }

        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            $io->error(sprintf('Entity type "%s" is not registered. Boot the application and try again.', $entityTypeId));
            return 1;
        }

        try {
            // Defense in depth: $entityTypeId feeds the generated migration
            // filename below and is interpolated into doc-comments in the
            // generated migration source (AddTranslationsMigrationGenerator).
            // A registered entity type is expected to already be a machine
            // name, but this handler must not trust that invariant blindly.
            $this->validateMachineName($entityTypeId, 'entity type id');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return 1;
        }

        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);

        // A config entity has no "uuid" key (#entity-types-without-uuid-are-config-entities).
        $keys = $entityType->getKeys();
        if (!isset($keys['uuid'])) {
            $io->error(sprintf('Entity type "%s" appears to be a config entity (no uuid key). --add-translations is only valid for content entities.', $entityTypeId));
            return 1;
        }

        $generator = $this->translationsGenerator ?? new AddTranslationsMigrationGenerator();
        $translatableColumns = $generator->translatableColumns($entityType);

        // sql-blob lives entirely in _data; sql-column splits translatable fields into
        // schema columns. The brief allows --backend to be inferred from the entity type
        // primary backend; when unset, sql-blob is the framework default.
        $backend = $entityType->getPrimaryStorageBackend() ?? 'sql-blob';
        if ($backend !== 'sql-blob' && $backend !== 'sql-column') {
            $io->error(sprintf('Unsupported primary storage backend "%s" for --add-translations. Expected sql-blob or sql-column.', $backend));
            return 1;
        }

        if ($backend === 'sql-column' && $translatableColumns === []) {
            $io->error('No fields are marked translatable. Mark at least one field with FieldDefinition::translatable() before regenerating.');
            return 1;
        }

        $rendered = $generator->render($entityType, $defaultLangcode, $backend, $translatableColumns);

        $timestamp = date('Ymd_His');
        $name = "add_translations_to_{$entityTypeId}";
        $filename = "{$timestamp}_{$name}.php";

        $targetDir = $this->projectRoot . '/migrations';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o755, true);
        }
        $targetPath = $targetDir . '/' . $filename;
        file_put_contents($targetPath, $rendered);

        $relativePath = str_starts_with($targetDir, $this->projectRoot)
            ? substr($targetDir, strlen($this->projectRoot) + 1) . '/' . $filename
            : $targetPath;
        $io->writeln("Created: {$relativePath}");
        $io->writeln(sprintf('Backend: %s; default langcode: %s', $backend, $defaultLangcode));
        if ($backend === 'sql-column') {
            $io->writeln(sprintf('Translatable columns: %s', implode(', ', $translatableColumns)));
        }
        $io->writeln('NOTE: The reverse migration drops non-default-langcode rows. Back up multilingual content before running `migrate:rollback`.');

        return 0;
    }

    private function guessTableName(string $name): string
    {
        $name = strtolower($name);
        // Strip common prefixes/suffixes.
        $name = preg_replace('/^(create|add|modify|update|alter)_/', '', $name) ?? $name;
        $name = preg_replace('/_(table|column|index)$/', '', $name) ?? $name;

        return $name;
    }

    private function resolveMigrationDirectory(?string $package, SymfonyCommandIO $io): ?string
    {
        if ($package === null) {
            return $this->projectRoot . '/migrations';
        }

        if ($this->manifest === null) {
            $io->error('PackageManifest not available. Cannot resolve package migration directory.');
            return null;
        }

        $packageMigrations = $this->manifest->migrations;
        if (!isset($packageMigrations[$package])) {
            $io->error("Package '{$package}' has no registered migration directory.");
            return null;
        }

        return $packageMigrations[$package];
    }
}
