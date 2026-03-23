<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;

#[AsMiddleware(pipeline: 'http', priority: 90)]
final class CompressionMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly int $minimumSize = 1024,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        $acceptEncoding = $request->headers->get('Accept-Encoding', '');
        if (!str_contains($acceptEncoding, 'gzip')) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || strlen($content) < $this->minimumSize) {
            return $response;
        }

        // Don't compress if already encoded.
        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        $compressed = gzencode($content);
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->headers->set('Vary', 'Accept-Encoding');
        $response->headers->remove('Transfer-Encoding');

        return $response;
    }
}
