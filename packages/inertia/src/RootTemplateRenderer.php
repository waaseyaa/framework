<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

use Waaseyaa\Foundation\Asset\ViteAssetManager;
use Waaseyaa\Foundation\Http\Inertia\InertiaFullPageRendererInterface;

final class RootTemplateRenderer implements InertiaFullPageRendererInterface
{
    public function __construct(
        private readonly ?\Closure $template = null,
        private readonly ?ViteAssetManager $assetManager = null,
        private readonly ?string $bundle = null,
        private readonly ?string $entrypoint = null,
    ) {}

    /** @param array<string, mixed> $pageObject */
    public function render(array $pageObject): string
    {
        $json = json_encode(
            $pageObject,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE,
        );

        $scriptTag = '<script type="application/json" data-page="app">' . $json . '</script>';

        if ($this->template !== null) {
            return ($this->template)($scriptTag);
        }

        return $this->defaultTemplate($scriptTag);
    }

    private function defaultTemplate(string $scriptTag): string
    {
        // Thread overridable bundle/entrypoint only when set; null args fall back to
        // ViteAssetManager::assetTags()'s own defaults ('build' / 'resources/js/app.ts').
        $assetTags = $this->assetManager === null
            ? ''
            : ($this->bundle !== null && $this->entrypoint !== null
                ? $this->assetManager->assetTags($this->bundle, $this->entrypoint)
                : ($this->bundle !== null
                    ? $this->assetManager->assetTags($this->bundle)
                    : $this->assetManager->assetTags()));

        return <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                {$assetTags}
            </head>
            <body>
                <div id="app"></div>
                {$scriptTag}
            </body>
            </html>
            HTML;
    }
}
