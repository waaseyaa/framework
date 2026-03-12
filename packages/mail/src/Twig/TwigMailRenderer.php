<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Twig;

use Twig\Environment;
use Waaseyaa\Mail\Envelope;

final class TwigMailRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    /**
     * Render a Twig template into an Envelope.
     *
     * @param list<string> $to
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    public function render(
        string $template,
        array $to,
        string $from,
        string $subject,
        array $context = [],
        array $headers = [],
    ): Envelope {
        $htmlBody = $this->twig->render($template, $context);

        return new Envelope(
            to: $to,
            from: $from,
            subject: $subject,
            htmlBody: $htmlBody,
            headers: $headers,
        );
    }
}
