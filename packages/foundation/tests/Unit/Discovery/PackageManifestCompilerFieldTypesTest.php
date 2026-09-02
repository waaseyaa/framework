<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Field\Tests\Fixtures\ExtensionFieldTypeFixture;
use Waaseyaa\Foundation\Discovery\FieldTypeManifestCollisionException;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

/**
 * The manifest `field_types` inventory is the downstream registration channel
 * for `#[Waaseyaa\Field\Attribute\FieldType]` plugins (#2786 B1). The compiler
 * records the attribute the plugins actually carry, refuses two plugins
 * claiming one id, and admits plugins from explicitly participating extension
 * packages whose namespace lies outside the ordinary scan boundary.
 */
#[CoversClass(PackageManifestCompiler::class)]
#[CoversClass(FieldTypeManifestCollisionException::class)]
final class PackageManifestCompilerFieldTypesTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid();
        mkdir($this->tempDir . '/vendor/composer', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/composer/autoload_psr4.php', '<?php return [];');
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tempDir);
    }

    #[Test]
    public function compile_records_field_type_plugins_under_their_attribute_id(): void
    {
        $class = ExtensionFieldTypeFixture::declare('compiler_money');
        $this->writeClassmap([$class]);

        $manifest = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();

        self::assertSame($class, $manifest->fieldTypes['compiler_money'] ?? null);
    }

    #[Test]
    public function compile_refuses_two_plugins_claiming_one_id_naming_both(): void
    {
        $first = ExtensionFieldTypeFixture::declare('compiler_collision');
        $second = ExtensionFieldTypeFixture::declare('compiler_collision');
        $this->writeClassmap([$first, $second]);

        try {
            new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();
            self::fail('A silent overwrite would drop one plugin from the inventory.');
        } catch (FieldTypeManifestCollisionException $exception) {
            self::assertStringContainsString('compiler_collision', $exception->getMessage());
            self::assertStringContainsString($first, $exception->getMessage());
            self::assertStringContainsString($second, $exception->getMessage());
        }
    }

    #[Test]
    public function compile_ignores_the_deprecated_foundation_marker(): void
    {
        $class = ExtensionFieldTypeFixture::declareLegacyAsFieldType('compiler_legacy');
        $this->writeClassmap([$class]);

        $manifest = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();

        self::assertArrayNotHasKey('compiler_legacy', $manifest->fieldTypes);
    }

    #[Test]
    public function compile_admits_field_types_from_an_explicitly_participating_extension_package(): void
    {
        $packageRoot = $this->tempDir . '/vendor/acme/money';
        $sourceDir = $packageRoot . '/src';
        mkdir($sourceDir, 0o755, true);
        $pluginPath = $sourceDir . '/MoneyItem.php';
        file_put_contents($pluginPath, <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Acme\Money;
            #[\Waaseyaa\Field\Attribute\FieldType(id: 'acme_money', label: 'Money')]
            final class MoneyItem extends \Waaseyaa\Field\AbstractFieldType
            {
                public static function schema(): array
                {
                    return ['value' => ['type' => 'varchar', 'length' => 32]];
                }

                public static function jsonSchema(): array
                {
                    return ['type' => 'string'];
                }
            }
            PHP);
        require_once $pluginPath;

        $packageComposer = [
            'name' => 'acme/money',
            'autoload' => ['psr-4' => ['Acme\\Money\\' => 'src/']],
            'extra' => ['waaseyaa' => ['providers' => []]],
        ];
        file_put_contents($packageRoot . '/composer.json', json_encode($packageComposer, JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                ...$packageComposer,
                'install-path' => '../acme/money',
            ]]], JSON_THROW_ON_ERROR),
        );
        $this->writeClassmap([]);

        $manifest = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();

        self::assertSame('Acme\\Money\\MoneyItem', $manifest->fieldTypes['acme_money'] ?? null);
    }

    #[Test]
    public function compile_does_not_admit_field_types_from_a_package_that_never_opted_in(): void
    {
        $packageRoot = $this->tempDir . '/vendor/acme/silent';
        $sourceDir = $packageRoot . '/src';
        mkdir($sourceDir, 0o755, true);
        $pluginPath = $sourceDir . '/SilentItem.php';
        file_put_contents($pluginPath, <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Acme\Silent;
            #[\Waaseyaa\Field\Attribute\FieldType(id: 'acme_silent', label: 'Silent')]
            final class SilentItem extends \Waaseyaa\Field\AbstractFieldType
            {
                public static function schema(): array
                {
                    return ['value' => ['type' => 'varchar', 'length' => 32]];
                }

                public static function jsonSchema(): array
                {
                    return ['type' => 'string'];
                }
            }
            PHP);
        require_once $pluginPath;

        $packageComposer = [
            'name' => 'acme/silent',
            'autoload' => ['psr-4' => ['Acme\\Silent\\' => 'src/']],
        ];
        file_put_contents($packageRoot . '/composer.json', json_encode($packageComposer, JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                ...$packageComposer,
                'install-path' => '../acme/silent',
            ]]], JSON_THROW_ON_ERROR),
        );
        $this->writeClassmap([]);

        $manifest = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();

        self::assertArrayNotHasKey('acme_silent', $manifest->fieldTypes);
    }

    /** @param list<class-string> $classes */
    private function writeClassmap(array $classes): void
    {
        $entries = [];
        foreach ($classes as $class) {
            $entries[$class] = ExtensionFieldTypeFixture::fileOf($class);
        }
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return ' . var_export($entries, true) . ';',
        );
    }
}
