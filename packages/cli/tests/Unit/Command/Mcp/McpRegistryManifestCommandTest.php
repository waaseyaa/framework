<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\Mcp\McpRegistryManifestCommand;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Mcp\McpImplementationInfo;
use Waaseyaa\Mcp\Registry\McpRegistryManifest;
use Waaseyaa\Mcp\Registry\McpRegistryManifestConfig;

#[CoversClass(McpRegistryManifestCommand::class)]
final class McpRegistryManifestCommandTest extends TestCase
{
    #[Test]
    public function writes_only_the_official_server_json_to_stdout(): void
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

        $tester = $this->runCommand(static fn(): McpRegistryManifest => $manifest);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame($manifest->toJson(), $tester->getStdout());
        self::assertSame('', $tester->getStderr());
    }

    #[Test]
    public function configuration_refusal_goes_to_stderr_and_leaves_stdout_empty(): void
    {
        $tester = $this->runCommand(static function (): McpRegistryManifest {
            throw new ConfigException('mcp.registry.remote_url must be a non-empty string');
        });

        self::assertSame(1, $tester->getExitCode());
        self::assertSame('', $tester->getStdout());
        self::assertStringContainsString('mcp.registry.remote_url', $tester->getStderr());
    }

    /** @param \Closure(): McpRegistryManifest $manifest */
    private function runCommand(\Closure $manifest): CliTester
    {
        $command = new McpRegistryManifestCommand($manifest);
        $definition = new HandlerCommand(
            name: 'mcp:registry-manifest',
            description: 'Emit the official MCP Registry server.json for this deployment.',
            handler: [McpRegistryManifestCommand::class, 'execute'],
        );
        $container = new class ($command) implements ContainerInterface {
            public function __construct(private readonly McpRegistryManifestCommand $command) {}

            public function get(string $id): object
            {
                if ($id === McpRegistryManifestCommand::class) {
                    return $this->command;
                }

                throw new \RuntimeException('Unexpected service: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === McpRegistryManifestCommand::class;
            }
        };

        return CliTester::for($definition, $container)->execute([]);
    }
}
