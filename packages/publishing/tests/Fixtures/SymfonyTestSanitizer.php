<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Fixtures;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Waaseyaa\Publishing\ContentHtmlSanitizerInterface;

/** Test allowlist sanitizer (the shape apps ship as an adapter). */
final readonly class SymfonyTestSanitizer implements ContentHtmlSanitizerInterface
{
    private HtmlSanitizer $sanitizer;

    /** @param list<string> $allowedElements */
    public function __construct(array $allowedElements = ['p', 'strong'])
    {
        $config = new HtmlSanitizerConfig();
        foreach ($allowedElements as $element) {
            $config = $config->allowElement($element);
        }
        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
