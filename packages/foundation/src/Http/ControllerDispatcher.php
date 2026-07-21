<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Foundation\Http\Inertia\InertiaFullPageRendererInterface;
use Waaseyaa\Foundation\Http\Inertia\InertiaPageResultInterface;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Routes a matched controller name to the appropriate domain router.
 *
 * Iterates DomainRouterInterface implementations in a deterministic chain.
 * Callable controllers (closures/invokables from service providers) are
 * handled before the router chain. `_controller` MUST be a `Class::method`
 * string or invokable; array `[Class, method]` defaults are normalized earlier
 * ({@see \Waaseyaa\Routing\RouteBuilder::normalizeControllerDefault()}).
 */
final class ControllerDispatcher
{
    use JsonApiResponseTrait;

    /**
     * @internal String FQN avoids upward layer import (Foundation/L0 must not import
     * from SSR/L6). `::class` on an unimported FQCN does not autoload — this string
     * constant is behaviourally identical to the inline `\Waaseyaa\SSR\SsrPageHandler::class`
     * it replaces (WP7 audit remediation — pattern PL008 was extended to catch).
     */
    private const SSR_PAGE_HANDLER = 'Waaseyaa\\SSR\\SsrPageHandler';

    private readonly LoggerInterface $logger;

    private readonly ?InertiaFullPageRendererInterface $inertiaFullPageRenderer;

    /** @param iterable<DomainRouterInterface> $routers */
    public function __construct(
        private readonly iterable $routers,
        ?LoggerInterface $logger = null,
        ?InertiaFullPageRendererInterface $inertiaFullPageRenderer = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->inertiaFullPageRenderer = $inertiaFullPageRenderer;
    }

    public function dispatch(HttpRequest $request): HttpResponse
    {
        $controller = $request->attributes->get('_controller', '');

        if ($controller === 'render.page' && !\class_exists(self::SSR_PAGE_HANDLER)) {
            return $this->jsonApiResponse(501, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '501',
                    'title' => 'Not Implemented',
                    'detail' => 'SSR rendering is not available. Install waaseyaa/ssr to enable HTML routes.',
                ]],
            ]);
        }

        // Callable controllers (closures/invokables from service providers).
        // Only consider actual invokable values here — a Class::method string
        // for an instance method is *not* callable without an instance, and
        // must be routed through the domain router chain (AppControllerRouter).
        if ($controller instanceof \Closure || (is_object($controller) && is_callable($controller))) {
            try {
                return $this->handleCallable($controller, $request);
            } catch (\Throwable $e) {
                return $this->handleException($e);
            }
        }

        try {
            foreach ($this->routers as $router) {
                if ($router->supports($request)) {
                    return $router->handle($request);
                }
            }
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }

        $this->logger->warning(sprintf('Unknown controller: %s', $controller));

        return $this->jsonApiResponse(404, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => '404',
                'title' => 'Not Found',
                'detail' => sprintf("No router supports controller '%s'.", $controller),
            ]],
        ]);
    }

    private function handleCallable(callable $controller, HttpRequest $request): HttpResponse
    {
        $params = $request->attributes->all();
        $routeParams = array_filter($params, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
        $result = $controller($request, ...$routeParams);

        if ($result instanceof InertiaPageResultInterface) {
            $pageObject = $result->toPageObject();
            $pageObject['url'] = $request->getRequestUri();

            if ($request->headers->get('X-Inertia') === 'true') {
                return $this->jsonApiResponse(200, $pageObject, [
                    'X-Inertia' => 'true',
                    'Vary' => 'X-Inertia',
                ]);
            }

            if ($this->inertiaFullPageRenderer === null) {
                return $this->jsonApiResponse(500, [
                    'jsonapi' => ['version' => '1.1'],
                    'errors' => [[
                        'status' => '500',
                        'title' => 'Internal Server Error',
                        'detail' => 'Inertia full-page renderer is not configured.',
                    ]],
                ]);
            }

            return $this->htmlResponse(200, $this->inertiaFullPageRenderer->render($pageObject));
        }
        if ($result instanceof RedirectResponse
            && $request->headers->get('X-Inertia') === 'true'
            && in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)
        ) {
            $result->setStatusCode(303);
            return $result;
        }
        if ($result instanceof HttpResponse) {
            return $result;
        }
        if (is_array($result)) {
            return $this->jsonApiResponse($result['statusCode'] ?? 200, $result['body'] ?? $result);
        }

        return $this->jsonApiResponse(200, ['data' => $result]);
    }

    private function handleException(\Throwable $e): HttpResponse
    {
        $this->logger->critical(sprintf(
            "Unhandled exception: %s in %s:%d\n%s",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        ));

        $error = [
            'status' => '500',
            'title' => 'Internal Server Error',
            'detail' => 'An unexpected error occurred.',
        ];

        return $this->jsonApiResponse(500, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [$error],
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function htmlResponse(int $status, string $html, array $headers = []): HttpResponse
    {
        return new HttpResponse($html, $status, array_merge(
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $headers,
        ));
    }
}
