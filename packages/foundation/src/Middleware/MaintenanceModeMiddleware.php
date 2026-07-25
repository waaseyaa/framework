<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;
use Waaseyaa\Foundation\Maintenance\MaintenanceStatus;

/**
 * Global quiesce gate: when the maintenance flag is set, short-circuits every
 * request with a branded `503 Service Unavailable` + `Retry-After` (#2122).
 *
 * SINGLE INVOCATION PATH. Although this is a real {@see HttpMiddlewareInterface},
 * it is deliberately NOT annotated with `#[AsMiddleware]` and is NOT added to
 * {@see \Waaseyaa\Foundation\Kernel\HttpKernel::buildMiddlewareStack()}. The
 * post-boot pipeline is assembled only after `boot()` — which runs migrations
 * and schema validation against the database — so a gate wired there could not
 * return a 503 while the database is mid-swap (it would 500 on boot). Instead
 * {@see \Waaseyaa\Foundation\Kernel\HttpKernel} invokes {@see maintenanceResponse()}
 * exactly once, BEFORE boot, so the branded 503 is rendered without touching the
 * database. Omitting `#[AsMiddleware]` guarantees the manifest compiler never
 * discovers it into a pipeline, so the check cannot run twice per request.
 * {@see process()} exists for interface conformance and optional consumer
 * composition. See docs/specs/operations-playbooks.md "Maintenance mode".
 *
 * @api
 */
final class MaintenanceModeMiddleware implements HttpMiddlewareInterface
{
    /** Loopback addresses treated as "operator on the host" when trust is enabled. */
    private const array LOOPBACK_IPS = ['127.0.0.1', '::1'];

    /**
     * @param list<string> $healthPaths exempt health/readiness paths (already normalized)
     */
    public function __construct(
        private readonly MaintenanceState $state,
        private readonly array $healthPaths = [],
        private readonly bool $trustLocalhost = true,
        private readonly ?string $customPagePath = null,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        return $this->maintenanceResponse($request) ?? $next->handle($request);
    }

    /**
     * The 503 to serve, or null to let the request proceed.
     *
     * This is the production entry point, called pre-boot by the kernel gate.
     */
    public function maintenanceResponse(Request $request): ?Response
    {
        $status = $this->state->read();
        if (!$status->active) {
            return null;
        }

        if ($this->isExempt($request)) {
            return null;
        }

        return $this->render($request, $status);
    }

    private function isExempt(Request $request): bool
    {
        // REMOTE_ADDR only — no X-Forwarded-For parsing. It is non-spoofable by
        // remote clients, but IS 127.0.0.1 for every request behind a same-host
        // reverse proxy; such deployments set WAASEYAA_MAINTENANCE_TRUST_LOCALHOST=false.
        if ($this->trustLocalhost && in_array($request->getClientIp(), self::LOOPBACK_IPS, true)) {
            return true;
        }

        $path = $this->normalizePath($request->getPathInfo());
        foreach ($this->healthPaths as $healthPath) {
            if ($path === $this->normalizePath($healthPath)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    private function render(Request $request, MaintenanceStatus $status): Response
    {
        if ($this->wantsJson($request)) {
            $response = new JsonResponse(
                [
                    'jsonapi' => ['version' => '1.1'],
                    'errors' => [
                        [
                            'status' => '503',
                            'title' => 'Service Unavailable',
                            'detail' => $status->message ?? 'The service is temporarily down for maintenance.',
                        ],
                    ],
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        } else {
            $response = new Response(
                $this->htmlBody($status),
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $response->headers->set('Retry-After', (string) $status->retryAfter);
        $response->headers->set('Cache-Control', 'no-store, private');
        // Harden the branded page with the same headers every response carries.
        SecurityHeadersMiddleware::applyResponseDefaults($request, $response, 'DENY');

        return $response;
    }

    private function wantsJson(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json') || str_contains($accept, 'application/vnd.api+json');
    }

    private function htmlBody(MaintenanceStatus $status): string
    {
        if ($this->customPagePath !== null && is_file($this->customPagePath)) {
            $custom = @file_get_contents($this->customPagePath);
            if (is_string($custom) && $custom !== '') {
                return $custom;
            }
        }

        $message = htmlspecialchars(
            $status->message ?? 'We’re performing scheduled maintenance and will be back shortly.',
            ENT_QUOTES,
            'UTF-8',
        );

        // Self-contained branded page — inline CSS only, no external assets, so it
        // renders with zero dependencies during a swap. Deep-Teal auth palette.
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>We’ll be right back</title>
                <style>
                    :root { color-scheme: light dark; }
                    * { box-sizing: border-box; }
                    body {
                        margin: 0;
                        min-height: 100vh;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                        background: radial-gradient(circle at 30% 20%, #0f766e 0%, #0d4f4f 55%, #08312f 100%);
                        color: #ecfeff;
                        padding: 2rem;
                    }
                    .card {
                        max-width: 30rem;
                        text-align: center;
                        background: rgba(8, 49, 47, 0.55);
                        border: 1px solid rgba(20, 184, 166, 0.35);
                        border-radius: 1rem;
                        padding: 2.5rem 2rem;
                        backdrop-filter: blur(6px);
                    }
                    .badge {
                        display: inline-block;
                        width: 3.5rem;
                        height: 3.5rem;
                        line-height: 3.5rem;
                        border-radius: 50%;
                        background: #14b8a6;
                        color: #08312f;
                        font-size: 1.75rem;
                        margin-bottom: 1.25rem;
                    }
                    h1 { margin: 0 0 0.75rem; font-size: 1.6rem; }
                    p { margin: 0; line-height: 1.6; color: #ccfbf1; }
                </style>
            </head>
            <body>
                <main class="card">
                    <div class="badge" aria-hidden="true">&#9881;</div>
                    <h1>We’ll be right back</h1>
                    <p>{$message}</p>
                </main>
            </body>
            </html>
            HTML;
    }
}
