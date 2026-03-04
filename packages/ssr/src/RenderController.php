<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Twig\Environment;
use Twig\Error\LoaderError;

final class RenderController
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function renderPath(string $path = '/'): SsrResponse
    {
        $normalizedPath = trim($path);
        if ($normalizedPath === '') {
            $normalizedPath = '/';
        }
        if (!str_starts_with($normalizedPath, '/')) {
            $normalizedPath = '/' . $normalizedPath;
        }

        $context = [
            'title' => 'Waaseyaa',
            'path' => $normalizedPath,
        ];

        foreach (['page.html.twig', 'ssr/page.html.twig'] as $template) {
            try {
                $html = $this->twig->render($template, $context);
                return new SsrResponse(content: $html);
            } catch (LoaderError) {
                continue;
            }
        }

        // Safe fallback while theme/templates are still being introduced.
        return new SsrResponse(content: sprintf(
            '<!doctype html><html><head><meta charset="utf-8"><title>Waaseyaa</title></head><body><main><h1>Waaseyaa</h1><p>Path: %s</p></main></body></html>',
            htmlspecialchars($normalizedPath, ENT_QUOTES, 'UTF-8'),
        ));
    }
}
