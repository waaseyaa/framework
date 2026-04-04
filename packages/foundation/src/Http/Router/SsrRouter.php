<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\SSR\SsrPageHandler;

final class SsrRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly SsrPageHandler $ssrPageHandler,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'render.page';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();
        $requestedViewMode = is_string($ctx->query['view_mode'] ?? null)
            ? trim((string) $ctx->query['view_mode'])
            : 'full';

        $result = $this->ssrPageHandler->handleRenderPage(
            (string) ($params['path'] ?? '/'),
            $ctx->account,
            $request,
            $requestedViewMode,
        );

        if ($result['type'] === 'json') {
            return $this->jsonApiResponse($result['status'], $result['content'], $result['headers']);
        }

        return new Response($result['content'], $result['status'], array_merge(
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $result['headers'],
        ));
    }
}
