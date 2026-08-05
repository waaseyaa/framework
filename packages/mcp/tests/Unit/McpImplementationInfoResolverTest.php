<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Mcp\McpImplementationInfo;
use Waaseyaa\Mcp\McpImplementationInfoResolver;

#[CoversClass(McpImplementationInfo::class)]
#[CoversClass(McpImplementationInfoResolver::class)]
final class McpImplementationInfoResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa-mcp-identity-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/VERSION');
        @unlink($this->root . '/composer.json');
        @rmdir($this->root);
    }

    #[Test]
    public function explicit_config_is_the_highest_precedence_identity(): void
    {
        $info = new McpImplementationInfoResolver('0.1.0-alpha.285')->resolve($this->root, [
            'mcp' => ['implementation' => ['name' => 'Sheg CMS', 'version' => '9.4.1']],
        ]);

        self::assertSame(['name' => 'Sheg CMS', 'version' => '9.4.1'], $info->toArray());
    }

    #[Test]
    public function framework_checkout_uses_its_release_managed_version_file(): void
    {
        file_put_contents($this->root . '/composer.json', '{"name":"waaseyaa/framework"}');
        file_put_contents($this->root . '/VERSION', "0.1.0-alpha.286\n");

        $info = new McpImplementationInfoResolver('dev-main')->resolve($this->root, []);

        self::assertSame('0.1.0-alpha.286', $info->version);
    }

    #[Test]
    public function consuming_project_uses_the_installed_mcp_package_version_not_its_own_version_file(): void
    {
        file_put_contents($this->root . '/composer.json', '{"name":"example/site"}');
        file_put_contents($this->root . '/VERSION', "site-42\n");

        $info = new McpImplementationInfoResolver('v0.1.0-alpha.286')->resolve($this->root, []);

        self::assertSame('0.1.0-alpha.286', $info->version);
    }

    #[Test]
    public function malformed_identity_config_fails_instead_of_guessing(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mcp.implementation.version');

        new McpImplementationInfoResolver(null)->resolve($this->root, [
            'mcp' => ['implementation' => ['version' => '']],
        ]);
    }
}
