<?php

declare(strict_types=1);

namespace Waaseyaa\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Coarse, bypassable regex pre-filter for common XSS vectors.
 *
 * IMPORTANT — SECURITY LIMITATIONS:
 *
 * This constraint is a defence-in-depth blocklist, NOT a real HTML sanitizer.
 * It catches the most common and obvious dangerous patterns (script tags, inline
 * event handlers, javascript: URIs, data:text/html URIs, etc.) but a sufficiently
 * crafted or obfuscated payload will bypass it.
 *
 * Use this constraint only as a cheap first gate or developer convenience check.
 * For untrusted rich-text input use a real allowlist sanitizer instead:
 *
 *   - `Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor` — the
 *     framework's DOM-based allowlist sanitizer (strips disallowed tags and
 *     dangerous URI schemes; optionally uses ezyang/htmlpurifier when present).
 *   - The admin SPA's client-side allowlist in RichText.vue is a complementary
 *     layer but is NOT a server-side security control.
 *
 * NOTE on $allowedTags:
 * The `allowedTags` option is public API — exposed via `ConstraintFactory::safeMarkup()`
 * and used by framework consumers to express intent about which tags are permitted.
 * However, `SafeMarkupValidator` does NOT currently read or enforce this list;
 * it only runs the regex blocklist above.  The `allowedTags` option is reserved
 * for future DOM-based allowlist enforcement and is retained to avoid a BC break.
 * Until that enforcement is implemented, tags NOT in `allowedTags` are NOT stripped
 * or rejected by this validator.
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class SafeMarkup extends Constraint
{
    public string $message = 'The text contains potentially dangerous markup.';

    /**
     * Default safe HTML tags when no custom list is provided.
     *
     * NOTE: This list is currently NOT enforced by SafeMarkupValidator.  It is
     * reserved for a future DOM-based allowlist implementation.  See the class
     * docblock for full security caveats.
     */
    public const array DEFAULT_ALLOWED_TAGS = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'code', 'dd', 'dl', 'dt',
        'em', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'li', 'ol',
        'p', 'pre', 'small', 'strong', 'sub', 'sup', 'table', 'tbody',
        'td', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    /**
     * @param string[] $allowedTags List of HTML tag names expressing intent about
     *                              permitted tags (without angle brackets).
     *                              Currently NOT enforced by SafeMarkupValidator —
     *                              retained as public API for future DOM-based
     *                              allowlist enforcement.  See class docblock.
     * @param string|null $message Custom violation message.
     * @param string[]|string|null $groups Validation groups.
     * @param mixed|null $payload Payload for external use.
     */
    public function __construct(
        public readonly array $allowedTags = self::DEFAULT_ALLOWED_TAGS,
        ?string $message = null,
        array|string|null $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}
