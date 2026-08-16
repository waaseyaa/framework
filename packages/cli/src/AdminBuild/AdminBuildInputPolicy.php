<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

final class AdminBuildInputPolicy
{
    /** @var list<string> */
    private const SAFE_NPM_KEYS = [
        'audit',
        'color',
        'engine-strict',
        'fund',
        'include',
        'legacy-peer-deps',
        'loglevel',
        'omit',
        'progress',
        'save-exact',
        'strict-peer-deps',
    ];

    /**
     * @param array<string, string> $environment
     * @return non-empty-string SHA-256 fingerprint of the exact package and lock inputs.
     */
    public function validate(string $adminPath, array $environment): string
    {
        $resolvedAdminPath = realpath($adminPath);
        $adminPath = is_string($resolvedAdminPath) ? $resolvedAdminPath : '';
        if ($adminPath === '' || !is_dir($adminPath)) {
            throw new AdminBuildPolicyException('admin-path-invalid');
        }

        foreach (new \DirectoryIterator($adminPath) as $entry) {
            if (!$entry->isDot() && str_starts_with($entry->getFilename(), '.env')) {
                throw new AdminBuildPolicyException('dotenv-present');
            }
        }

        $packagePath = $adminPath . '/package.json';
        $lockPath = $adminPath . '/package-lock.json';
        if (!is_file($packagePath)) {
            throw new AdminBuildPolicyException('package-manifest-missing');
        }
        if (!is_file($lockPath)) {
            throw new AdminBuildPolicyException('lock-missing');
        }

        $package = $this->decodeObject($packagePath, 'package-manifest-invalid');
        $lock = $this->decodeObject($lockPath, 'lock-invalid');
        $rootLock = $lock['packages'][''] ?? null;
        if (!is_array($rootLock)
            || ($lock['lockfileVersion'] ?? 0) < 2
            || ($package['name'] ?? null) !== ($lock['name'] ?? null)
            || ($package['version'] ?? null) !== ($lock['version'] ?? null)
            || ($package['name'] ?? null) !== ($rootLock['name'] ?? null)
            || ($package['version'] ?? null) !== ($rootLock['version'] ?? null)) {
            throw new AdminBuildPolicyException('lock-mismatch');
        }

        $npmrc = $adminPath . '/.npmrc';
        if (is_file($npmrc)) {
            $this->validateNpmConfig($npmrc, $environment);
        }

        return hash('sha256', hash_file('sha256', $packagePath) . ':' . hash_file('sha256', $lockPath));
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $path, string $errorCode): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AdminBuildPolicyException($errorCode);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new AdminBuildPolicyException($errorCode);
        }

        return $decoded;
    }

    /** @param array<string, string> $environment */
    private function validateNpmConfig(string $path, array $environment): void
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new AdminBuildPolicyException('npm-config-unreadable');
        }
        $lines = preg_split('/\R/', $contents);
        foreach (is_array($lines) ? $lines : [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (!preg_match('/^([^=]+)=(.*)$/D', $line, $match)) {
                throw new AdminBuildPolicyException('npm-config-invalid');
            }
            $key = strtolower(trim($match[1]));
            $value = $match[2];
            if (!in_array($key, self::SAFE_NPM_KEYS, true)) {
                throw new AdminBuildPolicyException('npm-config-forbidden-key');
            }
            if (preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', $value, $variables)) {
                foreach ($variables[1] as $variable) {
                    if (!array_key_exists($variable, $environment)) {
                        throw new AdminBuildPolicyException('npm-config-unresolved-environment');
                    }
                }
            }
            $withoutResolved = preg_replace('/\$\{[A-Za-z_][A-Za-z0-9_]*\}/', '', $value) ?? $value;
            if (str_contains($withoutResolved, '${')) {
                throw new AdminBuildPolicyException('npm-config-unresolved-environment');
            }
        }
    }
}
