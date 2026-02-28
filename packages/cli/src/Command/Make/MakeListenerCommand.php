<?php

declare(strict_types=1);

namespace Aurora\CLI\Command\Make;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:listener',
    description: 'Generate an event listener class',
)]
final class MakeListenerCommand extends AbstractMakeCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The listener class name (e.g. "NotifyOnPublish")')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'The event class to listen for', 'object')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Mark the listener as async');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $className = $this->toPascalCase($name);
        $event = $input->getOption('event');

        // Determine if the event is a fully-qualified class name or a short name.
        if (str_contains($event, '\\')) {
            $useStatement = sprintf('use %s;', $event);
            $eventShort = substr($event, strrpos($event, '\\') + 1);
        } else {
            $useStatement = '';
            $eventShort = $event;
        }

        $rendered = $this->renderStub('listener', [
            'class' => $className,
            'event' => $eventShort,
            'use' => $useStatement,
        ]);

        $output->write($rendered);

        if ($input->getOption('async')) {
            $output->writeln('');
            $output->writeln('// Hint: Register this listener with async dispatch in your service provider.');
        }

        return self::SUCCESS;
    }
}
