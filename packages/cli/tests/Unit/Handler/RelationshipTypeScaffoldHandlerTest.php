<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\RelationshipTypeScaffoldHandler;
use Waaseyaa\CLI\Provider\OtherScaffoldsServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(RelationshipTypeScaffoldHandler::class)]
final class RelationshipTypeScaffoldHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new OtherScaffoldsServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:relationship') {
                return $cmd;
            }
        }

        throw new \RuntimeException('scaffold:relationship command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === RelationshipTypeScaffoldHandler::class) {
                    return new RelationshipTypeScaffoldHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === RelationshipTypeScaffoldHandler::class;
            }
        };
    }

    #[Test]
    public function itGeneratesDeterministicRelationshipTypeScaffoldJson(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=authored_by',
            '--label=Authored by',
            '--directionality=directed',
            '--inverse=author_of',
            '--default-status=1',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('authored_by', $decoded['relationship_type']['id']);
        self::assertSame('Authored by', $decoded['relationship_type']['label']);
        self::assertSame('directed', $decoded['relationship_type']['directionality']);
        self::assertSame('author_of', $decoded['relationship_type']['inverse']);
        self::assertSame(1, $decoded['relationship_type']['default_status']);
    }

    #[Test]
    public function itNullsInverseWhenEmpty(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=tagged_with',
            '--label=Tagged with',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($decoded['relationship_type']['inverse']);
    }

    #[Test]
    public function itReturnsErrorWhenIdMissing(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute(['--label=Some Label']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('required', $tester->getStderr());
    }

    #[Test]
    public function itReturnsErrorForInvalidDirectionality(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=test',
            '--label=Test',
            '--directionality=invalid',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('directionality', $tester->getStderr());
    }
}
