<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakeEntityTypeHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderB;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakeEntityTypeHandler::class)]
final class MakeEntityTypeCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_config_entity_by_default(): void
    {
        $tester = $this->createTester();
        $tester->execute(['event']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('class Event extends ConfigEntityBase', $output);
        $this->assertStringContainsString('use Waaseyaa\\Entity\\ConfigEntityBase;', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
        $this->assertStringContainsString('string $entityTypeId = \'\'', $output);
        $this->assertStringContainsString("'event'", $output);
        $this->assertStringContainsString("'id' => 'id'", $output);
        $this->assertStringContainsString("'label' => 'label'", $output);
    }

    #[Test]
    public function it_generates_a_content_entity_with_flag(): void
    {
        $tester = $this->createTester();
        $tester->execute(['article', '--content']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('final class Article extends ContentEntityBase', $output);
        $this->assertStringContainsString('use Waaseyaa\\Entity\\ContentEntityBase;', $output);
        $this->assertStringContainsString('#[ContentEntityType(', $output);
        $this->assertStringContainsString('#[ContentEntityKeys(', $output);
        $this->assertStringContainsString('#[Field]', $output);
        $this->assertStringContainsString("id: 'article'", $output);
        $this->assertStringContainsString("EntityType::fromClass(Article::class, group: 'content')", $output);
    }

    private function createTester(): CliTester
    {
        $provider = new MakeServiceProviderB();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:entity-type') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MakeEntityTypeHandler::class) {
                    return new MakeEntityTypeHandler();
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakeEntityTypeHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }
}
