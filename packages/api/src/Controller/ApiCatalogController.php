<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Discovery\ApiCatalog;
use Waaseyaa\Foundation\Http\Request;

/** Serves the configured, closed-world RFC 9727 API Catalog. */
final readonly class ApiCatalogController
{
    public function __construct(private ApiCatalog $catalog) {}

    public function serve(Request $request): Response
    {
        if (!$this->acceptsLinkset((string) $request->headers->get('Accept', ''))) {
            $body = 'Available representation: ' . ApiCatalog::MEDIA_TYPE . "\n";

            return new Response($body, 406, [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Vary' => 'Accept',
            ]);
        }

        $body = $this->catalog->toJson();
        $etag = '"' . hash('sha256', $body) . '"';
        $contentType = ApiCatalog::MEDIA_TYPE . '; profile="' . ApiCatalog::PROFILE . '"';
        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'public, max-age=300',
            'ETag' => $etag,
            'Link' => sprintf(
                '<%s>; rel="api-catalog"; type="%s"',
                $this->catalog->catalogUrl(),
                ApiCatalog::MEDIA_TYPE,
            ),
            'Vary' => 'Accept',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($this->matchesIfNoneMatch($request, $etag)) {
            unset($headers['Content-Type'], $headers['Content-Length']);

            return new Response('', 304, $headers);
        }

        return new Response($request->isMethod('HEAD') ? '' : $body, 200, $headers);
    }

    private function matchesIfNoneMatch(Request $request, string $etag): bool
    {
        foreach ($request->getETags() as $candidate) {
            if ($candidate === '*') {
                return true;
            }
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if (hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function acceptsLinkset(string $accept): bool
    {
        $accept = trim(strtolower($accept));
        if ($accept === '') {
            return true;
        }

        $bestSpecificity = -1;
        $bestQuality = 0.0;
        foreach (explode(',', $accept) as $range) {
            $parameters = array_map('trim', explode(';', $range));
            $mediaType = array_shift($parameters);
            $quality = 1.0;
            foreach ($parameters as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $rawQuality = substr($parameter, 2);
                    $quality = is_numeric($rawQuality) ? max(0.0, min(1.0, (float) $rawQuality)) : 0.0;
                }
            }

            $specificity = match ($mediaType) {
                ApiCatalog::MEDIA_TYPE => 2,
                'application/*' => 1,
                '*/*' => 0,
                default => -1,
            };
            if ($specificity > $bestSpecificity) {
                $bestSpecificity = $specificity;
                $bestQuality = $quality;
            } elseif ($specificity === $bestSpecificity) {
                $bestQuality = max($bestQuality, $quality);
            }
        }

        return $bestSpecificity >= 0 && $bestQuality > 0.0;
    }
}
