<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

/** Normalizes bootstrap selectors once into one immutable authority context. @api */
final class ConfigurationAuthorityResolver
{
    /**
     * @param array<string, mixed> $bootstrap
     * @param array<string, mixed> $environment
     */
    public function resolve(
        string $projectRoot,
        string $databaseIdentity,
        array $bootstrap,
        array $environment,
    ): ConfigurationAuthorityContext {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new ConfigurationAuthorityConflictException('Configuration project root must be an existing directory.');
        }
        $root = $this->slash($root);
        if ($databaseIdentity === '') {
            throw new ConfigurationAuthorityConflictException('Configuration database identity must not be empty.');
        }

        $config = $bootstrap['config'] ?? [];
        if (!is_array($config)) {
            throw new ConfigurationAuthorityConflictException('Bootstrap config must be an array.');
        }
        $selectors = [
            'config.sync_path' => $config['sync_path'] ?? null,
            'WAASEYAA_CONFIG_SYNC_PATH' => $environment['WAASEYAA_CONFIG_SYNC_PATH'] ?? null,
            'config_dir' => $bootstrap['config_dir'] ?? null,
            'WAASEYAA_CONFIG_DIR' => $environment['WAASEYAA_CONFIG_DIR'] ?? null,
        ];
        $provided = [];
        foreach ($selectors as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value)) {
                throw new ConfigurationAuthorityConflictException("Configuration sync selector {$name} must be a string.");
            }
            $provided[$name] = $this->normalizePath($root, $value);
        }

        $provenance = array_keys($provided);
        if ($provided === []) {
            $syncPath = $this->normalizePath($root, 'storage/config-sync');
            $provenance = ['default'];
        } else {
            $paths = array_values(array_unique(array_values($provided)));
            if (count($paths) !== 1) {
                throw new ConfigurationAuthorityConflictException('configuration sync selectors disagree after canonical normalization.');
            }
            $syncPath = $paths[0];
        }

        $allowExternal = filter_var($config['allow_external_sync_path'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$allowExternal && !$this->isWithin($syncPath, $root)) {
            throw new ConfigurationAuthorityConflictException('Configuration sync path is outside the project boundary.');
        }

        $identity = json_encode([
            'schema' => 'configuration-authority.v1',
            'database_identity' => $databaseIdentity,
            'sync_path' => $syncPath,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [$anchor, $device, $inode] = $this->syncPathAnchorIdentity($syncPath);

        return new ConfigurationAuthorityContext(
            hash('sha256', $identity),
            $databaseIdentity,
            $syncPath,
            $provenance,
            syncPathAnchor: $anchor,
            syncPathAnchorDevice: $device,
            syncPathAnchorInode: $inode,
        );
    }

    private function normalizePath(string $root, string $selected): string
    {
        if (str_contains($selected, "\0")) {
            throw new ConfigurationAuthorityConflictException('Configuration sync path contains a null byte.');
        }
        $selected = $this->slash(trim($selected));
        if ($selected === '') {
            throw new ConfigurationAuthorityConflictException('Configuration sync path must not be empty.');
        }
        $absolute = str_starts_with($selected, '/')
            || preg_match('/^[A-Za-z]:\//D', $selected) === 1;
        $candidate = $absolute ? $selected : $root . '/' . $selected;

        $prefix = str_starts_with($candidate, '/') ? '/' : '';
        $segments = [];
        foreach (explode('/', $candidate) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new ConfigurationAuthorityConflictException('Configuration sync path traverses above its filesystem root.');
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $normalized = $prefix . implode('/', $segments);

        $existing = $normalized;
        $suffix = [];
        while (!file_exists($existing)) {
            $leaf = basename($existing);
            if ($leaf === '' || $leaf === '.' || $leaf === DIRECTORY_SEPARATOR) {
                break;
            }
            array_unshift($suffix, $leaf);
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            $existing = $parent;
        }
        $real = realpath($existing);
        if ($real !== false) {
            $normalized = rtrim($this->slash($real), '/');
            if ($suffix !== []) {
                $normalized .= '/' . implode('/', $suffix);
            }
        }

        return $normalized;
    }

    private function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    /** @return array{string, int, int} */
    private function syncPathAnchorIdentity(string $syncPath): array
    {
        $anchor = $syncPath;
        while (!file_exists($anchor)) {
            if (is_link($anchor)) {
                throw new ConfigurationAuthorityConflictException('Configuration sync path contains a dangling symbolic link.');
            }
            $parent = dirname($anchor);
            if ($parent === $anchor) {
                throw new ConfigurationAuthorityConflictException('Configuration sync path has no existing filesystem anchor.');
            }
            $anchor = $parent;
        }
        $real = realpath($anchor);
        $stat = $real === false ? false : @stat($real);
        if ($real === false || $stat === false || !is_dir($real)) {
            throw new ConfigurationAuthorityConflictException('Configuration sync path anchor is not an inspectable local directory.');
        }

        return [$this->slash($real), $stat['dev'], $stat['ino']];
    }

    private function slash(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
