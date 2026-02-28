<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Aurora\Routing\AuroraRouter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists all registered routes.
 */
#[AsCommand(
    name: 'route:list',
    description: 'List all registered routes',
)]
final class RouteListCommand extends Command
{
    public function __construct(
        private readonly AuroraRouter $router,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Filter routes by path pattern');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->router->getRouteCollection();
        $filterPath = $input->getOption('path');

        $rows = [];
        foreach ($routes as $name => $route) {
            $path = $route->getPath();

            if ($filterPath !== null && !str_contains($path, $filterPath)) {
                continue;
            }

            $methods = $route->getMethods();
            $methodStr = $methods === [] ? 'ANY' : implode('|', $methods);

            $rows[] = [
                $methodStr,
                $path,
                $name,
            ];
        }

        if ($rows === []) {
            $output->writeln('No routes found.');

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Method', 'Path', 'Name']);
        $table->setRows($rows);
        $table->render();

        return self::SUCCESS;
    }
}
