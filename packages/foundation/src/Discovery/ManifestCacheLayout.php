<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

/** Fixed manifest layout selection. Host artifact/custody admission remains separate. @internal */
final readonly class ManifestCacheLayout
{
    public const string LAYOUT_PATH = '/.waaseyaa/manifest-cache-layout';
    public const string BINDING_PATH = '/.waaseyaa/manifest-runtime-binding.json';

    private function __construct(
        public string $context,
        public string $physicalReleaseRoot,
        public string $runtimeRoot,
        public string $manifestPath,
        public ?string $bindingSha256 = null,
        public ?string $artifactSha256 = null,
    ) {}

    public static function resolve(string $basePath, string $storagePath, ManifestCompilationContext $context = ManifestCompilationContext::ArtifactBound): self
    {
        self::assertControlLookupAccess($basePath);
        $markerPath = $basePath . self::LAYOUT_PATH;
        $selected = file_exists($markerPath) || is_link($markerPath);
        $bindingPath = $basePath . self::BINDING_PATH;
        if (!$selected) {
            if (file_exists($bindingPath) || is_link($bindingPath)) {
                throw new \RuntimeException('Unexpected manifest runtime binding on a legacy project.');
            }
            if ($context !== ManifestCompilationContext::ArtifactBound) {
                throw new \RuntimeException('Isolated manifest validation requires the artifact-bound layout marker.');
            }
            $physicalBase = realpath($basePath);
            $physicalRuntime = realpath($basePath . '/storage');
            return new self('legacy', $physicalBase !== false ? $physicalBase : $basePath, $physicalRuntime !== false ? $physicalRuntime : $storagePath, $storagePath . '/framework/packages.php');
        }
        if (!ManifestCompilationContext::artifactBindingSupported()) {
            throw new \RuntimeException('Artifact-bound manifest layouts require a POSIX filesystem host.');
        }
        $base = self::canonicalDirectory($basePath);
        self::canonicalDirectory($base . '/.waaseyaa');
        if (self::readControlFile($markerPath, $base . '/.waaseyaa', 18, 18) !== "artifact-bound-v1\n") {
            throw new \RuntimeException('Unsupported canonical manifest-cache layout marker.');
        }
        $runtime = realpath($base . '/storage');
        if ($runtime === false || !is_dir($runtime)) {
            throw new \RuntimeException('Artifact-bound manifests require an existing canonical runtime.');
        }
        $bytes = self::readControlFile($bindingPath, $base . '/.waaseyaa', 512);
        $binding = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($binding) || array_keys($binding) !== ['schema', 'artifact_sha256']
            || $binding['schema'] !== 'waaseyaa.manifest-runtime-binding.v1'
            || !is_string($binding['artifact_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $binding['artifact_sha256']) !== 1
            || $bytes !== json_encode($binding, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        ) {
            throw new \RuntimeException('Invalid canonical manifest runtime binding.');
        }
        $storage = realpath($storagePath);
        if ($storage === false || !is_dir($storage)) {
            throw new \RuntimeException('Manifest storage must already exist.');
        }
        if ($context === ManifestCompilationContext::ArtifactBound) {
            if ($storage !== $runtime || !in_array($storagePath, [$base . '/storage', $runtime], true)) {
                throw new \RuntimeException('Artifact-bound storagePath differs from the bound runtime.');
            }
        } else {
            self::canonicalDirectory($storagePath);
            foreach ([$runtime, $base] as $protectedRoot) {
                if (self::overlaps($storage, $protectedRoot)) {
                    throw new \RuntimeException('Isolated manifest storage overlaps runtime or release.');
                }
            }
        }
        $directory = $storage;
        foreach (['framework', 'manifests', $binding['artifact_sha256']] as $segment) {
            $directory .= '/' . $segment;
            self::canonicalDirectory($directory);
            $modeBits = fileperms($directory);
            if ($modeBits === false || ($modeBits & 0o022) !== 0 || fileowner($directory) !== fileowner($storage)) {
                throw new \RuntimeException('Manifest directory has unsafe write permissions.');
            }
        }
        $path = $directory . '/packages.php';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('Manifest cache must be a regular non-symlink file.');
        }
        $entries = scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException('Cannot inventory the prepared manifest directory.');
        }
        foreach ($entries as $name) {
            if ($name !== '.' && $name !== '..' && $name !== 'packages.php' && preg_match('/^packages\.php\.tmp\.[0-9]+$/D', $name) !== 1) {
                throw new \RuntimeException('Unexpected file in the prepared manifest directory.');
            }
        }
        $layout = new self($context->value, $base, $runtime, $path, hash('sha256', $bytes), $binding['artifact_sha256']);
        $layout->assertSelectedPath();
        return $layout;
    }

    /** Revalidate mutable directory custody before cache filesystem operations. */
    public function assertSelectedPath(): void
    {
        if ($this->context !== 'legacy') {
            $directory = dirname($this->manifestPath);
            self::canonicalDirectory($directory);
            $mode = fileperms($directory);
            if ($mode === false || ($mode & 0o022) !== 0) {
                throw new \RuntimeException('Selected manifest directory changed write custody.');
            }
            if (is_link($this->manifestPath) || (file_exists($this->manifestPath) && !is_file($this->manifestPath))) {
                throw new \RuntimeException('Selected manifest cache changed custody.');
            }
            if (is_file($this->manifestPath)) {
                $mode = fileperms($this->manifestPath);
                if ($mode === false || ($mode & 0o022) !== 0 || fileowner($this->manifestPath) !== fileowner($directory) || (stat($this->manifestPath)['nlink'] ?? 0) !== 1) {
                    throw new \RuntimeException('Selected manifest file changed write custody.');
                }
            }

        }
    }

    /** Establish fixed-name lookup access before treating missing controls as legacy. */
    private static function assertControlLookupAccess(string $basePath): void
    {
        $lookup = $basePath;
        while (($physical = realpath($lookup)) === false) {
            $parent = dirname($lookup);
            if ($parent === $lookup) {
                throw new \RuntimeException('Cannot establish manifest control-parent access.');
            }
            $lookup = $parent;
        }
        if (!is_dir($physical)
            || (PHP_OS_FAMILY !== 'Windows' && !is_executable($physical))
            || (PHP_OS_FAMILY === 'Windows' && !is_readable($physical))
        ) {
            throw new \RuntimeException('Cannot establish manifest control-parent access.');
        }
        if ($lookup !== $basePath) {
            // A legacy not-yet-created project retains its old behavior only
            // when the nearest existing ancestor permits actual name lookup.
            return;
        }
        $controls = $physical . '/.waaseyaa';
        clearstatcache(true, $controls);
        if (!file_exists($controls) && !is_link($controls)) {
            return;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            self::canonicalDirectory($controls);
        }
        if (!is_dir($controls) || is_link($controls) || !is_readable($controls)
            || (PHP_OS_FAMILY !== 'Windows' && !is_executable($controls))
        ) {
            throw new \RuntimeException('Cannot establish manifest control-parent access.');
        }
    }

    /** Read one fixed manifest control file without accepting links or racing identity. */
    private static function readControlFile(string $path, string $parent, int $maximum, ?int $exact = null): string
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0o170000) !== 0o100000 || is_link($path)
            || $stat['size'] > $maximum || ($exact !== null && $stat['size'] !== $exact)
            || $stat['nlink'] !== 1 || ($stat['mode'] & 0o022) !== 0 || $stat['uid'] !== fileowner($parent)
        ) {
            throw new \RuntimeException('Manifest control must be a bounded regular non-symlink custody file.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read required manifest control.');
        }
        try {
            $opened = fstat($handle);
            $bytes = fread($handle, $maximum + 1);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if ($bytes === false || strlen($bytes) > $maximum || ($exact !== null && strlen($bytes) !== $exact)
            || $opened === false || $after === false
            || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']
            || $after['ino'] !== $stat['ino'] || $after['dev'] !== $stat['dev']
            || ($after['mode'] & 0o170000) !== 0o100000 || $after['nlink'] !== 1
            || ($after['mode'] & 0o022) !== 0 || $after['uid'] !== $stat['uid']
        ) {
            throw new \RuntimeException('Manifest control custody changed while reading.');
        }
        return $bytes;
    }

    private static function canonicalDirectory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || $real !== $path || !is_dir($path) || is_link($path)
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
        ) {
            throw new \RuntimeException('Manifest directories must exist as canonical physical paths.');
        }
        return $real;
    }

    private static function overlaps(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/');
    }
}
