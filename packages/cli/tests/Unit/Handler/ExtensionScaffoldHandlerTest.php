<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\ExtensionScaffoldHandler;
use Waaseyaa\CLI\Provider\OtherScaffoldsServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(ExtensionScaffoldHandler::class)]
final class ExtensionScaffoldHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new OtherScaffoldsServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:extension') {
                return $cmd;
            }
        }

        throw new \RuntimeException('scaffold:extension command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === ExtensionScaffoldHandler::class) {
                    return new ExtensionScaffoldHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === ExtensionScaffoldHandler::class;
            }
        };
    }

    #[Test]
    public function itGeneratesDeterministicExtensionSdkScaffoldJson(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=knowledge_tooling_example',
            '--label=Knowledge Tooling Example',
            '--package=acme/knowledge-extension',
            '--class=KnowledgeToolingExtension',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('knowledge_tooling_example', $decoded['extension_sdk']['plugin']['id']);
        self::assertSame('Acme\\Knowledge\\Extension', $decoded['extension_sdk']['package']['namespace']);
        self::assertSame('KnowledgeToolingExtension', $decoded['extension_sdk']['package']['class']);
        self::assertSame(
            'Waaseyaa\\Plugin\\Extension\\KnowledgeToolingExtensionInterface',
            $decoded['extension_sdk']['contracts']['interface'],
        );

        $files = array_keys($decoded['extension_sdk']['files']);
        self::assertSame(['README.md', 'composer.json', 'src/KnowledgeToolingExtension.php'], $files);
        self::assertStringContainsString('knowledge_tooling_example', $decoded['extension_sdk']['files']['README.md']);
        self::assertStringContainsString('KnowledgeToolingExtensionInterface', $decoded['extension_sdk']['files']['src/KnowledgeToolingExtension.php']);
    }

    #[Test]
    public function itReturnsErrorForMalformedPackageName(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=knowledge_tooling_example',
            '--label=Knowledge Tooling Example',
            '--package=Acme/Invalid',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('--package must match composer format', $tester->getStderr());
    }

    #[Test]
    public function itReturnsErrorForMissingRequiredOptions(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute(['--id=test_plugin']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('required', $tester->getStderr());
    }
}
