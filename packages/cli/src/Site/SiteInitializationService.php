<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationLockedException;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\SiteManifestParser;

/** @api */
final class SiteInitializationService
{
    private const string METADATA = '.waaseyaa/generated.json';
    private const string JOURNAL = '.waaseyaa/site-init.transaction.json';
    private const string LOCK = '.waaseyaa/site-init.lock';

    private readonly string $projectRoot;

    private readonly SiteHostPlatform $platform;

    public function __construct(
        string $projectRoot,
        private readonly ?\Closure $faultInjector = null,
        ?SiteHostPlatform $platform = null,
    ) {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root) || is_link($projectRoot)) {
            throw new \InvalidArgumentException('The site project root must be an existing, non-symlink directory.');
        }
        $this->projectRoot = rtrim($root, DIRECTORY_SEPARATOR);
        $this->platform = $platform ?? SiteHostPlatform::host();
    }

    /** @param null|\Closure(list<string>): bool $authorize */
    public function initialize(GeneratedSite $site, bool $dryRun = false, ?\Closure $authorize = null): SiteInitializationResult
    {
        if ($dryRun) {
            if (is_file($this->absolute(self::JOURNAL))) {
                throw new \RuntimeException('Site initialization recovery or committed cleanup requires a non-dry run before a new plan can be computed.');
            }
            $prepared = $this->prepare($site);

            return new SiteInitializationResult(array_keys($prepared), true);
        }

        $controlDirectory = $this->absolute('.waaseyaa');
        if (!is_file($this->absolute(self::JOURNAL))) {
            // Refuse deterministic collisions before creating lock/control state.
            // The same checks run again under the lock before publication.
            $this->prepare($site);
        }
        if (!is_dir($controlDirectory) && !mkdir($controlDirectory, 0o700, true) && !is_dir($controlDirectory)) {
            throw new \RuntimeException('Unable to create the site initialization control directory.');
        }
        if (is_link($controlDirectory)) {
            throw new SiteInitializationCollisionException('The .waaseyaa control directory must not be a symbolic link.');
        }
        $controlIgnore = $site->artifacts['.waaseyaa/.gitignore'] ?? null;
        if (!$controlIgnore instanceof GeneratedArtifact) {
            throw new \InvalidArgumentException('Generated site control-ignore authority is required.');
        }
        $controlIgnorePath = $this->absolute($controlIgnore->path);
        if (!is_file($controlIgnorePath)) {
            $this->writeDurably($controlIgnorePath, $controlIgnore->content, $controlIgnore->mode);
        } elseif (!hash_equals(hash('sha256', $controlIgnore->content), $this->digestFile($controlIgnorePath))) {
            throw new SiteInitializationCollisionException('The site initialization control ignore file was substituted.');
        }

        $lockPath = $this->absolute(self::LOCK);
        if (file_exists($lockPath) || is_link($lockPath)) {
            $this->assertRegularOwnedFile($lockPath, self::LOCK);
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open the site initialization lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new SiteInitializationLockedException('Another site initialization transaction owns this project.');
        }

        $recovered = false;
        try {
            $recovered = $this->recoverIfRequired();
            $prepared = $this->prepare($site);
            if ($prepared === []) {
                return new SiteInitializationResult([], recoveredInterruptedTransaction: $recovered);
            }
            $changedPaths = array_keys($prepared);
            if ($authorize !== null && !$authorize($changedPaths)) {
                return new SiteInitializationResult($changedPaths, recoveredInterruptedTransaction: $recovered, cancelled: true);
            }
            $cleanupPending = $this->publish($prepared);

            return new SiteInitializationResult($changedPaths, recoveredInterruptedTransaction: $recovered, cleanupPending: $cleanupPending);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, GeneratedArtifact> */
    private function prepare(GeneratedSite $site): array
    {
        $metadataPath = $this->absolute(self::METADATA);
        $hasMetadata = is_file($metadataPath);
        $prior = $hasMetadata ? $this->readMetadata($metadataPath) : null;
        $priorRows = [];
        if ($prior !== null) {
            $priorManifestPath = $this->absolute('.waaseyaa/site.yaml');
            if (!is_file($priorManifestPath)) {
                throw new SiteInitializationCollisionException('Generated ownership metadata exists without its manifest authority.');
            }
            try {
                $priorManifest = new SiteManifestParser()->parse((string) file_get_contents($priorManifestPath), '.waaseyaa/site.yaml');
            } catch (\Throwable $exception) {
                throw new SiteInitializationCollisionException('The previously generated site authority is not reproducible.', previous: $exception);
            }
            if (!hash_equals($priorManifest->digest, $prior['manifest_digest']) || $priorManifest->generatorVersion !== $prior['generator_version']) {
                throw new SiteInitializationCollisionException('Generated ownership metadata does not bind the current manifest authority.');
            }
            if ($prior['generator_version'] !== $site->generatorVersion) {
                throw new SiteInitializationCollisionException(sprintf(
                    'Generated artifact migration from version %d to %d is required before regeneration.',
                    $prior['generator_version'],
                    $site->generatorVersion,
                ));
            }
            foreach ($prior['artifacts'] as $row) {
                if (isset($priorRows[$row['path']])) {
                    throw new SiteInitializationCollisionException("Generated ownership metadata repeats {$row['path']}.");
                }
                $priorRows[$row['path']] = $row;
            }
            $expectedOwnedPaths = array_values(array_filter(array_keys($site->artifacts), static fn(string $path): bool => $path !== self::METADATA));
            $recordedPaths = array_keys($priorRows);
            sort($expectedOwnedPaths, SORT_STRING);
            sort($recordedPaths, SORT_STRING);
            if ($expectedOwnedPaths !== $recordedPaths) {
                throw new SiteInitializationCollisionException('Generated ownership metadata does not match this generator version.');
            }
            if (hash_equals($prior['manifest_digest'], $site->manifestDigest)) {
                foreach ($site->artifacts as $path => $artifact) {
                    if ($path === self::METADATA) {
                        continue;
                    }
                    if (!hash_equals($priorRows[$path]['managed_sha256'], $artifact->managedDigest())) {
                        // #2644: this fires when the framework's renderer has
                        // changed but the manifest still binds the previous
                        // dependency lock — the manifest digest is unchanged, so
                        // regeneration cannot tell an upgrade from a
                        // substitution. Naming only a migration that does not
                        // exist as a command left the operator with no move.
                        // Rebinding the lock is the sanctioned one: it changes
                        // the manifest digest, which is precisely the signal
                        // that this is a reviewed upgrade.
                        throw new SiteInitializationCollisionException(sprintf(
                            'Generated artifact bytes changed without a generator-version migration: %s. '
                            . 'If this followed a framework upgrade, rebind framework.observed_lock_sha256 in '
                            . '.waaseyaa/site.yaml to the sha256 of the current composer.lock and re-run site:init.',
                            $path,
                        ));
                    }
                }
            }
        }

        $prepared = [];
        foreach ($site->artifacts as $path => $artifact) {
            $this->assertSafeTarget($path);
            $absolute = $this->absolute($path);
            if (file_exists($absolute) || is_link($absolute)) {
                $bootstrapControlIgnore = !$hasMetadata
                    && $path === '.waaseyaa/.gitignore'
                    && is_file($absolute)
                    && hash_equals(hash('sha256', $artifact->content), $this->digestFile($absolute));
                if (!$hasMetadata && !$bootstrapControlIgnore || $path === self::METADATA && !is_file($absolute)) {
                    throw new SiteInitializationCollisionException("Refusing to overwrite unowned artifact: {$path}");
                }
                $this->assertRegularOwnedFile($absolute, $path);
                $existing = (string) file_get_contents($absolute);
                if ($path !== self::METADATA && !$bootstrapControlIgnore) {
                    $row = $priorRows[$path] ?? null;
                    try {
                        $managedDigest = $artifact->managedDigest($existing);
                    } catch (\InvalidArgumentException $exception) {
                        throw new SiteInitializationCollisionException("Generated artifact has a damaged extension region: {$path}", previous: $exception);
                    }
                    if (!is_array($row) || !hash_equals($row['managed_sha256'], $managedDigest)) {
                        throw new SiteInitializationCollisionException("Generated artifact was edited outside an extension region: {$path}");
                    }
                    if (($row['extension_region'] ?? null) !== $artifact->extensionRegion) {
                        throw new SiteInitializationCollisionException("Generated extension ownership changed unexpectedly: {$path}");
                    }
                    try {
                        $artifact = $artifact->withExtensionFrom($existing);
                    } catch (\InvalidArgumentException $exception) {
                        throw new SiteInitializationCollisionException("Generated artifact has a damaged extension region: {$path}", previous: $exception);
                    }
                }
                if (hash_equals(hash('sha256', $existing), hash('sha256', $artifact->content)) && $this->modeMatches($absolute, $artifact->mode)) {
                    continue;
                }
            }
            $prepared[$path] = $artifact;
        }
        ksort($prepared, SORT_STRING);

        return $prepared;
    }

    /** @param array<string, GeneratedArtifact> $artifacts */
    private function publish(array $artifacts): bool
    {
        $transactionId = bin2hex(random_bytes(12));
        $stageRelative = '.waaseyaa/site-init-stage-' . $transactionId;
        $backupRelative = '.waaseyaa/site-init-backup-' . $transactionId;
        $stage = $this->absolute($stageRelative);
        $backup = $this->absolute($backupRelative);
        $this->makePrivateDirectory($stage);
        $this->makePrivateDirectory($backup);

        $publishOrder = array_keys($artifacts);
        $publishOrder = array_values(array_filter($publishOrder, static fn(string $path): bool => $path !== self::METADATA));
        if (isset($artifacts[self::METADATA])) {
            $publishOrder[] = self::METADATA;
        }
        $items = [];
        foreach ($publishOrder as $index => $path) {
            $artifact = $artifacts[$path];
            $stageFile = $stage . '/' . sprintf('%04d.artifact', $index);
            $this->writeDurably($stageFile, $artifact->content, $artifact->mode);
            $this->injectFault('after-stage', $index, $path);
            $target = $this->absolute($path);
            $existed = is_file($target);
            $backupFile = null;
            $backupMode = null;
            if ($existed) {
                $backupFile = $backup . '/' . sprintf('%04d.backup', $index);
                // A host without permission bits has no observed mode to preserve, so the
                // journal records the declared one and rollback stays reproducible.
                $backupMode = $this->platform->enforcesPermissionBits() ? fileperms($target) & 0o777 : $artifact->mode;
                $this->copyDurably($target, $backupFile, $backupMode);
                $this->injectFault('after-backup', $index, $path);
            }
            $items[] = [
                'path' => $path,
                'stage' => $this->relative($stageFile),
                'installed_sha256' => $this->digestFile($stageFile),
                'backup' => $backupFile === null ? null : $this->relative($backupFile),
                'backup_sha256' => $backupFile === null ? null : $this->digestFile($backupFile),
                'backup_mode' => $backupMode,
                'existed' => $existed,
                'mode' => $artifact->mode,
                'state' => 'pending',
            ];
        }
        $journal = [
            'schema' => 'waaseyaa.site-init-transaction',
            'version' => 1,
            'id' => $transactionId,
            'state' => 'prepared',
            'stage' => $stageRelative,
            'backup' => $backupRelative,
            'created_directories' => $this->missingTargetDirectories(array_keys($artifacts)),
            'items' => $items,
        ];
        $this->writeJournal($journal);

        try {
            foreach ($journal['items'] as $index => &$item) {
                $item['state'] = 'installing';
                $this->writeJournal($journal);
                $this->injectFault('before-replace', $index, $item['path']);
                $target = $this->absolute($item['path']);
                $this->ensureTargetDirectory(dirname($target));
                if (!rename($this->absolute($item['stage']), $target)) {
                    throw new \RuntimeException("Unable to atomically install {$item['path']}.");
                }
                if ($this->platform->enforcesPermissionBits() && !chmod($target, $item['mode'])) {
                    throw new \RuntimeException("Unable to set mode on {$item['path']}.");
                }
                $this->syncFile($target);
                $this->syncDirectory(dirname($target));
                $this->injectFault('after-replace', $index, $item['path']);
                $item['state'] = 'applied';
                $this->writeJournal($journal);
            }
            unset($item);
            $journal['state'] = 'committed';
            $this->writeJournal($journal);
        } catch (\Exception $exception) {
            unset($item);
            $this->rollback($journal);
            throw $exception;
        }
        try {
            $this->injectFault('after-commit', -1, '');
            $this->cleanupTransaction($journal);
        } catch (\Exception) {
            return true;
        }

        return false;
    }

    private function recoverIfRequired(): bool
    {
        $path = $this->absolute(self::JOURNAL);
        if (!is_file($path)) {
            return $this->cleanupOrphanControlResidue();
        }
        $this->assertRegularOwnedFile($path, self::JOURNAL);
        try {
            $journal = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The interrupted site initialization journal is invalid JSON.', previous: $exception);
        }
        if (!is_array($journal)) {
            throw new \RuntimeException('The interrupted site initialization journal is invalid.');
        }
        $this->validateJournal($journal);
        if ($journal['state'] === 'committed') {
            $this->cleanupTransaction($journal);
        } else {
            $this->rollback($journal);
        }

        $this->cleanupOrphanControlResidue();

        return true;
    }

    /** @param array<string, mixed> $journal */
    private function rollback(array $journal): void
    {
        foreach (array_reverse($journal['items'], true) as $index => $item) {
            if (!in_array($item['state'], ['installing', 'applied'], true)) {
                continue;
            }
            $target = $this->absolute($item['path']);
            if ($item['existed'] === true) {
                $backup = $this->absolute($item['backup']);
                if (!is_file($backup) || is_link($backup)) {
                    throw new \RuntimeException("Cannot recover {$item['path']}: its backup is missing.");
                }
                $this->assertRegularOwnedFile($backup, $item['backup']);
                if (!hash_equals($item['backup_sha256'], $this->digestFile($backup))) {
                    throw new \RuntimeException("Cannot recover {$item['path']}: its backup was substituted.");
                }
                if (!is_file($target) || is_link($target)) {
                    throw new SiteInitializationCollisionException("Cannot recover a changed generated target: {$item['path']}");
                }
                $this->assertRegularOwnedFile($target, $item['path']);
                $currentDigest = $this->digestFile($target);
                if (hash_equals($item['backup_sha256'], $currentDigest)) {
                    if (!$this->modeMatches($target, $item['backup_mode']) && !chmod($target, $item['backup_mode'])) {
                        throw new \RuntimeException("Cannot restore the mode of {$item['path']}.");
                    }
                    $this->syncFile($target);
                    continue;
                }
                if (!hash_equals($item['installed_sha256'], $currentDigest)) {
                    throw new SiteInitializationCollisionException("Cannot recover a substituted generated target: {$item['path']}");
                }
                $temp = dirname($backup) . '/restore-' . sprintf('%04d', $index) . '-' . bin2hex(random_bytes(6));
                $this->copyDurably($backup, $temp, $item['backup_mode']);
                $this->injectFault('after-rollback-copy', (int) $index, $item['path']);
                if (!rename($temp, $target)) {
                    @unlink($temp);
                    throw new \RuntimeException("Cannot restore {$item['path']}.");
                }
                $this->syncDirectory(dirname($target));
            } elseif (file_exists($target) || is_link($target)) {
                $this->assertRegularOwnedFile($target, $item['path']);
                if (!hash_equals($item['installed_sha256'], $this->digestFile($target))) {
                    throw new SiteInitializationCollisionException("Cannot recover a substituted generated target: {$item['path']}");
                }
                if (!unlink($target)) {
                    throw new \RuntimeException("Cannot remove interrupted artifact {$item['path']}.");
                }
                $this->syncDirectory(dirname($target));
            }
        }
        foreach (array_reverse($journal['created_directories']) as $relative) {
            $directory = $this->absolute($relative);
            if (is_dir($directory) && $this->directoryIsEmpty($directory)) {
                if (!rmdir($directory)) {
                    throw new \RuntimeException("Cannot remove interrupted target directory {$relative}.");
                }
                $this->syncDirectory(dirname($directory));
            }
        }
        $this->cleanupTransaction($journal);
    }

    /** @param array<string, mixed> $journal */
    private function cleanupTransaction(array $journal): void
    {
        $this->removeControlTree($this->absolute($journal['stage']));
        $this->removeControlTree($this->absolute($journal['backup']));
        $journalPath = $this->absolute(self::JOURNAL);
        if (is_file($journalPath)) {
            if (!unlink($journalPath)) {
                throw new \RuntimeException('Unable to remove the completed site initialization journal.');
            }
            $this->syncDirectory(dirname($journalPath));
        }
    }

    /** @return array<string, mixed> */
    private function readMetadata(string $path): array
    {
        $raw = (string) file_get_contents($path);
        try {
            $metadata = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SiteInitializationCollisionException('Generated ownership metadata is invalid.', previous: $exception);
        }
        if (!is_array($metadata)) {
            throw new SiteInitializationCollisionException('Generated ownership metadata has an unsupported shape.');
        }
        $metadataKeys = array_keys($metadata);
        sort($metadataKeys, SORT_STRING);
        if ($metadataKeys !== ['artifacts', 'generator_version', 'manifest_digest', 'schema', 'version']
            || ($metadata['schema'] ?? null) !== 'waaseyaa.generated'
            || ($metadata['version'] ?? null) !== 1
            || !is_int($metadata['generator_version'] ?? null)
            || $metadata['generator_version'] < 1
            || preg_match('/^[a-f0-9]{64}$/D', $metadata['manifest_digest'] ?? '') !== 1
            || !is_array($metadata['artifacts'] ?? null)
            || !hash_equals(CanonicalJson::encode($metadata) . "\n", $raw)) {
            throw new SiteInitializationCollisionException('Generated ownership metadata has an unsupported shape.');
        }
        $paths = [];
        foreach ($metadata['artifacts'] as $row) {
            if (!is_array($row) || !is_string($row['path'] ?? null) || isset($paths[$row['path']]) || preg_match('/^[a-f0-9]{64}$/D', $row['managed_sha256'] ?? '') !== 1) {
                throw new SiteInitializationCollisionException('Generated ownership metadata contains an invalid artifact record.');
            }
            $allowed = isset($row['extension_region'])
                ? ['extension_region', 'managed_sha256', 'mode', 'path']
                : ['managed_sha256', 'mode', 'path'];
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== $allowed || preg_match('/^0(?:644|755)$/D', $row['mode'] ?? '') !== 1) {
                throw new SiteInitializationCollisionException('Generated ownership metadata contains an unsupported artifact record.');
            }
            $paths[$row['path']] = true;
        }
        $sortedPaths = array_keys($paths);
        $recordedPaths = $sortedPaths;
        sort($sortedPaths, SORT_STRING);
        if ($recordedPaths !== $sortedPaths) {
            throw new SiteInitializationCollisionException('Generated ownership metadata artifact records are not canonical.');
        }

        return $metadata;
    }

    /** @param array<string, mixed> $journal */
    private function writeJournal(array $journal): void
    {
        $this->writeAtomically($this->absolute(self::JOURNAL), json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0o600);
    }

    private function writeAtomically(string $target, string $content, int $mode): void
    {
        $temp = $target . '.tmp-' . bin2hex(random_bytes(6));
        $this->writeDurably($temp, $content, $mode);
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException("Unable to publish control file {$target}.");
        }
        $this->syncDirectory(dirname($target));
    }

    private function writeDurably(string $path, string $content, int $mode): void
    {
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException("Unable to create {$path} exclusively.");
        }
        try {
            $written = fwrite($handle, $content);
            if ($written !== strlen($content) || !fflush($handle)) {
                throw new \RuntimeException("Unable to durably write {$path}.");
            }
            if ($this->platform->enforcesPermissionBits() && !chmod($path, $mode)) {
                throw new \RuntimeException("Unable to set mode on {$path}.");
            }
            if (!fsync($handle)) {
                throw new \RuntimeException("Unable to durably write {$path}.");
            }
        } finally {
            fclose($handle);
        }
        $this->syncDirectory(dirname($path));
    }

    private function copyDurably(string $source, string $target, int $mode): void
    {
        $content = file_get_contents($source);
        if ($content === false) {
            throw new \RuntimeException("Unable to read {$source} for recovery.");
        }
        $this->writeDurably($target, $content, $mode);
    }

    private function digestFile(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if ($digest === false) {
            throw new \RuntimeException("Unable to digest {$path}.");
        }

        return $digest;
    }

    private function syncFile(string $path): void
    {
        if (!$this->platform->synchronizesDirectories()) {
            return;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false || !fsync($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException("Unable to sync {$path}.");
        }
        fclose($handle);
    }

    private function syncDirectory(string $directory): void
    {
        if (!$this->platform->synchronizesDirectories()) {
            return;
        }
        $handle = fopen($directory, 'rb');
        if ($handle === false || !fsync($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException("Unable to sync directory {$directory}.");
        }
        fclose($handle);
    }

    private function makePrivateDirectory(string $directory): void
    {
        if (!mkdir($directory, 0o700) || is_link($directory)) {
            throw new \RuntimeException("Unable to create transaction directory {$directory}.");
        }
        $this->syncDirectory(dirname($directory));
    }

    private function ensureTargetDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create target directory {$directory}.");
        }
        $this->assertPathWithinRoot($directory);
    }

    /** @param list<string> $paths @return list<string> */
    private function missingTargetDirectories(array $paths): array
    {
        $directories = [];
        foreach ($paths as $path) {
            $relative = dirname($path);
            while ($relative !== '.' && $relative !== '.waaseyaa') {
                if (!is_dir($this->absolute($relative))) {
                    $directories[$relative] = substr_count($relative, '/');
                }
                $relative = dirname($relative);
            }
        }
        uasort($directories, static fn(int $left, int $right): int => $left <=> $right);

        return array_keys($directories);
    }

    private function assertSafeTarget(string $relative): void
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains("/{$relative}/", '/../') || str_contains($relative, "\0")) {
            throw new SiteInitializationCollisionException("Unsafe generated target: {$relative}");
        }
        $cursor = $this->projectRoot;
        foreach (explode('/', dirname($relative)) as $segment) {
            if ($segment === '.') {
                continue;
            }
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                throw new SiteInitializationCollisionException("Generated target traverses a symbolic link: {$relative}");
            }
        }
    }

    private function assertRegularOwnedFile(string $path, string $relative): void
    {
        $stat = lstat($path);
        // The hard-link count is a POSIX guarantee; on Windows it is not a
        // portable signal, so enforcing it there would refuse ordinary files.
        // The symlink and regular-file clauses stay unconditional (#2644).
        $aliased = $this->platform->enforcesHardLinkCounts() && ($stat === false || $stat['nlink'] !== 1);
        if ($stat === false || !is_file($path) || is_link($path) || $aliased) {
            throw new SiteInitializationCollisionException("Generated target is not a private regular file: {$relative}");
        }
    }

    /**
     * Whether an existing artifact already carries its declared mode.
     *
     * On a host without POSIX permission bits there is nothing to compare, so
     * the mode half of the unchanged-artifact test is vacuously satisfied.
     * Comparing anyway meant no artifact ever matched and `site:init` rewrote
     * the entire generated set on every run (#2644).
     */
    private function modeMatches(string $absolute, int $mode): bool
    {
        if (!$this->platform->enforcesPermissionBits()) {
            return true;
        }

        return (fileperms($absolute) & 0o777) === $mode;
    }

    private function assertPathWithinRoot(string $path): void
    {
        $resolved = realpath($path);
        // #2644: realpath() returns backslash-separated paths on Windows, so a
        // separator-naive prefix test rejected every legitimate target there.
        if ($resolved === false || !SitePathContainment::contains($this->projectRoot, $resolved)) {
            throw new SiteInitializationCollisionException('Generated target escaped the project root.');
        }
    }

    private function absolute(string $relative): string
    {
        return $this->projectRoot . '/' . $relative;
    }

    private function relative(string $absolute): string
    {
        return substr($absolute, strlen($this->projectRoot) + 1);
    }

    /** @param array<string, mixed> $journal */
    private function validateJournal(array $journal): void
    {
        $keys = array_keys($journal);
        sort($keys, SORT_STRING);
        if ($keys !== ['backup', 'created_directories', 'id', 'items', 'schema', 'stage', 'state', 'version']
            || ($journal['schema'] ?? null) !== 'waaseyaa.site-init-transaction'
            || ($journal['version'] ?? null) !== 1
            || preg_match('/^[a-f0-9]{24}$/D', $journal['id'] ?? '') !== 1
            || ($journal['stage'] ?? null) !== '.waaseyaa/site-init-stage-' . $journal['id']
            || ($journal['backup'] ?? null) !== '.waaseyaa/site-init-backup-' . $journal['id']
            || !in_array($journal['state'] ?? null, ['prepared', 'committed'], true)
            || !is_array($journal['items'] ?? null)
            || !is_array($journal['created_directories'] ?? null)) {
            throw new \RuntimeException('The interrupted site initialization journal is invalid.');
        }
        $paths = [];
        foreach ($journal['items'] as $index => $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('The interrupted site initialization journal contains an invalid item.');
            }
            $itemKeys = array_keys($item);
            sort($itemKeys, SORT_STRING);
            if ($itemKeys !== ['backup', 'backup_mode', 'backup_sha256', 'existed', 'installed_sha256', 'mode', 'path', 'stage', 'state']
                || !is_string($item['path'] ?? null)
                || isset($paths[$item['path']])
                || !is_bool($item['existed'] ?? null)
                || !in_array($item['mode'] ?? null, [0o644, 0o755], true)
                || !in_array($item['state'] ?? null, ['pending', 'installing', 'applied'], true)
                || preg_match('/^[a-f0-9]{64}$/D', $item['installed_sha256'] ?? '') !== 1
                || ($item['stage'] ?? null) !== $journal['stage'] . '/' . sprintf('%04d.artifact', $index)
                || ($item['existed'] === true && (($item['backup'] ?? null) !== $journal['backup'] . '/' . sprintf('%04d.backup', $index) || preg_match('/^[a-f0-9]{64}$/D', $item['backup_sha256'] ?? '') !== 1 || !is_int($item['backup_mode'] ?? null) || $item['backup_mode'] < 0 || $item['backup_mode'] > 0o777))
                || ($item['existed'] === false && (($item['backup'] ?? null) !== null || ($item['backup_sha256'] ?? null) !== null || ($item['backup_mode'] ?? null) !== null))) {
                throw new \RuntimeException('The interrupted site initialization journal contains an invalid item.');
            }
            $this->assertSafeTarget($item['path']);
            $paths[$item['path']] = true;
        }
        $directories = [];
        foreach ($journal['created_directories'] as $directory) {
            if (!is_string($directory) || $directory === '.' || $directory === '.waaseyaa' || isset($directories[$directory])) {
                throw new \RuntimeException('The interrupted site initialization journal contains an invalid target directory.');
            }
            $this->assertSafeTarget($directory . '/placeholder');
            $ownedAncestor = false;
            foreach (array_keys($paths) as $path) {
                if (str_starts_with($path, $directory . '/')) {
                    $ownedAncestor = true;
                    break;
                }
            }
            if (!$ownedAncestor) {
                throw new \RuntimeException('The interrupted site initialization journal contains an unowned target directory.');
            }
            $directories[$directory] = true;
        }
    }

    private function cleanupOrphanControlResidue(): bool
    {
        $directory = $this->absolute('.waaseyaa');
        $entries = scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException('Unable to inspect site initialization control state.');
        }
        $cleaned = false;
        foreach ($entries as $entry) {
            $path = $directory . '/' . $entry;
            if (preg_match('/^site-init-(?:stage|backup)-[a-f0-9]{24}$/D', $entry) === 1) {
                if (is_link($path) || !is_dir($path)) {
                    throw new SiteInitializationCollisionException("Unsafe site initialization residue: .waaseyaa/{$entry}");
                }
                $this->removeControlTree($path);
                $cleaned = true;
            } elseif (preg_match('/^site-init\.transaction\.json\.tmp-[a-f0-9]{12}$/D', $entry) === 1) {
                $this->assertRegularOwnedFile($path, '.waaseyaa/' . $entry);
                if (!unlink($path)) {
                    throw new \RuntimeException("Unable to remove site initialization residue: .waaseyaa/{$entry}");
                }
                $this->syncDirectory($directory);
                $cleaned = true;
            }
        }

        return $cleaned;
    }

    private function removeControlTree(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('Refusing to clean a linked transaction root.');
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException('Refusing to clean a linked transaction artifact.');
            }
            $removed = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$removed) {
                throw new \RuntimeException('Unable to clean a transaction artifact.');
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('Unable to clean a transaction directory.');
        }
        $this->syncDirectory(dirname($path));
    }

    private function directoryIsEmpty(string $directory): bool
    {
        $items = scandir($directory);

        return $items === ['.', '..'];
    }

    private function injectFault(string $stage, int $index, string $path): void
    {
        if ($this->faultInjector !== null) {
            ($this->faultInjector)($stage, $index, $path);
        }
    }
}
