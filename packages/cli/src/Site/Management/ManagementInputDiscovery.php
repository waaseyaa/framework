<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Management;

use Symfony\Component\Finder\Finder;

/**
 * Complete identity of a sealed, regular project input tree for management checks.
 * Only Git metadata is excluded. Store receipts outside this tree and keep it
 * stable while checking it. This is an input identity, not deployment attestation.
 * @api
 */
final class ManagementInputDiscovery
{
    public function digest(string $projectRoot): string
    {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('Management inputs require an existing project root.');
        }
        $finder = new Finder()->in($root)->ignoreDotFiles(false)->ignoreVCS(false)
            ->exclude('.git')->notName('.git')->sortByName();
        $context = hash_init('sha256');
        hash_update($context, "waaseyaa.management-inputs.v1\0");
        $rootMode = fileperms($root);
        if ($rootMode === false) {
            throw new \RuntimeException('Unable to inspect management input root.');
        }
        hash_update($context, json_encode(['.', 'directory', $rootMode & 0o7777], JSON_THROW_ON_ERROR) . "\n");
        foreach ($finder as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());
            if ($file->isLink() || (!$file->isFile() && !$file->isDir())) {
                throw new \RuntimeException('Management inputs must be regular files and directories: ' . $path);
            }
            $mode = $file->getPerms() & 0o7777;
            if ($file->isDir()) {
                hash_update($context, json_encode([$path, 'directory', $mode], JSON_THROW_ON_ERROR) . "\n");
                continue;
            }
            $digest = hash_file('sha256', $file->getPathname());
            if ($digest === false) {
                throw new \RuntimeException('Unable to read management input: ' . $path);
            }
            hash_update($context, json_encode([$path, 'file', $mode, $digest], JSON_THROW_ON_ERROR) . "\n");
        }

        return hash_final($context);
    }
}
