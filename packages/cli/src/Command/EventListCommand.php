<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Lists all registered events and their listeners.
 */
#[AsCommand(
    name: 'event:list',
    description: 'List all registered events and listeners',
)]
final class EventListCommand extends Command
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $listeners = $this->dispatcher->getListeners();

        if ($listeners === []) {
            $output->writeln('No events registered.');

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Event', 'Listener', 'Priority']);

        foreach ($listeners as $eventName => $eventListeners) {
            foreach ($eventListeners as $listener) {
                $priority = 0;

                // Retrieve priority from the dispatcher if available.
                if (method_exists($this->dispatcher, 'getListenerPriority')) {
                    $priority = $this->dispatcher->getListenerPriority($eventName, $listener) ?? 0;
                }

                $table->addRow([
                    $eventName,
                    $this->formatListener($listener),
                    (string) $priority,
                ]);
            }
        }

        $table->render();

        return self::SUCCESS;
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
