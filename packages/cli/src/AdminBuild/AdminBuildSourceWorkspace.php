<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

final class AdminBuildSourceWorkspace
{
    /** @var list<string> */
    private const SOURCE_DIRECTORIES = [
        'app',
        'assets',
        'components',
        'composables',
        'content',
        'layouts',
        'layers',
        'middleware',
        'modules',
        'pages',
        'plugins',
        'public',
        'server',
        'shared',
        'utils',
    ];

    /** @var list<string> */
    private const SOURCE_FILES = [
        'app.config.ts',
        'error.vue',
        'nuxt.config.ts',
        'package-lock.json',
        'package.json',
        'tsconfig.json',
    ];

    public function prepare(string $adminPath, string $destination): string
    {
        $sourceRoot = realpath($adminPath);
        if (!is_string($sourceRoot) || !is_dir($sourceRoot) || is_link($adminPath)) {
            throw new AdminBuildPolicyException('admin-source-invalid');
        }
        if (file_exists($destination) || !mkdir($destination, 0o700, true)) {
            throw new AdminBuildPolicyException('source-workspace-create-failed');
        }
        $destinationRoot = realpath($destination);
        if (!is_string($destinationRoot)) {
            throw new AdminBuildPolicyException('source-workspace-create-failed');
        }

        $roster = [];
        foreach (self::SOURCE_DIRECTORIES as $relative) {
            $source = $sourceRoot . '/' . $relative;
            if (is_dir($source)) {
                $this->copyDirectory($source, $destinationRoot . '/' . $relative, $sourceRoot, $roster);
            }
        }
        foreach (self::SOURCE_FILES as $relative) {
            $source = $sourceRoot . '/' . $relative;
            if (is_file($source)) {
                $this->copyFile($source, $destinationRoot . '/' . $relative, $sourceRoot, $roster);
            }
        }
        $additionalTypeScriptConfigs = glob($sourceRoot . '/tsconfig.*.json');
        foreach (is_array($additionalTypeScriptConfigs) ? $additionalTypeScriptConfigs : [] as $source) {
            if (is_file($source)) {
                $this->copyFile($source, $destinationRoot . '/' . basename($source), $sourceRoot, $roster);
            }
        }
        foreach (['package.json', 'package-lock.json', 'nuxt.config.ts'] as $required) {
            if (!is_file($destinationRoot . '/' . $required)) {
                throw new AdminBuildPolicyException('declared-build-input-missing');
            }
        }

        sort($roster, SORT_STRING);

        return hash('sha256', implode("\n", $roster));
    }

    /** @param list<string> $roster */
    private function copyDirectory(string $source, string $destination, string $sourceRoot, array &$roster): void
    {
        if (is_link($source) || (!is_dir($destination) && !mkdir($destination, 0o700, true))) {
            throw new AdminBuildPolicyException('declared-build-input-invalid');
        }
        foreach (new \DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink()) {
                throw new AdminBuildPolicyException('declared-build-input-symlink');
            }
            $target = $destination . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                $this->copyDirectory($entry->getPathname(), $target, $sourceRoot, $roster);
            } elseif ($entry->isFile()) {
                $this->copyFile($entry->getPathname(), $target, $sourceRoot, $roster);
            } else {
                throw new AdminBuildPolicyException('declared-build-input-invalid');
            }
        }
    }

    /** @param list<string> $roster */
    private function copyFile(string $source, string $destination, string $sourceRoot, array &$roster): void
    {
        $real = realpath($source);
        if (!is_string($real) || is_link($source) || !str_starts_with($real, $sourceRoot . DIRECTORY_SEPARATOR)) {
            throw new AdminBuildPolicyException('declared-build-input-invalid');
        }
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0o700, true)) {
            throw new AdminBuildPolicyException('source-workspace-create-failed');
        }
        if (!copy($real, $destination) || !chmod($destination, 0o600)) {
            throw new AdminBuildPolicyException('declared-build-input-copy-failed');
        }
        $relative = str_replace('\\', '/', substr($real, strlen($sourceRoot) + 1));
        $roster[] = $relative . "\0" . hash_file('sha256', $real);
    }
}
