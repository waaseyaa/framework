<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Waaseyaa\Access\ErrorPageRendererInterface;

final class TwigErrorPageRenderer implements ErrorPageRendererInterface
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function render(int $statusCode, string $title, string $detail, Request $request): ?Response
    {
        $template = $statusCode . '.html.twig';

        if (!$this->twig->getLoader()->exists($template)) {
            return null;
        }

        try {
            $html = $this->twig->render($template, [
                'status_code' => $statusCode,
                'title' => $title,
                'detail' => $detail,
                'request_path' => $request->getPathInfo(),
            ]);
        } catch (\Throwable $e) {
            error_log(sprintf('[Waaseyaa] TwigErrorPageRenderer failed for %s: %s', $template, $e->getMessage()));

            return null;
        }

        return new Response($html, $statusCode, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
