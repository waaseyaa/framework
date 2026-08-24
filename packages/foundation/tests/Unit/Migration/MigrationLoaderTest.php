<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationLoader;

#[CoversClass(MigrationLoader::class)]
final class MigrationLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_migration_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function loadsAppMigrationsFromMigrationsDirectory(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/20260317_143000_create_posts.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $this->assertArrayHasKey('app', $all);
        $this->assertArrayHasKey('app:20260317_143000_create_posts', $all['app']);
        $this->assertInstanceOf(Migration::class, $all['app']['app:20260317_143000_create_posts']);
    }

    #[Test]
    public function loadsPackageMigrations(): void
    {
        $pkgDir = $this->tempDir . '/vendor/waaseyaa/node/migrations';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir . '/20260317_100000_create_nodes.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest(migrations: ['waaseyaa/node' => $pkgDir]);
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $this->assertArrayHasKey('waaseyaa/node', $all);
        $this->assertArrayHasKey('waaseyaa/node:20260317_100000_create_nodes', $all['waaseyaa/node']);
    }

    #[Test]
    public function relativePackagePathNeverResolvesToTheApplicationsWorkingDirectory(): void
    {
        $appDir = $this->tempDir . '/migrations';
        $packageDir = $this->tempDir . '/vendor/example/package/migrations';
        mkdir($appDir);
        mkdir($packageDir, 0777, true);
        file_put_contents($appDir . '/20260317_app.php', <<<'PHP'
        <?php
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);
        file_put_contents($packageDir . '/20260317_package.php', <<<'PHP'
        <?php
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $previousDirectory = getcwd();
        chdir($this->tempDir);

        try {
            $manifest = new PackageManifest(migrations: ['example/package' => 'migrations']);
            $all = (new MigrationLoader($this->tempDir, $manifest))->loadAll();
        } finally {
            if (is_string($previousDirectory)) {
                chdir($previousDirectory);
            }
        }

        self::assertSame(
            ['example/package:20260317_package'],
            array_keys($all['example/package']),
        );
        self::assertSame(['app:20260317_app'], array_keys($all['app']));
    }

    #[Test]
    public function appMigrationsRunAfterPackageMigrations(): void
    {
        $pkgDir = $this->tempDir . '/vendor/waaseyaa/node/migrations';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir . '/20260317_100000_create_nodes.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/20260317_090000_early_app.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest(migrations: ['waaseyaa/node' => $pkgDir]);
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $keys = array_keys($all);
        $this->assertSame('waaseyaa/node', $keys[0]);
        $this->assertSame('app', $keys[1]);
    }

    #[Test]
    public function handlesMissingMigrationsDirectory(): void
    {
        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $this->assertSame([], $all);
    }

    #[Test]
    public function sortsFilesAlphabeticallyWithinPackage(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/20260318_second.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);
        file_put_contents($migrationsDir . '/20260317_first.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $names = array_keys($all['app']);
        $this->assertSame('app:20260317_first', $names[0]);
        $this->assertSame('app:20260318_second', $names[1]);
    }

    #[Test]
    public function throwsOnInvalidMigrationFile(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/20260317_bad.php', '<?php return "not a migration";');

        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/20260317_bad\.php/');
        $loader->loadAll();
    }

    #[Test]
    public function ignoresNonPhpFiles(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/README.md', '# Migrations');
        file_put_contents($migrationsDir . '/20260317_valid.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $this->assertCount(1, $all['app']);
    }

}
