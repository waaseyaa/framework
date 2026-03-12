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

    #[Test]
    public function config_returns_empty_string_when_no_factory(): void
    {
        $twig = $this->createTwig('{{ config("site.name") }}', configFactory: null);

        $this->assertSame('', $twig->render('test'));
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
    public function extension_registers_three_functions(): void
    {
        $extension = new WaaseyaaExtension();
        $functions = $extension->getFunctions();

        $names = array_map(fn($f) => $f->getName(), $functions);
        $this->assertContains('asset', $names);
        $this->assertContains('config', $names);
        $this->assertContains('env', $names);
        $this->assertCount(3, $functions);
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
        $config->method('get')->with($key)->willReturn($value);

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->with($configName)->willReturn($config);

        return $factory;
    }
}
