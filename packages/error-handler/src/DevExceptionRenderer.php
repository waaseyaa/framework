<?php

declare(strict_types=1);

namespace Waaseyaa\ErrorHandler;

final class DevExceptionRenderer
{
    private readonly string $editorId;

    public function __construct(
        private readonly ?SolutionProviderRegistry $solutionRegistry = null,
        ?string $editorId = null,
    ) {
        // Resolve the editor at the composition root so EditorLinkGenerator
        // stays a pure value object with no environment side-effects.
        $env = getenv('EDITOR');
        $fromEnv = is_string($env) && trim($env) !== '' ? strtolower(trim(explode(' ', $env)[0])) : null;
        $this->editorId = $editorId ?? $fromEnv ?? 'vscode';
    }

    public function render(\Throwable $e): string
    {
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $class = htmlspecialchars($e::class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $line = $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $links = new EditorLinkGenerator($this->editorId);
        $editorHref = htmlspecialchars($links->link($e->getFile(), $e->getLine()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $solutionsHtml = '';
        if ($this->solutionRegistry !== null) {
            $blocks = [];
            foreach ($this->solutionRegistry->solutionsFor($e) as $solution) {
                $title = htmlspecialchars($solution->getTitle(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $desc = htmlspecialchars($solution->getDescription(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $blocks[] = "<li><strong>{$title}</strong> — {$desc}</li>";
            }
            if ($blocks !== []) {
                $solutionsHtml = '<h2>Suggestions</h2><ul>' . implode('', $blocks) . '</ul>';
            }
        }

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <title>{$class}</title>
              <style>
                body { font-family: system-ui, sans-serif; margin: 2rem; line-height: 1.45; color: #111; }
                pre { overflow: auto; background: #f6f8fa; padding: 1rem; border-radius: 6px; }
                a { color: #0d47a1; }
              </style>
            </head>
            <body>
              <h1>{$class}</h1>
              <p><strong>Message:</strong> {$message}</p>
              <p><strong>Location:</strong> {$file}:{$line} — <a href="{$editorHref}">Open in editor</a></p>
              {$solutionsHtml}
              <h2>Stack trace</h2>
              <pre>{$trace}</pre>
            </body>
            </html>
            HTML;
    }
}
