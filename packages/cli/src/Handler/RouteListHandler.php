<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Routing\WaaseyaaRouter;

final class RouteListHandler
{
    public function __construct(
        private readonly WaaseyaaRouter $router,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $routes = $this->router->getRouteCollection();
        $filterPath = $io->option('path');

        $rows = [];
        foreach ($routes as $name => $route) {
            $routePath = $route->getPath();

            if ($filterPath !== null && !str_contains($routePath, (string) $filterPath)) {
                continue;
            }

            $methods = $route->getMethods();
            $methodStr = $methods === [] ? 'ANY' : implode('|', $methods);

            $rows[] = [$methodStr, $routePath, $name];
        }

        if ($rows === []) {
            $io->writeln('No routes found.');

            return 0;
        }

        // Calculate column widths.
        $w0 = max(6, ...array_map(static fn($r) => strlen($r[0]), $rows));
        $w1 = max(4, ...array_map(static fn($r) => strlen($r[1]), $rows));
        $w2 = max(4, ...array_map(static fn($r) => strlen($r[2]), $rows));

        $io->writeln(sprintf('%-' . $w0 . 's  %-' . $w1 . 's  %s', 'Method', 'Path', 'Name'));
        $io->writeln(str_repeat('-', $w0 + $w1 + $w2 + 4));

        foreach ($rows as $row) {
            $io->writeln(sprintf('%-' . $w0 . 's  %-' . $w1 . 's  %s', $row[0], $row[1], $row[2]));
        }

        return 0;
    }
}
