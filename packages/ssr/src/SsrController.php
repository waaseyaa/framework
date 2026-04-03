<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Symfony\Component\HttpFoundation\Response;

final class SsrController
{
    public function __construct(
        private readonly ComponentRenderer $renderer,
    ) {}

    /**
     * Render a named component with props and return a Response.
     *
     * @param array<string, mixed> $props
     */
    public function render(string $componentName, array $props = []): Response
    {
        $html = $this->renderer->render($componentName, $props);

        return new Response($html);
    }

    /**
     * Render a component object and return a Response.
     */
    public function renderObject(object $component): Response
    {
        $html = $this->renderer->renderObject($component);

        return new Response($html);
    }
}
