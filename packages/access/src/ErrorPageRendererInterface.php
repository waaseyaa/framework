<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
interface ErrorPageRendererInterface
{
    /**
     * Render an error page for the given status code.
     *
     * Returns null if no template is available, allowing the caller to fall
     * back to its own default rendering.
     */
    public function render(int $statusCode, string $title, string $detail, Request $request): ?Response;
}
