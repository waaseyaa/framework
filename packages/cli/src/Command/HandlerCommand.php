<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @api
 */
final class HandlerCommand extends Command
{
    /** @var list<HandlerArgument> */
    private readonly array $arguments;

    /** @var list<HandlerOption> */
    private readonly array $options;

    /**
     * @param list<HandlerArgument> $arguments
     * @param list<HandlerOption> $options
     * @param \Closure|array{class-string, non-empty-string} $handler
     */
    public function __construct(
        string $name,
        string $description,
        private readonly \Closure|array $handler,
        array $arguments = [],
        array $options = [],
        private readonly ?ContainerInterface $container = null,
    ) {
        $this->arguments = $arguments;
        $this->options = $options;

        parent::__construct($name);
        $this->setDescription($description);
    }

    public function sourceClass(): string
    {
        return is_array($this->handler) ? $this->handler[0] : self::class;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'arguments' => $this->arguments,
            'options' => $this->options,
            default => throw new \OutOfBoundsException(sprintf('Unknown command metadata property "%s".', $name)),
        };
    }

    /**
     * @return list<HandlerArgument>
     */
    public function handlerArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return list<HandlerOption>
     */
    public function handlerOptions(): array
    {
        return $this->options;
    }

    public function withContainer(ContainerInterface $container): self
    {
        return new self(
            name: (string) $this->getName(),
            description: $this->getDescription(),
            handler: $this->handler,
            arguments: $this->arguments,
            options: $this->options,
            container: $container,
        );
    }

    protected function configure(): void
    {
        foreach ($this->arguments as $argument) {
            $mode = $argument->mode === HandlerArgumentMode::Required ? InputArgument::REQUIRED : InputArgument::OPTIONAL;
            if ($argument->isArray) {
                $mode |= InputArgument::IS_ARRAY;
            }

            $this->addArgument(
                $argument->name,
                $mode,
                $argument->description,
                $argument->mode === HandlerArgumentMode::Required && !$argument->isArray ? null : $argument->default,
            );
        }

        foreach ($this->options as $option) {
            $this->addOption(
                $option->name,
                $option->shortcut,
                $this->optionMode($option->mode),
                $option->description,
                $option->mode === HandlerOptionMode::None ? null : $option->default,
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyCommandIO(
            input: $input,
            stdout: $output,
            stderr: $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output,
        );

        $handler = $this->resolveHandler();
        return $handler($io);
    }

    /**
     * @return callable(SymfonyCommandIO): int
     */
    private function resolveHandler(): callable
    {
        if ($this->handler instanceof \Closure) {
            return $this->handler;
        }

        if ($this->container === null) {
            throw new \LogicException(sprintf('Command "%s" requires a handler container.', (string) $this->getName()));
        }

        [$fqcn, $method] = $this->handler;
        $instance = $this->container->get($fqcn);
        $callable = [$instance, $method];

        if (!is_callable($callable)) {
            throw new \LogicException(sprintf('Resolved handler "%s::%s" is not callable.', $fqcn, $method));
        }

        return $callable;
    }

    private function optionMode(HandlerOptionMode $mode): int
    {
        return match ($mode) {
            HandlerOptionMode::None => InputOption::VALUE_NONE,
            HandlerOptionMode::Required => InputOption::VALUE_REQUIRED,
            HandlerOptionMode::Optional => InputOption::VALUE_OPTIONAL,
            HandlerOptionMode::Array_ => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            HandlerOptionMode::Negatable => InputOption::VALUE_NEGATABLE,
        };
    }
}
