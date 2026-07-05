<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class MakeListenerHandler extends AbstractMakeHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $name = (string) $io->argument('name');
        $event = (string) $io->option('event');
        try {
            $this->validateIdentifier($name, 'name');
            // $event ends up as a bare type-hint token and inside a top-level
            // `use ...;` statement in the generated listener — the FQCN
            // allowlist keeps it to valid namespace segments so it cannot
            // close the parameter list or terminate the use-statement early.
            $this->validateIdentifier($event, 'event', self::FQCN_PATTERN);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $className = $this->toPascalCase($name);

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

        $io->write($rendered);

        if ($io->option('async')) {
            $io->writeln('');
            $io->writeln('// Hint: Register this listener with async dispatch in your service provider.');
        }

        return 0;
    }
}
