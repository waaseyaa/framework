<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Mcp\McpImplementationInfo;
use Waaseyaa\Mcp\Registry\McpRegistryManifest;
use Waaseyaa\Mcp\Registry\McpRegistryManifestConfig;

#[CoversClass(McpRegistryManifest::class)]
#[CoversClass(McpRegistryManifestConfig::class)]
final class McpRegistryManifestTest extends TestCase
{
    #[Test]
    public function emits_only_the_pinned_official_remote_server_shape(): void
    {
        $manifest = new McpRegistryManifest(
            McpRegistryManifestConfig::fromArray([
                'name' => 'io.github.waaseyaa/framework',
                'description' => 'Access-controlled CMS content and editorial tools',
                'remote_url' => 'https://cms.example/mcp',
                'repository_url' => 'https://github.com/waaseyaa/framework',
                'website_url' => 'https://waaseyaa.org',
            ]),
            new McpImplementationInfo('Waaseyaa', '0.1.0-alpha.286'),
        );

        self::assertSame([
            '$schema' => 'https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json',
            'name' => 'io.github.waaseyaa/framework',
            'title' => 'Waaseyaa',
            'description' => 'Access-controlled CMS content and editorial tools',
            'version' => '0.1.0-alpha.286',
            'repository' => [
                'url' => 'https://github.com/waaseyaa/framework',
                'source' => 'github',
            ],
            'websiteUrl' => 'https://waaseyaa.org',
            'remotes' => [[
                'type' => 'streamable-http',
                'url' => 'https://cms.example/mcp',
            ]],
        ], $manifest->toArray());
    }

    #[Test]
    public function refuses_to_invent_a_remote_endpoint(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mcp.registry.remote_url');

        McpRegistryManifestConfig::fromArray([
            'name' => 'io.github.waaseyaa/framework',
            'description' => 'CMS server',
        ]);
    }

    #[Test]
    public function refuses_to_publish_an_unknown_implementation_version(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mcp.implementation.version');

        new McpRegistryManifest(
            McpRegistryManifestConfig::fromArray([
                'name' => 'io.github.waaseyaa/framework',
                'description' => 'CMS server',
                'remote_url' => 'https://cms.example/mcp',
            ]),
            new McpImplementationInfo('Waaseyaa', '0.0.0+unknown'),
        );
    }

    #[Test]
    public function official_registry_remote_must_be_public_https(): void
    {
        foreach ([
            'http://cms.example/mcp',
            'http://localhost/mcp',
            'https://[::1]/mcp',
            'https://[fd00::1]/mcp',
            'https://[fe80::1]/mcp',
            'https://cms.example/mcp?token=secret',
            'https://cms.example/mcp#fragment',
            'https://user:password@cms.example/mcp',
            '/mcp',
        ] as $url) {
            try {
                McpRegistryManifestConfig::fromArray([
                    'name' => 'io.github.waaseyaa/framework',
                    'description' => 'CMS server',
                    'remote_url' => $url,
                ]);
                self::fail($url . ' must not be publishable.');
            } catch (ConfigException $e) {
                self::assertStringContainsString('mcp.registry.remote_url', $e->getMessage());
                self::assertStringNotContainsString($url, $e->getMessage());
            }
        }
    }

    #[Test]
    public function registry_name_must_use_the_official_namespaced_shape(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mcp.registry.name');

        McpRegistryManifestConfig::fromArray([
            'name' => 'Waaseyaa',
            'description' => 'CMS server',
            'remote_url' => 'https://cms.example/mcp',
        ]);
    }
}
