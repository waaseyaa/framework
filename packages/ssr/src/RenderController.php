<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Waaseyaa\Entity\EntityInterface;
use Twig\Environment;
use Twig\Error\LoaderError;

final class RenderController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ?EntityRenderer $entityRenderer = null,
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

    public function renderEntity(EntityInterface $entity, ViewMode|string $viewMode = 'full'): SsrResponse
    {
        if ($this->entityRenderer === null) {
            throw new \RuntimeException('EntityRenderer is required for entity rendering.');
        }

        $bag = $this->entityRenderer->render($entity, $viewMode);
        $templates = $bag['template_suggestions'] ?? [];
        foreach ($templates as $template) {
            if (!is_string($template) || $template === '') {
                continue;
            }
            try {
                return new SsrResponse(content: $this->twig->render($template, $bag));
            } catch (LoaderError) {
                continue;
            }
        }

        return new SsrResponse(content: '<h1>Render template missing</h1>', statusCode: 500);
    }

    public function renderNotFound(string $path): SsrResponse
    {
        $context = ['path' => $path];
        foreach (['404.html.twig', 'ssr/404.html.twig'] as $template) {
            try {
                return new SsrResponse(
                    content: $this->twig->render($template, $context),
                    statusCode: 404,
                );
            } catch (LoaderError) {
                continue;
            }
        }

        return new SsrResponse(
            content: sprintf(
                '<!doctype html><html><head><meta charset="utf-8"><title>404</title></head><body><main><h1>Not Found</h1><p>%s</p></main></body></html>',
                htmlspecialchars($path, ENT_QUOTES, 'UTF-8'),
            ),
            statusCode: 404,
        );
    }
}
