<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

/**
 * Optional thin base for controllers that prefer protected redirect helpers.
 *
 * Plain controllers remain first-class and may inject {@see Redirector}
 * directly. This base deliberately exposes no container or service-locator
 * conveniences.
 *
 * @api
 */
abstract class Controller
{
    public function __construct(
        private readonly Redirector $redirector,
    ) {}

    /** @param array<string, string> $headers */
    final protected function redirect(
        string $path,
        int $status = 302,
        array $headers = [],
    ): RedirectResponse {
        return $this->redirector->to($path, $status, $headers);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, string> $headers
     */
    final protected function redirectToRoute(
        string $name,
        array $parameters = [],
        int $status = 302,
        array $headers = [],
    ): RedirectResponse {
        return $this->redirector->toRoute($name, $parameters, $status, $headers);
    }
}
