<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;

#[AsMiddleware(pipeline: 'http', priority: 100)]
final class SecurityHeadersMiddleware implements HttpMiddlewareInterface
{
    /**
     * Embed-exemption request attribute (#1651). A route that renders content
     * meant to be framed cross-origin (e.g. a workspace document-preview rail)
     * sets this attribute to `true`; {@see applyResponseDefaults()} then omits
     * the `X-Frame-Options` header for that response so the frame is permitted.
     * Same-origin previews need no exemption — `SAMEORIGIN` already allows them.
     */
    public const string FRAME_EXEMPT_ATTRIBUTE = '_frame_exempt';

    public function __construct(
        private readonly string $csp = "default-src 'self'",
        private readonly bool $hstsEnabled = true,
        private readonly int $hstsMaxAge = 31_536_000,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->csp);
        }

        if (!$response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        if (!$response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        if ($this->hstsEnabled && !$response->headers->has('Strict-Transport-Security')) {
            $response->headers->set(
                'Strict-Transport-Security',
                sprintf('max-age=%d; includeSubDomains', $this->hstsMaxAge),
            );
        }

        return $response;
    }

    /**
     * Apply the framing / MIME-sniffing security headers to the FINAL,
     * post-dispatch response (#1651).
     *
     * Why a static helper and not the pipeline: this middleware's `process()`
     * runs inside the kernel's authorization pipeline, whose inner handler
     * returns a stub empty `200` — so a pipeline middleware decorates the stub,
     * never the controller's real response. (`CsrfMiddleware::attachCookieIfHtml`
     * exists for the same reason.) `HttpKernel` therefore calls this on the
     * dispatched response. Before this, `SecurityHeadersMiddleware` was compiled
     * into the middleware manifest but never instantiated, so every response was
     * frameable — the spec/kernel drift #1651 documented.
     *
     * Scope — the two headers safe to apply to every response:
     *  - `X-Frame-Options` (`$frameOptions`, default `SAMEORIGIN`): blocks
     *    cross-origin framing (clickjacking) while PRESERVING same-origin inline
     *    previews; OMITTED when the matched route set
     *    {@see FRAME_EXEMPT_ATTRIBUTE} (cross-origin embed routes opt out).
     *  - `X-Content-Type-Options: nosniff`.
     *
     * CSP and HSTS are deliberately NOT applied here: `default-src 'self'` breaks
     * the admin SPA and HSTS needs HTTPS certainty. They remain opt-in via this
     * middleware's constructor for deployments that wire it explicitly.
     *
     * Existing headers are never overwritten — a controller may set a stricter
     * (`DENY`) or looser value.
     */
    public static function applyResponseDefaults(
        Request $request,
        Response $response,
        string $frameOptions = 'SAMEORIGIN',
    ): void {
        $frameExempt = $request->attributes->get(self::FRAME_EXEMPT_ATTRIBUTE) === true;

        if (!$frameExempt && !$response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', $frameOptions);
        }

        if (!$response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }
    }
}
