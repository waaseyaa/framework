<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Waaseyaa\Foundation\Http\HttpResponse;

final class SsrController
{
    public function __construct(
        private readonly ComponentRenderer $renderer,
    ) {}

    /**
     * Render a named component with props and return an HttpResponse.
     *
     * @param array<string, mixed> $props
     */
    public function render(string $componentName, array $props = []): HttpResponse
    {
        $html = $this->renderer->render($componentName, $props);

        return new HttpResponse(content: $html);
    }

    /**
     * Render a component object and return an HttpResponse.
     */
    public function renderObject(object $component): HttpResponse
    {
        $html = $this->renderer->renderObject($component);

        return new HttpResponse(content: $html);
    }
}
