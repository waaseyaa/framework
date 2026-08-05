<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Discovery\AiCatalog;
use Waaseyaa\Foundation\Http\Request;

/** Serves the configured, closed-world experimental AI Catalog. */
final readonly class AiCatalogController
{
    public function __construct(private AiCatalog $catalog) {}

    public function serve(Request $request): Response
    {
        if (!$this->acceptsCatalog((string) $request->headers->get('Accept', ''))) {
            return new Response('Available representation: ' . AiCatalog::MEDIA_TYPE . "\n", 406, [
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Vary' => 'Accept',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $body = $this->catalog->toJson();
        $etag = '"' . hash('sha256', $body) . '"';
        $headers = [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=300',
            'Content-Length' => (string) strlen($body),
            'Content-Type' => AiCatalog::MEDIA_TYPE,
            'ETag' => $etag,
            // ai-catalog is a draft extension relation, not an IANA-registered relation.
            'Link' => sprintf(
                '<%s>; rel="ai-catalog"; type="%s"',
                $this->catalog->catalogUrl(),
                AiCatalog::MEDIA_TYPE,
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

    private function acceptsCatalog(string $accept): bool
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
                AiCatalog::MEDIA_TYPE => 2,
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
