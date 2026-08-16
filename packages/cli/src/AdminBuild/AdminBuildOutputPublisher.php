<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

final class AdminBuildOutputPublisher
{
    public function publish(string $builtOutput, string $adminPath): void
    {
        $sourceRoot = realpath($builtOutput);
        $adminRoot = realpath($adminPath);
        if (!is_string($sourceRoot) || !is_dir($sourceRoot) || !is_string($adminRoot) || !is_dir($adminRoot)) {
            throw new AdminBuildPolicyException('generated-output-missing');
        }

        $nonce = bin2hex(random_bytes(8));
        $staging = $adminRoot . '/.waaseyaa-output-new-' . $nonce;
        $backup = $adminRoot . '/.waaseyaa-output-old-' . $nonce;
        $target = $adminRoot . '/.output';
        if (!mkdir($staging, 0o755)) {
            throw new AdminBuildPolicyException('generated-output-stage-failed');
        }

        try {
            $this->copyDirectory($sourceRoot, $staging, $sourceRoot);
            if (file_exists($target) || is_link($target)) {
                if (is_link($target) || !is_dir($target) || !rename($target, $backup)) {
                    throw new AdminBuildPolicyException('generated-output-replace-failed');
                }
            }
            if (!rename($staging, $target)) {
                if (is_dir($backup)) {
                    @rename($backup, $target);
                }
                throw new AdminBuildPolicyException('generated-output-replace-failed');
            }
            if (is_dir($backup)) {
                $this->removeDirectory($backup, $adminRoot);
            }
        } finally {
            if (is_dir($staging)) {
                $this->removeDirectory($staging, $adminRoot);
            }
        }
    }

    private function copyDirectory(string $source, string $destination, string $sourceRoot): void
    {
        foreach (new \DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink()) {
                throw new AdminBuildPolicyException('generated-output-symlink');
            }
            $real = realpath($entry->getPathname());
            if (!is_string($real) || !str_starts_with($real, $sourceRoot . DIRECTORY_SEPARATOR)) {
                throw new AdminBuildPolicyException('generated-output-path-escape');
            }
            $target = $destination . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                if (!mkdir($target, 0o755)) {
                    throw new AdminBuildPolicyException('generated-output-stage-failed');
                }
                $this->copyDirectory($real, $target, $sourceRoot);
            } elseif ($entry->isFile()) {
                if (!copy($real, $target) || !chmod($target, 0o644)) {
                    throw new AdminBuildPolicyException('generated-output-stage-failed');
                }
            } else {
                throw new AdminBuildPolicyException('generated-output-invalid');
            }
        }
    }

    private function removeDirectory(string $path, string $adminRoot): void
    {
        $real = realpath($path);
        if (!is_string($real) || !str_starts_with($real, $adminRoot . DIRECTORY_SEPARATOR . '.waaseyaa-output-')) {
            throw new AdminBuildPolicyException('generated-output-cleanup-refused');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new AdminBuildPolicyException('generated-output-cleanup-refused');
            }
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($real);
    }
}
