<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Twig;

use JsonException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Waaseyaa\Seo\MetaTagBuilder;

final class SeoTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly MetaTagBuilder $metaTagBuilder = new MetaTagBuilder(),
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'seo_meta_head',
                $this->metaTagBuilder->buildHeadSnippet(...),
                ['is_safe' => ['html']],
            ),
            new TwigFunction('seo_json_ld_script', $this->jsonLdScript(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    public function jsonLdScript(array $data): string
    {
        // JSON_HEX_TAG|JSON_HEX_AMP hex-escape `<`, `>`, `&` so untrusted data
        // cannot close the `<script>` element and inject markup into the page.
        $json = json_encode(
            $data,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP,
        );

        return '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }
}
