<?php

declare(strict_types=1);

namespace Waaseyaa\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Coarse, bypassable regex pre-filter for common XSS patterns.
 *
 * IMPORTANT — SECURITY LIMITATIONS:
 *
 * This validator is a defence-in-depth blocklist. It is NOT a substitute for a
 * real HTML sanitizer and MUST NOT be relied upon as the sole protection against
 * XSS. Known limitations:
 *
 *  - Regex-based: a sufficiently crafted or obfuscated payload will bypass it.
 *  - Does NOT enforce `SafeMarkup::$allowedTags` — the allowedTags option is
 *    stored on the constraint as public API (reserved for future DOM-based
 *    allowlist enforcement) but is never read by this validator. Disallowed tags
 *    are NOT stripped or rejected.
 *  - Suitable use: a cheap first gate on trusted-ish input, or as a developer
 *    convenience constraint in low-risk contexts.
 *
 * For untrusted rich-text input (user-submitted HTML, migrated CMS content, etc.)
 * use a real allowlist sanitizer instead:
 *
 *   - `Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor` — the
 *     framework's DOM-based allowlist sanitizer (strips disallowed tags and
 *     dangerous URI schemes; optionally uses ezyang/htmlpurifier when present).
 *   - The admin SPA's client-side allowlist in RichText.vue is a complementary
 *     layer but also NOT a server-side security control.
 */
final class SafeMarkupValidator extends ConstraintValidator
{
    /**
     * Regex patterns matching dangerous markup constructs.
     *
     * These are a blocklist, not an allowlist — they catch the most common and
     * obvious XSS patterns.  A determined attacker can bypass this list (encoding
     * tricks, novel vectors, browser quirks).  Use a real sanitizer for untrusted
     * HTML — see class-level docblock.
     *
     * @var string[]
     */
    private const array DANGEROUS_PATTERNS = [
        // Script tags (including variations).
        '/<script\b[^>]*>.*?<\/script>/is',
        '/<script\b[^>]*>/is',

        // Event handler attributes (on*="...").
        '/\bon\w+\s*=/is',

        // javascript: and vbscript: URIs.
        '/\bjavascript\s*:/is',
        '/\bvbscript\s*:/is',

        // data: URIs with executable / markup MIME types — blocked regardless of
        // encoding scheme (i.e. both ;base64 and plain-text variants).  A browser
        // navigating a data:text/html URI renders full attacker-controlled HTML,
        // enabling phishing / XSS even without a literal <script> tag in the
        // validator's input (e.g. via URL-encoding or browser-specific vectors).
        '/\bdata\s*:\s*text\s*\/\s*html\b/is',
        '/\bdata\s*:\s*application\s*\/\s*(javascript|ecmascript|x-javascript)\b/is',
        '/\bdata\s*:\s*image\s*\/\s*svg\+xml\b/is',

        // Expression() in CSS (IE-specific XSS).
        '/expression\s*\(/is',

        // Import directives that can load external resources.
        '/@import\b/is',

        // Embedded objects and iframes.
        '/<\s*(iframe|object|embed|applet|form)\b/is',

        // Meta refresh redirects.
        '/<\s*meta[^>]+http-equiv\s*=/is',

        // SVG event handlers and script.
        '/<\s*svg\b[^>]*\bon\w+/is',
    ];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SafeMarkup) {
            throw new UnexpectedTypeException($constraint, SafeMarkup::class);
        }

        // Allow null values to pass through.
        if ($value === null || $value === '') {
            return;
        }

        if (!\is_string($value)) {
            return;
        }

        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                $this->context->buildViolation($constraint->message)
                    ->addViolation();

                return;
            }
        }
    }
}
