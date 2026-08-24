<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\AdminBuild\AdminBuildOutputPublisher;

#[CoversClass(AdminBuildOutputPublisher::class)]
final class AdminBuildOutputPublisherTest extends TestCase
{
    #[Test]
    public function published_static_output_is_readable_by_the_serving_account_group(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_admin_publish_' . bin2hex(random_bytes(6));
        $built = $root . '/built';
        $admin = $root . '/admin';
        mkdir($built . '/public/assets', 0o700, true);
        mkdir($admin, 0o700, true);
        file_put_contents($built . '/public/assets/app.js', 'synthetic');

        try {
            new AdminBuildOutputPublisher()->publish($built, $admin);

            self::assertSame(0o755, fileperms($admin . '/.output/public/assets') & 0o777);
            self::assertSame(0o644, fileperms($admin . '/.output/public/assets/app.js') & 0o777);
        } finally {
            (new Filesystem())->remove($root);
        }
    }
}
