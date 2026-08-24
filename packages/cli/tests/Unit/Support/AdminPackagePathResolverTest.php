<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Support\AdminPackagePathResolver;

#[CoversClass(AdminPackagePathResolver::class)]
final class AdminPackagePathResolverTest extends TestCase
{
    #[Test]
    public function env_overrides_composer_extra(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_admin_resolve_' . uniqid();

        try {
            mkdir($root, 0755, true);
            mkdir($root . '/from-env', 0755, true);
            file_put_contents($root . '/from-env/package.json', '{}');
            file_put_contents($root . '/composer.json', json_encode([
                'extra' => ['waaseyaa' => ['admin_path' => 'from-composer']],
            ], JSON_THROW_ON_ERROR));
            mkdir($root . '/from-composer', 0755, true);
            file_put_contents($root . '/from-composer/package.json', '{}');

            putenv('WAASEYAA_ADMIN_PATH=' . $root . '/from-env');
            $path = (new AdminPackagePathResolver($root))->resolve();
            putenv('WAASEYAA_ADMIN_PATH');

            $this->assertSame(realpath($root . '/from-env'), $path);
        } finally {
            putenv('WAASEYAA_ADMIN_PATH');
            (new Filesystem())->remove($root);
        }
    }

    #[Test]
    public function composer_extra_relative_path_works(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_admin_resolve_' . uniqid();

        try {
            mkdir($root . '/admin-pkg', 0755, true);
            file_put_contents($root . '/admin-pkg/package.json', '{}');
            file_put_contents($root . '/composer.json', json_encode([
                'extra' => ['waaseyaa' => ['admin_path' => 'admin-pkg']],
            ], JSON_THROW_ON_ERROR));

            $path = (new AdminPackagePathResolver($root))->resolve();
            $this->assertSame(realpath($root . '/admin-pkg'), $path);
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    #[Test]
    public function throws_when_unconfigured(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_admin_resolve_' . uniqid();

        try {
            mkdir($root, 0755, true);
            file_put_contents($root . '/composer.json', json_encode([], JSON_THROW_ON_ERROR));

            $this->expectException(\RuntimeException::class);
            (new AdminPackagePathResolver($root))->resolve();
        } finally {
            (new Filesystem())->remove($root);
        }
    }

}
