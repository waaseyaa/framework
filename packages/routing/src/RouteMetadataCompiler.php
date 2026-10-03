<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouteCompiler;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;

/** Compiles immutable declarations without resolving execution targets. @api */
final class RouteMetadataCompiler
{
    public function compile(RouteSnapshot $snapshot): RouteCollection
    {
        $collection = new RouteCollection();
        foreach ($snapshot->routes as $definition) {
            if (array_key_exists('compiler_class', $definition->options) && $definition->options['compiler_class'] !== RouteCompiler::class) {
                throw new RouteCompositionException('unsupported-options', 'Unsupported route compiler for ' . $definition->name . '.');
            }
            if ($definition->condition !== '') {
                try {
                    // Parse only. Symfony evaluates against the request during matching.
                    new ExpressionLanguage()->parse($definition->condition, ['context', 'request', 'params']);
                } catch (SyntaxError) {
                    throw new RouteCompositionException('unsupported-condition', 'Unsupported route condition for ' . $definition->name . '.');
                }
            }
            $route = new Route(
                $definition->path,
                $definition->defaults + ['_controller' => $definition->handler->id],
                $definition->requirements,
                $definition->options + ['_waaseyaa_priority' => $definition->priority],
                $definition->host,
                $definition->schemes,
                $definition->methods,
                $definition->condition,
            );
            // Snapshot admission already refused duplicates. Symfony owns ordering.
            $collection->add($definition->name, $route, $definition->priority);
        }
        return $collection;
    }
}
