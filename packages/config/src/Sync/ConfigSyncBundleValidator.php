<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;

/** Strict, complete, read-only validation of one authored sync directory. @api */
final class ConfigSyncBundleValidator
{
    public function __construct(
        private readonly ConfigSchemaRegistry $registry,
        private readonly ConfigSyncDeserializer $deserializer = new ConfigSyncDeserializer(),
        private readonly ConfigContentHasher $hasher = new ConfigContentHasher(),
    ) {}

    public function validate(string $syncPath): ConfigSyncBundleValidationResult
    {
        if (!$this->registry->isFrozen()) {
            throw new \LogicException('Sync bundle validation requires the frozen schema registry.');
        }
        if (!is_dir($syncPath) || is_link($syncPath)) {
            return new ConfigSyncBundleValidationResult([], [new ConfigBundleDiagnostic(
                path: $syncPath,
                phase: 'directory',
                field: '',
                message: 'Sync path must be an existing non-link directory.',
            )]);
        }

        $names = scandir($syncPath);
        if ($names === false) {
            return new ConfigSyncBundleValidationResult([], [new ConfigBundleDiagnostic(
                path: $syncPath,
                phase: 'directory',
                field: '',
                message: 'Sync directory cannot be enumerated.',
            )]);
        }
        $names = array_values(array_diff($names, ['.', '..']));
        sort($names, \SORT_STRING);

        $entries = [];
        $diagnostics = [];
        $entryKeys = [];
        $uuidKeys = [];
        foreach ($names as $name) {
            $path = rtrim($syncPath, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_link($path) || !is_file($path)) {
                $diagnostics[] = new ConfigBundleDiagnostic($name, 'directory', '', 'Bundle member must be a regular non-link file.');
                continue;
            }
            try {
                ConfigSyncFile::splitFilename($name);
            } catch (\Throwable $exception) {
                $diagnostics[] = new ConfigBundleDiagnostic($name, 'filename', '', $exception->getMessage());
                continue;
            }
            $bytes = file_get_contents($path);
            if (!\is_string($bytes)) {
                $diagnostics[] = new ConfigBundleDiagnostic($name, 'read', '', 'Bundle member is unreadable.');
                continue;
            }
            if ($bytes === '') {
                $diagnostics[] = new ConfigBundleDiagnostic($name, 'read', '', 'Bundle member is empty.');
                continue;
            }
            try {
                $file = $this->deserializer->fromYaml($bytes, $name);
            } catch (\Throwable $exception) {
                $diagnostics[] = new ConfigBundleDiagnostic($name, 'parse', '', $exception->getMessage());
                continue;
            }
            try {
                $hashes = $this->hasher->hash($file, $bytes, $this->registry);
            } catch (\Throwable $exception) {
                $diagnostics[] = new ConfigBundleDiagnostic($name, 'schema', '', $exception->getMessage());
                continue;
            }

            $entry = new ValidatedConfigSyncEntry($file, $bytes, $hashes);
            $key = $entry->key();
            if (isset($entryKeys[$key])) {
                $diagnostics[] = new ConfigBundleDiagnostic($name, 'bundle', 'identity', sprintf(
                    'Duplicate entry identity %s also appears in %s.',
                    $key,
                    $entryKeys[$key],
                ));
                continue;
            }
            if (isset($uuidKeys[$file->uuid]) && $uuidKeys[$file->uuid] !== $key) {
                $diagnostics[] = new ConfigBundleDiagnostic($name, 'bundle', 'uuid', sprintf(
                    'UUID %s is already bound to %s.',
                    $file->uuid,
                    $uuidKeys[$file->uuid],
                ));
                continue;
            }
            $entryKeys[$key] = $name;
            $uuidKeys[$file->uuid] = $key;
            $entries[] = $entry;
        }

        $availableRefs = [];
        foreach ($entries as $entry) {
            $availableRefs[$entry->file->ref()] = true;
        }
        foreach ($entries as $entry) {
            foreach ($entry->file->dependencies as $dependency) {
                if (!isset($availableRefs[$dependency])) {
                    $diagnostics[] = new ConfigBundleDiagnostic(
                        $entry->file->filename(),
                        'bundle',
                        'dependencies',
                        sprintf('Dependency %s is absent from the bundle.', $dependency),
                    );
                }
            }
        }

        usort($entries, static fn(ValidatedConfigSyncEntry $left, ValidatedConfigSyncEntry $right): int => strcmp($left->key(), $right->key()));
        usort($diagnostics, static function (ConfigBundleDiagnostic $left, ConfigBundleDiagnostic $right): int {
            return [$left->path, $left->phase, $left->field] <=> [$right->path, $right->phase, $right->field];
        });

        return new ConfigSyncBundleValidationResult($entries, $diagnostics);
    }
}
