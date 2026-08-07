<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Config\Config;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\SSR\Twig\WaaseyaaExtension;

#[CoversClass(WaaseyaaExtension::class)]
final class WaaseyaaExtensionTest extends TestCase
{
    // ---------------------------------------------------------------
    // asset()
    // ---------------------------------------------------------------

    #[Test]
    public function asset_prepends_base_path(): void
    {
        $twig = $this->createTwig('{{ asset("css/app.css") }}', basePath: '/build');

        $this->assertSame('/build/css/app.css', $twig->render('test'));
    }

    #[Test]
    public function asset_normalizes_double_slashes(): void
    {
        $twig = $this->createTwig('{{ asset("/js/main.js") }}', basePath: '/build/');

        $this->assertSame('/build/js/main.js', $twig->render('test'));
    }

    #[Test]
    public function asset_works_with_empty_base_path(): void
    {
        $twig = $this->createTwig('{{ asset("img/logo.png") }}', basePath: '');

        $this->assertSame('/img/logo.png', $twig->render('test'));
    }

    // ---------------------------------------------------------------
    // config()
    // ---------------------------------------------------------------

    #[Test]
    public function config_reads_value_from_factory(): void
    {
        $configFactory = $this->createMockConfigFactory('site', 'name', 'My Site');
        $twig = $this->createTwig('{{ config("site.name") }}', configFactory: $configFactory);

        $this->assertSame('My Site', $twig->render('test'));
    }

    #[Test]
    public function config_returns_empty_string_for_missing_key(): void
    {
        $configFactory = $this->createMockConfigFactory('site', 'nonexistent', null);
        $twig = $this->createTwig('{{ config("site.nonexistent") }}', configFactory: $configFactory);

        $this->assertSame('', $twig->render('test'));
    }

    #[Test]
    public function config_handles_nested_dot_notation(): void
    {
        $configFactory = $this->createMockConfigFactory('mail', 'smtp.host', 'localhost');
        $twig = $this->createTwig('{{ config("mail.smtp.host") }}', configFactory: $configFactory);

        $this->assertSame('localhost', $twig->render('test'));
    }


    // ---------------------------------------------------------------
    // env()
    // ---------------------------------------------------------------

    #[Test]
    public function env_returns_whitelisted_variable(): void
    {
        $twig = $this->createTwig(
            '{{ env("APP_ENV") }}',
            envWhitelist: ['APP_ENV'],
            envValues: ['APP_ENV' => 'production'],
        );

        $this->assertSame('production', $twig->render('test'));
    }

    #[Test]
    public function env_returns_empty_string_for_non_whitelisted_variable(): void
    {
        $twig = $this->createTwig(
            '{{ env("DB_PASSWORD") }}',
            envWhitelist: ['APP_ENV'],
            envValues: ['DB_PASSWORD' => 'secret123'],
        );

        $this->assertSame('', $twig->render('test'));
    }

    #[Test]
    public function env_returns_default_when_variable_not_set(): void
    {
        $twig = $this->createTwig(
            '{{ env("APP_ENV", "dev") }}',
            envWhitelist: ['APP_ENV'],
            envValues: [],
        );

        $this->assertSame('dev', $twig->render('test'));
    }

    #[Test]
    public function env_returns_empty_string_for_empty_whitelist(): void
    {
        $twig = $this->createTwig(
            '{{ env("APP_ENV") }}',
            envWhitelist: [],
            envValues: ['APP_ENV' => 'production'],
        );

        $this->assertSame('', $twig->render('test'));
    }

    // ---------------------------------------------------------------
    // Extension registration
    // ---------------------------------------------------------------

    #[Test]
    public function extension_registers_only_asset_and_env_when_no_config_factory(): void
    {
        $extension = new WaaseyaaExtension();
        $names = array_map(fn($f) => $f->getName(), $extension->getFunctions());

        $this->assertContains('asset', $names);
        $this->assertContains('env', $names);
        $this->assertNotContains('config', $names, 'config() must not be registered when no factory is wired — it always returned "" and was misleading dead code.');
        $this->assertCount(2, $extension->getFunctions());
    }

    #[Test]
    public function extension_registers_config_function_when_factory_is_provided(): void
    {
        $factory = $this->createStub(ConfigFactoryInterface::class);
        $extension = new WaaseyaaExtension(configFactory: $factory);
        $names = array_map(fn($f) => $f->getName(), $extension->getFunctions());

        $this->assertContains('config', $names);
        $this->assertCount(3, $extension->getFunctions());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param string[] $envWhitelist
     * @param array<string, string> $envValues
     */
    private function createTwig(
        string $template,
        string $basePath = '',
        ?ConfigFactoryInterface $configFactory = null,
        array $envWhitelist = [],
        array $envValues = [],
    ): Environment {
        $twig = new Environment(new ArrayLoader(['test' => $template]));
        $twig->addExtension(new WaaseyaaExtension(
            assetBasePath: $basePath,
            configFactory: $configFactory,
            envWhitelist: $envWhitelist,
            envResolver: fn(string $name): string|false => $envValues[$name] ?? false,
        ));
        return $twig;
    }

    private function createMockConfigFactory(string $configName, string $key, mixed $value): ConfigFactoryInterface
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->expects(self::once())->method('get')->with($key)->willReturn($value);

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->expects(self::once())->method('get')->with($configName)->willReturn($config);

        return $factory;
    }
}
