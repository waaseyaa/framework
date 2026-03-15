<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Twig\Environment;
use Twig\Error\LoaderError;
use Waaseyaa\Entity\EntityInterface;

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

        // Try path-specific template first (e.g., /language → language.html.twig).
        $templates = [];
        if ($normalizedPath === '/') {
            $templates[] = 'home.html.twig';
        }
        $pathTemplate = $this->pathSegmentToTemplate(trim($normalizedPath, '/'));
        if ($pathTemplate !== null) {
            $templates[] = $pathTemplate;
        }
        $templates[] = 'page.html.twig';
        $templates[] = 'ssr/page.html.twig';

        foreach ($templates as $template) {
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

    /**
     * @param array<string, mixed> $context
     */
    public function renderEntity(EntityInterface $entity, ViewMode|string $viewMode = 'full', array $context = []): SsrResponse
    {
        if ($this->entityRenderer === null) {
            throw new \RuntimeException('EntityRenderer is required for entity rendering.');
        }

        $bag = $this->entityRenderer->render($entity, $viewMode);
        foreach ($context as $key => $value) {
            if (is_string($key) && $key !== '') {
                $bag[$key] = $value;
            }
        }
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

    public function tryRenderPathTemplate(string $path): ?SsrResponse
    {
        $normalizedPath = trim($path);
        if ($normalizedPath === '' || $normalizedPath === '/') {
            return null;
        }
        if (!str_starts_with($normalizedPath, '/')) {
            $normalizedPath = '/' . $normalizedPath;
        }

        $trimmed = trim($normalizedPath, '/');
        if ($trimmed === '') {
            return null;
        }

        $context = [
            'title' => 'Waaseyaa',
            'path' => $normalizedPath,
        ];

        // Try exact single-segment match first, then first segment of multi-segment paths.
        $candidates = [$trimmed];
        if (str_contains($trimmed, '/')) {
            $candidates[] = explode('/', $trimmed, 2)[0];
        }

        foreach ($candidates as $candidate) {
            $template = $this->pathSegmentToTemplate($candidate);
            if ($template === null) {
                continue;
            }
            try {
                $html = $this->twig->render($template, $context);
                return new SsrResponse(content: $html);
            } catch (LoaderError) {
                continue;
            }
        }

        return null;
    }

    /**
     * Validate a path segment and return its template filename, or null if invalid.
     * Valid segments are lowercase alphanumeric with optional hyphens (no leading/trailing hyphens).
     */
    private function pathSegmentToTemplate(string $segment): ?string
    {
        if ($segment === '' || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $segment)) {
            return null;
        }

        return $segment . '.html.twig';
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

    public function renderForbidden(string $path): SsrResponse
    {
        $context = ['path' => $path];
        foreach (['403.html.twig', 'ssr/403.html.twig'] as $template) {
            try {
                return new SsrResponse(
                    content: $this->twig->render($template, $context),
                    statusCode: 403,
                );
            } catch (LoaderError) {
                continue;
            }
        }

        return new SsrResponse(
            content: sprintf(
                '<!doctype html><html><head><meta charset="utf-8"><title>403</title></head><body><main><h1>Forbidden</h1><p>%s</p></main></body></html>',
                htmlspecialchars($path, ENT_QUOTES, 'UTF-8'),
            ),
            statusCode: 403,
        );
    }

    public function renderServerError(): SsrResponse
    {
        foreach (['500.html.twig', 'ssr/500.html.twig'] as $template) {
            try {
                return new SsrResponse(
                    content: $this->twig->render($template),
                    statusCode: 500,
                );
            } catch (LoaderError) {
                continue;
            }
        }

        return new SsrResponse(
            content: '<!doctype html><html><head><meta charset="utf-8"><title>500</title></head><body><main><h1>Server Error</h1><p>Something went wrong. Please try again later.</p></main></body></html>',
            statusCode: 500,
        );
    }
}
