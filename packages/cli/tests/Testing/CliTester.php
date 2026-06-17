<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Testing;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;

final class CliTester
{
    private CommandTester $tester;
    private int $exitCode = 0;

    private function __construct(
        private readonly HandlerCommand $command,
        private readonly ?object $stdin = null,
    ) {
        $this->tester = new CommandTester($command);
    }

    public static function for(HandlerCommand $definition, ContainerInterface $container, ?object $stdin = null): self
    {
        return new self($definition->withContainer($container), $stdin);
    }

    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): self
    {
        $this->tester = new CommandTester($this->command);
        $this->applyInputs();
        $this->exitCode = $this->tester->execute(
            $this->argvToInput($argv),
            [
                'capture_stderr_separately' => true,
                'interactive' => $this->stdin?->isInteractive() ?? false,
            ],
        );

        return $this;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    public function executeMap(array $inputs): self
    {
        $this->tester = new CommandTester($this->command);
        $this->applyInputs();
        $this->exitCode = $this->tester->execute(
            $inputs,
            [
                'capture_stderr_separately' => true,
                'interactive' => $this->stdin?->isInteractive() ?? false,
            ],
        );

        return $this;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getStdout(): string
    {
        return $this->tester->getDisplay();
    }

    public function getStderr(): string
    {
        return $this->tester->getErrorOutput();
    }

    public function getOutput(): string
    {
        return $this->getStdout() . $this->getStderr();
    }

    /**
     * @param list<string> $argv
     * @return array<string, mixed>
     */
    private function argvToInput(array $argv): array
    {
        $input = [];
        $positionals = [];

        for ($i = 0, $count = count($argv); $i < $count; $i++) {
            $token = $argv[$i];
            if (str_starts_with($token, '--')) {
                $raw = substr($token, 2);
                $value = true;
                if (str_contains($raw, '=')) {
                    [$raw, $value] = explode('=', $raw, 2);
                } elseif (($option = $this->findOption($raw)) !== null && $option->mode !== HandlerOptionMode::None) {
                    $value = $argv[++$i] ?? null;
                }

                $key = '--' . $raw;
                $option = $this->findOption($raw);
                if ($option?->mode === HandlerOptionMode::Array_) {
                    $input[$key] ??= [];
                    $input[$key][] = $value;
                } else {
                    $input[$key] = $value;
                }
                continue;
            }

            $positionals[] = $token;
        }

        $arguments = $this->command->handlerArguments();
        foreach ($arguments as $index => $argument) {
            $input[$argument->name] = $argument->isArray
                ? array_slice($positionals, $index)
                : ($positionals[$index] ?? null);
        }

        return array_filter(
            $input,
            static fn(mixed $value): bool => $value !== null,
        );
    }

    private function findOption(string $name): ?HandlerOption
    {
        foreach ($this->command->handlerOptions() as $option) {
            if ($option->name === $name) {
                return $option;
            }
        }

        return null;
    }

    private function applyInputs(): void
    {
        if ($this->stdin === null || !$this->stdin->isInteractive()) {
            return;
        }

        $lines = [];
        while (($line = $this->stdin->readLine()) !== null) {
            $lines[] = $line;
        }
        $this->tester->setInputs($lines);
    }
}
