<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;

#[AsMiddleware(pipeline: 'http', priority: 50)]
final class ETagMiddleware implements HttpMiddlewareInterface
{
    private const CACHEABLE_METHODS = ['GET', 'HEAD'];

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        if (!in_array($request->getMethod(), self::CACHEABLE_METHODS, true)) {
            return $response;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return $response;
        }

        $hash = function_exists('hash') && in_array('xxh3', hash_algos(), true)
            ? hash('xxh3', $content)
            : md5($content);

        $etag = '"' . $hash . '"';
        $response->headers->set('ETag', $etag);

        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
            return new Response('', Response::HTTP_NOT_MODIFIED, [
                'ETag' => $etag,
            ]);
        }

        return $response;
    }
}
