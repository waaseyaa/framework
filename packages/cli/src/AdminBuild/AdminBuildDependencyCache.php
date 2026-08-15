<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/** @internal */
final class AdminBuildDependencyCache
{
    public function prepare(string $projectRoot): string
    {
        $root = realpath($projectRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new AdminBuildPolicyException('project-root-invalid');
        }

        $path = $root;
        foreach (['storage', 'framework', 'admin-build', 'npm-cache-v1'] as $component) {
            $path .= DIRECTORY_SEPARATOR . $component;
            if (is_link($path) || (file_exists($path) && !is_dir($path))) {
                throw new AdminBuildPolicyException('dependency-cache-invalid');
            }
            if (!is_dir($path) && !mkdir($path, 0o700)) {
                throw new AdminBuildPolicyException('dependency-cache-create-failed');
            }
            $real = realpath($path);
            if (!is_string($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                throw new AdminBuildPolicyException('dependency-cache-invalid');
            }
            $path = $real;
        }

        return $path;
    }
}
