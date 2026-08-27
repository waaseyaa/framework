<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\ErrorPageRendererInterface;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Http\Router\WaaseyaaContext;
use Waaseyaa\SSR\Http\AppController\Exception\AppControllerTypeMismatchException;
use Waaseyaa\SSR\Http\AppController\Exception\InvalidAppControllerArgumentException;
use Waaseyaa\SSR\Http\AppController\Exception\InvalidAppControllerBindingException;
use Waaseyaa\SSR\SsrPageHandler;

/**
 * Routes app-level controllers registered via ServiceProvider::routes()
 * with the `Class::method` controller string format.
 *
 * @see SsrPageHandler::dispatchAppController()
 */
final class AppControllerRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly SsrPageHandler $ssrPageHandler,
        private readonly ?ErrorPageRendererInterface $errorPageRenderer = null,
        private readonly bool $debug = false,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');

        if (!is_string($controller) || $controller === '') {
            return false;
        }

        if (!str_contains($controller, '::')) {
            return false;
        }
        if (preg_match('/\s/', $controller) === 1) {
            return false;
        }

        [$class, $method] = explode('::', $controller, 2);
        if ($class === '' || $method === '') {
            return false;
        }

        return str_contains($class, '\\') || ctype_upper($class[0]);
    }

    public function handle(Request $request): Response
    {
        $controller = (string) $request->attributes->get('_controller', '');
        $ctx = WaaseyaaContext::fromRequest($request);

        try {
            $result = $this->ssrPageHandler->dispatchAppController(
                $controller,
                $ctx->account,
                $request,
            );
        } catch (ResourceNotFoundException $e) {
            return $this->appControllerErrorResponse(
                $request,
                404,
                'Not Found',
                $e->getMessage(),
            );
        } catch (InvalidAppControllerArgumentException $e) {
            return $this->appControllerErrorResponse(
                $request,
                400,
                'Bad Request',
                $e->getMessage(),
            );
        } catch (AppControllerTypeMismatchException|InvalidAppControllerBindingException $e) {
            return $this->appControllerErrorResponse(
                $request,
                500,
                'Internal Server Error',
                $this->safeExceptionDetail($e),
            );
        }

        if ($result instanceof Response) {
            return $result;
        }

        if ($result['type'] === 'json') {
            /** @var array<string, mixed> $content */
            $content = $result['content'];
            return $this->jsonApiResponse($result['status'], $content, $result['headers']);
        }

        /** @var string $content */
        $content = $result['content'];
        return new Response(
            $content,
            $result['status'],
            array_merge(
                ['Content-Type' => 'text/html; charset=UTF-8'],
                $result['headers'],
            ),
        );
    }

    private function safeExceptionDetail(\Throwable $e): string
    {
        if ($this->debug) {
            return $e->getMessage();
        }

        return 'A server error occurred.';
    }

    private function appControllerErrorResponse(
        Request $request,
        int $status,
        string $title,
        string $detail,
    ): Response {
        $route = $request->attributes->get('_route_object');
        $isRenderRoute = $route instanceof Route && $route->getOption('_render') === true;

        if ($isRenderRoute) {
            return $this->renderHtmlAppError($request, $status, $title, $detail);
        }

        return $this->jsonApiResponse($status, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => (string) $status,
                'title' => $title,
                'detail' => $detail,
            ]],
        ], [
            'Content-Type' => 'application/vnd.api+json',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function renderHtmlAppError(Request $request, int $statusCode, string $title, string $detail): Response
    {
        if ($this->errorPageRenderer !== null) {
            $response = $this->errorPageRenderer->render($statusCode, $title, $detail, $request);
            if ($response !== null) {
                return $response;
            }
        }

        $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $escapedTitle = $esc($title);
        $escapedDetail = $esc($detail);

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$statusCode} {$escapedTitle}</title>
            <style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#111827;color:#F3F4F6}
            .box{text-align:center;max-width:420px;padding:2rem}.code{font-size:4rem;font-weight:700;color:#F59E0B;margin:0}.msg{color:#9CA3AF;margin:1rem 0;line-height:1.6}</style></head>
            <body><div class="box"><p class="code">{$statusCode}</p><h1>{$escapedTitle}</h1><p class="msg">{$escapedDetail}</p></div></body></html>
            HTML;

        return new Response($html, $statusCode, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
