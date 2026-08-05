<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\ContentSearchController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Http\Router\WaaseyaaContext;
use Waaseyaa\Foundation\Log\LoggerInterface;

/** Dispatches the optional public content-search endpoint. */
final class ContentSearchApiRouter implements DomainRouterInterface
{
    public const string CONTROLLER = ContentSearchController::class . '::search';

    public function __construct(
        ContentSearchController|\Closure $controller,
        private readonly ?\Closure $loggerResolver = null,
    ) {
        $this->controllerResolver = $controller instanceof ContentSearchController
            ? static fn(): ContentSearchController => $controller
            : $controller;
    }

    /** @var \Closure(): ContentSearchController */
    private readonly \Closure $controllerResolver;

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller') === self::CONTROLLER;
    }

    public function handle(Request $request): Response
    {
        try {
            $context = WaaseyaaContext::fromRequest($request);
        } catch (\LogicException) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '500',
                    'title' => 'Internal Server Error',
                    'detail' => 'The authorization context is unavailable.',
                ]],
            ], 500, [
                'Content-Type' => 'application/vnd.api+json',
                'Cache-Control' => 'no-store',
            ]);
        }

        try {
            $controller = ($this->controllerResolver)();
            if (!$controller instanceof ContentSearchController) {
                throw new \RuntimeException('The content search controller resolver returned an invalid service.');
            }
        } catch (\Throwable $exception) {
            $correlationId = bin2hex(random_bytes(8));
            $logger = null;
            try {
                $candidate = $this->loggerResolver !== null ? ($this->loggerResolver)() : null;
                $logger = $candidate instanceof LoggerInterface ? $candidate : null;
            } catch (\Throwable) {
                // Logging is diagnostic only; its failure cannot expose details
                // or turn an unavailable protected service into an allowed call.
            }
            $logger?->error('Public content search wiring failed.', [
                'correlation_id' => $correlationId,
                'exception_class' => $exception::class,
            ]);

            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '503',
                    'title' => 'Service Unavailable',
                    'detail' => 'Search is temporarily unavailable.',
                    'meta' => ['correlation_id' => $correlationId],
                ]],
            ], 503, [
                'Content-Type' => 'application/vnd.api+json',
                'Cache-Control' => 'no-store',
            ]);
        }

        return $controller->search($request, $context->principal);
    }
}
