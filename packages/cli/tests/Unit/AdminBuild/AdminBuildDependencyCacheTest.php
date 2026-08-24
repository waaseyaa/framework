<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
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

            (new Filesystem())->remove($root . '/storage');
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
            (new Filesystem())->remove($root);
            if (is_dir($root . '-outside')) {
                rmdir($root . '-outside');
            }
        }
    }

}
