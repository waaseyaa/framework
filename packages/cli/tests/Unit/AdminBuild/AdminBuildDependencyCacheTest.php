<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\AdminBuild\AdminBuildDependencyCache;
use Waaseyaa\CLI\AdminBuild\AdminBuildPolicyException;

#[CoversClass(AdminBuildDependencyCache::class)]
final class AdminBuildDependencyCacheTest extends TestCase
{
    #[Test]
    public function cache_is_stable_project_local_and_refuses_a_symlinked_storage_boundary(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_admin_cache_' . bin2hex(random_bytes(6));
        mkdir($root, 0o700);
        $cache = new AdminBuildDependencyCache();

        try {
            $first = $cache->prepare($root);
            $second = $cache->prepare($root);
            self::assertSame($first, $second);
            self::assertSame(realpath($root) . '/storage/framework/admin-build/npm-cache-v1', $first);

            $this->removeTree($root . '/storage');
            $outside = $root . '-outside';
            mkdir($outside, 0o700);
            if (!@symlink($outside, $root . '/storage')) {
                self::markTestSkipped('Symlink creation unavailable.');
            }
            try {
                $cache->prepare($root);
                self::fail('A symlinked cache boundary must refuse.');
            } catch (AdminBuildPolicyException $e) {
                self::assertSame('dependency-cache-invalid', $e->errorCode);
            }
            unlink($root . '/storage');
            rmdir($outside);
        } finally {
            $this->removeTree($root);
            if (is_dir($root . '-outside')) {
                rmdir($root . '-outside');
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (is_link($path)) {
                unlink($path);
            }

            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
