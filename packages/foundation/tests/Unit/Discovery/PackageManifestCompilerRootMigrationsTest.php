<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Discovery\InvalidMigrationEntryException;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

#[CoversClass(PackageManifestCompiler::class)]
final class PackageManifestCompilerRootMigrationsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/waaseyaa_root_migrations_'.uniqid();
        mkdir($this->root.'/vendor/composer', 0o755, true);
        file_put_contents($this->root.'/vendor/composer/installed.json', '{"packages":[]}');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    #[Test]
    public function compile_retains_root_migrations_under_composer_package_name(): void
    {
        $this->writeComposer([
            'name' => 'acme/application',
            'extra' => ['waaseyaa' => [
                'migrations' => ['Acme\\Application\\Migrations', 'migrations'],
            ]],
        ]);

        $manifest = (new PackageManifestCompiler($this->root, $this->root.'/storage'))->compile();

        self::assertSame(
            ['acme/application' => ['Acme\\Application\\Migrations', 'migrations']],
            $manifest->migrations,
        );
    }

    #[Test]
    public function root_migration_declaration_without_package_name_fails_closed(): void
    {
        $this->writeComposer([
            'extra' => ['waaseyaa' => ['migrations' => 'migrations']],
        ]);

        $this->expectException(InvalidMigrationEntryException::class);
        $this->expectExceptionMessage('root Composer package');

        (new PackageManifestCompiler($this->root, $this->root.'/storage'))->compile();
    }

    #[Test]
    public function load_augments_a_matching_pre_fix_cache_with_root_migrations(): void
    {
        $composer = [
            'name' => 'acme/application',
            'extra' => ['waaseyaa' => ['migrations' => 'migrations']],
        ];
        $this->writeComposer($composer);

        $composerBytes = (string) file_get_contents($this->root.'/composer.json');
        $installedBytes = (string) file_get_contents($this->root.'/vendor/composer/installed.json');
        // No installed package declares `install-path`, so #2778's path-package
        // fingerprint input is the empty string.
        $fingerprint = hash('xxh128', implode("\0", [$composerBytes, $installedBytes, '', '', '']));

        mkdir($this->root.'/storage/framework', 0o755, true);
        $cache = [
            'providers' => [],
            'migrations' => [],
            'field_types' => [],
            'middleware' => [],
            '_manifest_inputs_fp' => $fingerprint,
        ];
        file_put_contents(
            $this->root.'/storage/framework/packages.php',
            '<?php return '.var_export($cache, true).';'."\n",
        );

        $manifest = (new PackageManifestCompiler($this->root, $this->root.'/storage'))->load();

        self::assertSame(['acme/application' => 'migrations'], $manifest->migrations);
    }

    /** @param array<string, mixed> $composer */
    private function writeComposer(array $composer): void
    {
        file_put_contents(
            $this->root.'/composer.json',
            json_encode($composer, JSON_THROW_ON_ERROR),
        );
    }
}
