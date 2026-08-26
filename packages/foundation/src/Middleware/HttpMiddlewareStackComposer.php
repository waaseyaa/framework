<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Foundation\Attribute\AsMiddleware;

/** Deterministic exactly-once composition for the kernel HTTP pipeline. */
final class HttpMiddlewareStackComposer
{
    /**
     * @param list<HttpMiddlewareInterface> $builtIns
     * @param list<array{middleware: HttpMiddlewareInterface, provider: class-string}> $providerMiddleware
     * @return list<HttpMiddlewareInterface>
     */
    public function compose(array $builtIns, array $providerMiddleware): array
    {
        /** @var array<class-string, string> $owners */
        $owners = [];
        /** @var list<array{middleware: HttpMiddlewareInterface, priority: int, sequence: int}> $registrations */
        $registrations = [];
        $sequence = 0;

        foreach ($builtIns as $middleware) {
            $this->register($middleware, 'kernel built-ins', $owners, $registrations, $sequence);
        }
        foreach ($providerMiddleware as $registration) {
            $this->register(
                $registration['middleware'],
                $registration['provider'],
                $owners,
                $registrations,
                $sequence,
            );
        }

        usort($registrations, static function (array $left, array $right): int {
            $priorityOrder = $right['priority'] <=> $left['priority'];

            return $priorityOrder !== 0
                ? $priorityOrder
                : $left['sequence'] <=> $right['sequence'];
        });

        return array_map(
            static fn(array $registration): HttpMiddlewareInterface => $registration['middleware'],
            $registrations,
        );
    }

    /**
     * @param array<class-string, string> $owners
     * @param list<array{middleware: HttpMiddlewareInterface, priority: int, sequence: int}> $registrations
     */
    private function register(
        HttpMiddlewareInterface $middleware,
        string $owner,
        array &$owners,
        array &$registrations,
        int &$sequence,
    ): void {
        $class = $middleware::class;
        if (isset($owners[$class])) {
            throw new \LogicException(sprintf(
                'HTTP middleware %s was registered by both %s and %s; each concrete middleware must have exactly one runtime owner.',
                $class,
                $owners[$class],
                $owner,
            ));
        }

        $owners[$class] = $owner;
        $registrations[] = [
            'middleware' => $middleware,
            'priority' => $this->priority($middleware),
            'sequence' => $sequence++,
        ];
    }

    private function priority(HttpMiddlewareInterface $middleware): int
    {
        $attributes = new \ReflectionClass($middleware)->getAttributes(AsMiddleware::class);
        if ($attributes === []) {
            return 0;
        }
        $attribute = $attributes[0]->newInstance();

        return $attribute->pipeline === 'http' ? $attribute->priority : 0;
    }
}
