<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\AiVerifyCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Testing\CliTester;

final class AiVerifyCommandTest extends TestCase
{
    #[Test]
    public function callbackOwnsExitAndOutputWhileTransportNormalizesOptions(): void
    {
        $handler = new AiVerifyCommand(static function (?string $root, ?array $clients): array {
            self::assertSame(realpath((string) getcwd()), $root);
            self::assertSame(['claude', 'cursor'], $clients);

            return ['exit_code' => 1, 'json' => '{"status":"failed"}', 'lines' => ['verification refused']];
        });
        $definition = new HandlerCommand(
            name: 'ai:verify',
            description: 'Test transport',
            options: [
                new HandlerOption(name: 'client', mode: HandlerOptionMode::Array_),
                new HandlerOption(name: 'json', mode: HandlerOptionMode::None),
            ],
            handler: static fn(SymfonyCommandIO $io): int => $handler->execute($io),
        );
        $tester = CliTester::for($definition, self::createStub(\Psr\Container\ContainerInterface::class));
        $tester->execute(['--client=CURSOR,claude,cursor', '--json']);
        self::assertSame(1, $tester->getExitCode());
        self::assertSame('{"status":"failed"}' . PHP_EOL, $tester->getOutput());
        $tester->execute(['--client=cursor,claude']);
        self::assertSame(1, $tester->getExitCode());
        self::assertSame('verification refused' . PHP_EOL, $tester->getOutput());
    }
}
