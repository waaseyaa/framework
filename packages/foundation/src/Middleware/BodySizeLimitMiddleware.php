<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;

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
        if ($contentLength !== null && (int) $contentLength > $this->maxBytes) {
            return $this->payloadTooLargeResponse();
        }

        // Backstop: enforce the cap against the actual body to close the chunked /
        // no-Content-Length bypass and to catch lying / understated headers.
        if (strlen($request->getContent()) > $this->maxBytes) {
            return $this->payloadTooLargeResponse();
        }

        return $next->handle($request);
    }

    private function payloadTooLargeResponse(): JsonResponse
    {
        return new JsonResponse(
            [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [
                    [
                        'status' => '413',
                        'title' => 'Payload Too Large',
                    ],
                ],
            ],
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        );
    }
}
