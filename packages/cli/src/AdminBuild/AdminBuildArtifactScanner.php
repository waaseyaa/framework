<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

final class AdminBuildArtifactScanner
{
    /** @var \WeakMap<self, list<string>>|null */
    private static ?\WeakMap $canaryRepresentations = null;

    /** @param list<string> $syntheticCanaries */
    public function __construct(#[\SensitiveParameter] array $syntheticCanaries = [])
    {
        $representations = [];
        foreach ($syntheticCanaries as $canary) {
            if ($canary === '') {
                continue;
            }
            $base64 = base64_encode($canary);
            foreach ([$canary, $base64, rtrim(strtr($base64, '+/', '-_'), '='), rawurlencode($canary)] as $form) {
                $representations[$form] = true;
            }
            try {
                $json = json_encode($canary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $representations[$json] = true;
                $representations[substr($json, 1, -1)] = true;
            } catch (\JsonException) {
                // Raw/base64/url forms still cover binary synthetic values.
            }
        }
        self::$canaryRepresentations ??= new \WeakMap();
        self::$canaryRepresentations[$this] = array_keys($representations);
    }

    public function scan(string $projectRoot, string $adminPath): AdminBuildArtifactReport
    {
        $projectRoot = $this->validatedRoot($projectRoot);
        $adminPath = $this->validatedRoot($adminPath);
        $adminLabelRoot = $this->isWithin($adminPath, $projectRoot) ? $projectRoot : $adminPath;
        $adminLabelPrefix = $adminLabelRoot === $projectRoot ? '' : 'admin-package';

        $files = [];
        $this->collectAdminGeneratedFiles(
            $adminPath,
            $adminLabelRoot,
            $adminLabelPrefix,
            $files,
        );
        $publishable = $projectRoot . '/packages/admin-surface/dist';
        if (is_dir($publishable)) {
            $this->collectFiles(
                root: $publishable,
                boundaryRoot: $projectRoot,
                labelRoot: $projectRoot,
                labelPrefix: '',
                files: $files,
                skipNodeModules: false,
            );
        }
        ksort($files, SORT_STRING);

        $roster = [];
        foreach ($files as $relative => $absolute) {
            $before = @stat($absolute);
            if (!is_array($before) || !is_readable($absolute)) {
                throw new AdminBuildArtifactScanException('artifact-unreadable');
            }
            [$hash, $size] = $this->scanFile($absolute);
            clearstatcache(true, $absolute);
            $after = @stat($absolute);
            if (!is_array($after)
                || $before['ino'] !== $after['ino']
                || $before['size'] !== $after['size']
                || $before['mtime'] !== $after['mtime']) {
                throw new AdminBuildArtifactScanException('artifact-changed-during-scan');
            }
            $roster[] = $relative . "\0" . $size . "\0" . $hash;
        }

        return new AdminBuildArtifactReport(
            files: array_keys($files),
            inventoryHash: hash('sha256', implode("\n", $roster)),
            clean: true,
        );
    }

    /** @param array<string, string> $files */
    private function collectAdminGeneratedFiles(
        string $adminPath,
        string $labelRoot,
        string $labelPrefix,
        array &$files,
    ): void {
        $declaredSourceDirectories = ['app', 'e2e', 'node_modules', 'scripts', 'tests'];
        $declaredSourceFiles = [
            '.npmrc',
            'README.md',
            'app.config.ts',
            'eslint.config.mjs',
            'nuxt.config.ts',
            'package-lock.json',
            'package.json',
            'playwright.config.ts',
            'tsconfig.contracts.json',
            'tsconfig.json',
            'vitest.config.ts',
        ];
        foreach (new \DirectoryIterator($adminPath) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $name = $entry->getFilename();
            if (str_starts_with($name, '.env')) {
                continue;
            }
            if ($entry->isDir() && in_array($name, $declaredSourceDirectories, true)) {
                continue;
            }
            if ($entry->isFile() && in_array($name, $declaredSourceFiles, true)) {
                continue;
            }
            if ($entry->isLink()) {
                $target = realpath($entry->getPathname());
                if (!is_string($target) || !$this->isWithin($target, $adminPath)) {
                    throw new AdminBuildArtifactScanException('artifact-symlink-refused');
                }
                continue;
            }
            if ($entry->isDir()) {
                $this->collectFiles(
                    root: $entry->getPathname(),
                    boundaryRoot: $adminPath,
                    labelRoot: $labelRoot,
                    labelPrefix: $labelPrefix,
                    files: $files,
                    skipNodeModules: false,
                );
            } elseif ($entry->isFile()) {
                $this->addFile($entry->getPathname(), $adminPath, $labelRoot, $labelPrefix, $files);
            }
        }

        $dependencyCache = $adminPath . '/node_modules/.cache';
        if (is_dir($dependencyCache)) {
            $this->collectFiles(
                root: $dependencyCache,
                boundaryRoot: $adminPath,
                labelRoot: $labelRoot,
                labelPrefix: $labelPrefix,
                files: $files,
                skipNodeModules: false,
            );
        }
    }

    /** @param array<string, string> $files */
    private function collectFiles(
        string $root,
        string $boundaryRoot,
        string $labelRoot,
        string $labelPrefix,
        array &$files,
        bool $skipNodeModules,
    ): void {
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $entry) use ($skipNodeModules): bool {
                return !($skipNodeModules && $entry->isDir() && $entry->getFilename() === 'node_modules');
            },
        );
        $iterator = new \RecursiveIteratorIterator($filter);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                $target = realpath($entry->getPathname());
                if (!is_string($target) || !$this->isWithin($target, $boundaryRoot)) {
                    throw new AdminBuildArtifactScanException('artifact-symlink-refused');
                }
                // Internal generated aliases (Nuxt's dist -> .output/public)
                // are safe because the canonical target is traversed directly.
                continue;
            }
            if (!$entry->isFile()) {
                continue;
            }
            $this->addFile($entry->getPathname(), $boundaryRoot, $labelRoot, $labelPrefix, $files);
        }
    }

    /** @param array<string, string> $files */
    private function addFile(
        string $absolute,
        string $boundaryRoot,
        string $labelRoot,
        string $labelPrefix,
        array &$files,
    ): void {
        $real = realpath($absolute);
        if (!is_string($real) || !$this->isWithin($real, $boundaryRoot)) {
            throw new AdminBuildArtifactScanException('artifact-path-escape');
        }
        $relative = str_replace('\\', '/', substr($real, strlen($labelRoot) + 1));
        if ($labelPrefix !== '') {
            $relative = $labelPrefix . '/' . $relative;
        }
        $files[$relative] = $real;
    }

    /** @return array{0: string, 1: int} */
    private function scanFile(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new AdminBuildArtifactScanException('artifact-unreadable');
        }
        $hash = hash_init('sha256');
        $tail = '';
        $size = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if (!is_string($chunk)) {
                    throw new AdminBuildArtifactScanException('artifact-read-failed');
                }
                $size += strlen($chunk);
                hash_update($hash, $chunk);
                $window = $tail . $chunk;
                if ($this->containsSecretMaterial($window)) {
                    throw new AdminBuildArtifactScanException('artifact-secret-detected');
                }
                $tail = substr($window, -4096);
            }
        } finally {
            fclose($handle);
        }

        return [hash_final($hash), $size];
    }

    private function containsSecretMaterial(string $value): bool
    {
        foreach (self::$canaryRepresentations[$this] ?? [] as $representation) {
            if ($representation !== '' && str_contains($value, $representation)) {
                return true;
            }
        }
        $patterns = [
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
            '/\bsk-[A-Za-z0-9_-]{20,}\b/',
            '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/',
            '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
            '/\bya29\.[A-Za-z0-9_-]{20,}\b/',
            '/\bAIza[A-Za-z0-9_-]{20,}\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }
        if (preg_match_all(
            '/\b(?:api[_-]?(?:key|credential)|credential|secret|token|authorization|password|private[_-]?key)\b\s*[:=]\s*["\']?([A-Za-z0-9+\/_=-]{32,})/i',
            $value,
            $matches,
        )) {
            foreach ($matches[1] as $candidate) {
                if ($this->entropy($candidate) >= 4.0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function entropy(string $value): float
    {
        $length = strlen($value);
        if ($length === 0) {
            return 0.0;
        }
        $entropy = 0.0;
        foreach (count_chars($value, 1) as $count) {
            $probability = $count / $length;
            $entropy -= $probability * log($probability, 2);
        }

        return $entropy;
    }

    private function validatedRoot(string $path): string
    {
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real)) {
            throw new AdminBuildArtifactScanException('artifact-root-invalid');
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Admin artifact scanners cannot be serialized.');
    }

}
