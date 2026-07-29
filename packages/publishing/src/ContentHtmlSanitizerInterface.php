<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

/**
 * App-supplied HTML sanitizer applied to every {@see FieldSpec::$html} field
 * BEFORE persistence.
 *
 * The publishing package deliberately owns no sanitizer implementation and no
 * Symfony coupling (the framework's Symfony-import boundary, #1374): the app
 * supplies its explicit editorial allowlist behind this contract — typically
 * a thin adapter over `Symfony\Component\HtmlSanitizer` configured with the
 * app's allowed elements/attributes. Implementations MUST be allowlist-based
 * and MUST strip executable content (scripts, event handlers, styles).
 *
 * @api
 */
interface ContentHtmlSanitizerInterface
{
    public function sanitize(string $html): string;
}
