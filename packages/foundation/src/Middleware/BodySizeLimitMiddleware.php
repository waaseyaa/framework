<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Http\Refusal\HttpRefusal;
use Waaseyaa\Foundation\Http\Refusal\RefusalEnvelope;

#[AsMiddleware(pipeline: 'http', priority: 70)]
final class BodySizeLimitMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly int $maxBytes = 1_048_576,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $contentLength = $request->headers->get('Content-Length');

        // Fast-path: reject before reading the body when the declared length already exceeds the cap.
        // Only a digit-only header is a declared length — PHP's `(int)` cast would otherwise
        // treat `2000000abc` as 2000000 and impersonate an oversize refusal on a JSON-RPC
        // route whose transport guard would have answered `-32600` Invalid Content-Length.
        if (
            $contentLength !== null
            && preg_match('/^\d+$/D', $contentLength) === 1
            && (int) $contentLength > $this->maxBytes
        ) {
            return $this->payloadTooLargeResponse($request);
        }

        // Backstop: enforce the cap against the actual body to close the chunked /
        // no-Content-Length bypass and to catch lying / understated headers.
        if (strlen($request->getContent()) > $this->maxBytes) {
            return $this->payloadTooLargeResponse($request);
        }

        return $next->handle($request);
    }

    /**
     * Refuse in the vocabulary the matched route advertises.
     *
     * The cap itself is unchanged — only the envelope is negotiated. A JSON:API
     * document stays the default and remains right for the framework's own
     * endpoints. It is the wrong document for an endpoint that advertises a
     * different transport: this middleware runs ahead of every controller, so
     * before the seam existed its 413 shadowed the MCP endpoint's own
     * oversize-body JSON-RPC refusal and handed a JSON-RPC client a shape it
     * could not interpret (#2594).
     */
    private function payloadTooLargeResponse(Request $request): Response
    {
        return RefusalEnvelope::forRequest($request)->refuse(new HttpRefusal(
            status: Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            reason: RefusalEnvelope::REASON_PAYLOAD_TOO_LARGE,
            title: 'Payload Too Large',
            transportMessage: 'Request body exceeds maximum size',
            transportData: ['max_request_bytes' => $this->maxBytes],
        ));
    }
}
