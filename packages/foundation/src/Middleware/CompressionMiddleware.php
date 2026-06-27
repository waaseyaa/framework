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
        $this->appendVaryToken($response, 'Accept-Encoding');
        $response->headers->remove('Transfer-Encoding');

        return $response;
    }

    /**
     * Append a Vary token if it is not already present (case-insensitive).
     *
     * If no Vary header exists, set it to $token.
     * If the existing value is "*", leave it unchanged ("*" already varies on
     * everything; appending would be redundant and malformed).
     * If the token is already listed (any case), leave it unchanged.
     * Otherwise append ", $token" to preserve the original tokens verbatim.
     */
    private function appendVaryToken(Response $response, string $token): void
    {
        $existing = $response->headers->get('Vary');
        if ($existing === null) {
            $response->headers->set('Vary', $token);

            return;
        }

        if ($existing === '*') {
            return;
        }

        $existingTokens = array_map(trim(...), explode(',', $existing));
        foreach ($existingTokens as $existingToken) {
            if (strcasecmp($existingToken, $token) === 0) {
                return;
            }
        }

        $response->headers->set('Vary', $existing . ', ' . $token);
    }
}
