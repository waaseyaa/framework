<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\ExtensionScaffoldCommand;

#[CoversClass(ExtensionScaffoldCommand::class)]
final class ExtensionScaffoldCommandTest extends TestCase
{
    #[Test]
    public function itGeneratesDeterministicExtensionSdkScaffoldJson(): void
    {
        $app = new Application();
        $app->add(new ExtensionScaffoldCommand());
        $command = $app->find('scaffold:extension');

        $tester = new CommandTester($command);
        $tester->execute([
            '--id' => 'knowledge_tooling_example',
            '--label' => 'Knowledge Tooling Example',
            '--package' => 'acme/knowledge-extension',
            '--class' => 'KnowledgeToolingExtension',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('knowledge_tooling_example', $decoded['extension_sdk']['plugin']['id']);
        $this->assertSame('Acme\\Knowledge\\Extension', $decoded['extension_sdk']['package']['namespace']);
        $this->assertSame('KnowledgeToolingExtension', $decoded['extension_sdk']['package']['class']);
        $this->assertSame(
            'Waaseyaa\\Plugin\\Extension\\KnowledgeToolingExtensionInterface',
            $decoded['extension_sdk']['contracts']['interface'],
        );

        $files = array_keys($decoded['extension_sdk']['files']);
        $this->assertSame(['README.md', 'composer.json', 'src/KnowledgeToolingExtension.php'], $files);
        $this->assertStringContainsString('knowledge_tooling_example', $decoded['extension_sdk']['files']['README.md']);
        $this->assertStringContainsString('KnowledgeToolingExtensionInterface', $decoded['extension_sdk']['files']['src/KnowledgeToolingExtension.php']);
    }

    #[Test]
    public function itReturnsInvalidForMalformedPackageName(): void
    {
        $app = new Application();
        $app->add(new ExtensionScaffoldCommand());
        $command = $app->find('scaffold:extension');

        $tester = new CommandTester($command);
        $tester->execute([
            '--id' => 'knowledge_tooling_example',
            '--label' => 'Knowledge Tooling Example',
            '--package' => 'Acme/Invalid',
        ]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('--package must match composer format', $tester->getDisplay());
    }
}
