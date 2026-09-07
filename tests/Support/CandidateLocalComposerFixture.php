<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Builds a scratch checkout's Composer boundary without borrowing first-party source.
 *
 * Generated Composer entry/metadata files are copied into the scratch checkout.
 * Third-party package directories remain explicit external links; Waaseyaa path
 * packages point back into the scratch checkout, with only declared autoload files
 * copied when a deliberately minimal fixture omitted the package tree.
 */
final class CandidateLocalComposerFixture
{
    public static function materialize(string $sourceRoot, string $targetRoot): void
    {
        $sourceRoot = self::canonicalDirectory($sourceRoot, 'source checkout');
        $targetRoot = self::canonicalDirectory($targetRoot, 'fixture checkout');
        $lock = self::readJson($targetRoot . '/composer.lock');
        if (hash_file('sha256', $sourceRoot . '/composer.lock') !== hash_file('sha256', $targetRoot . '/composer.lock')) {
            throw new \RuntimeException('Candidate-local Composer fixtures require the source checkout lock unchanged.');
        }
        $installed = self::readJson($sourceRoot . '/vendor/composer/installed.json');

        $filesystem = new Filesystem();
        $targetVendor = $targetRoot . '/vendor';
        $filesystem->remove($targetVendor);
        $filesystem->mkdir([$targetVendor . '/composer', $targetVendor . '/waaseyaa']);

        self::copyFile($sourceRoot . '/vendor/autoload.php', $targetVendor . '/autoload.php');
        foreach (new \FilesystemIterator($sourceRoot . '/vendor/composer', \FilesystemIterator::SKIP_DOTS) as $entry) {
            $target = $targetVendor . '/composer/' . $entry->getFilename();
            if ($entry->isFile()) {
                self::copyFile($entry->getPathname(), $target);
            } elseif ($entry->isDir() && !symlink($entry->getPathname(), $target)) {
                throw new \RuntimeException(sprintf('Could not link declared Composer fixture package %s.', $entry->getFilename()));
            }
        }

        $installedPackages = is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        $thirdPartyVendors = [];
        foreach ($installedPackages as $package) {
            $name = is_array($package) && is_string($package['name'] ?? null) ? $package['name'] : '';
            if ($name === '' || str_starts_with($name, 'waaseyaa/') || !str_contains($name, '/')) {
                continue;
            }
            $vendorName = explode('/', $name, 2)[0];
            if ($vendorName !== 'composer') {
                $thirdPartyVendors[$vendorName] = true;
            }
        }
        foreach (array_keys($thirdPartyVendors) as $vendorName) {
            $source = $sourceRoot . '/vendor/' . $vendorName;
            if (!is_dir($source) || !symlink($source, $targetVendor . '/' . $vendorName)) {
                throw new \RuntimeException(sprintf('Could not link declared third-party fixture vendor %s.', $vendorName));
            }
        }

        $packages = array_merge(
            is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
            is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
        );
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null) || !str_starts_with($package['name'], 'waaseyaa/')) {
                continue;
            }
            $dist = is_array($package['dist'] ?? null) ? $package['dist'] : [];
            $relative = is_string($dist['url'] ?? null) ? str_replace('\\', '/', $dist['url']) : '';
            if (($dist['type'] ?? null) !== 'path' || preg_match('#^packages/[a-z0-9-]+/?$#D', $relative) !== 1) {
                throw new \RuntimeException(sprintf('Waaseyaa fixture package %s does not have a repository-local path dist.', $package['name']));
            }
            $relative = rtrim($relative, '/');
            $packageRoot = $targetRoot . '/' . $relative;
            $filesystem->mkdir($packageRoot);
            self::copyFile($sourceRoot . '/' . $relative . '/composer.json', $packageRoot . '/composer.json');

            $installedName = substr($package['name'], strlen('waaseyaa/'));
            if (!symlink('../../' . $relative, $targetVendor . '/waaseyaa/' . $installedName)) {
                throw new \RuntimeException(sprintf('Could not bind first-party fixture package %s.', $package['name']));
            }

            $autoload = is_array($package['autoload'] ?? null) ? $package['autoload'] : [];
            $files = is_array($autoload['files'] ?? null) ? $autoload['files'] : [];
            foreach ($files as $autoloadFile) {
                if (!is_string($autoloadFile) || str_contains($autoloadFile, '..')) {
                    throw new \RuntimeException(sprintf('Unsafe autoload-file declaration for %s.', $package['name']));
                }
                $source = $sourceRoot . '/' . $relative . '/' . ltrim($autoloadFile, '/');
                $target = $packageRoot . '/' . ltrim($autoloadFile, '/');
                $filesystem->mkdir(dirname($target));
                self::copyFile($source, $target);
            }
        }
    }

    private static function canonicalDirectory(string $path, string $label): string
    {
        $canonical = realpath($path);
        if (!is_string($canonical) || !is_dir($canonical)) {
            throw new \RuntimeException(sprintf('Could not resolve %s %s.', $label, $path));
        }

        return $canonical;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Could not read fixture metadata %s.', $path));
        }
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Fixture metadata %s is not an object.', $path));
        }

        return $decoded;
    }

    private static function copyFile(string $source, string $target): void
    {
        if (!is_file($source) || !copy($source, $target)) {
            throw new \RuntimeException(sprintf('Could not copy fixture file %s to %s.', $source, $target));
        }
    }
}
