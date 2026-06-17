<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class EventListHandler
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $listeners = $this->dispatcher->getListeners();

        if ($listeners === []) {
            $io->writeln('No events registered.');

            return 0;
        }

        $io->writeln(sprintf('%-50s %-60s %s', 'Event', 'Listener', 'Priority'));
        $io->writeln(str_repeat('-', 120));

        foreach ($listeners as $eventName => $eventListeners) {
            $eventNameStr = (string) $eventName;
            foreach ($eventListeners as $listener) {
                $priority = $this->dispatcher->getListenerPriority($eventNameStr, $listener) ?? 0;

                $io->writeln(sprintf(
                    '%-50s %-60s %s',
                    $eventNameStr,
                    $this->formatListener($listener),
                    (string) $priority,
                ));
            }
        }

        return 0;
    }

    private function formatListener(callable $listener): string
    {
        if ($listener instanceof \Closure) {
            return 'Closure';
        }

        if (is_array($listener)) {
            $class = is_object($listener[0]) ? $listener[0]::class : $listener[0];

            return $class . '::' . $listener[1];
        }

        if (is_object($listener)) {
            return $listener::class . '::__invoke';
        }

        if (is_string($listener)) {
            return $listener;
        }

        return 'Unknown';
    }
}
