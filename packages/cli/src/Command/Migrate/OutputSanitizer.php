<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

/**
 * Strips host-specific filesystem paths from operator-facing output in
 * production mode.
 *
 * Per spec §7.3, production diagnostic output MUST NOT include raw
 * filesystem paths — operators paste these into Slack / Sentry / public
 * dashboards and the paths leak deployment topology. Development output
 * keeps the paths so debugging stays ergonomic.
 *
 * The sanitizer filters absolute Unix and Windows paths, including bare
 * directories. It is intentionally conservative: it never rewrites substrings
 * that look like migration ids
 * (`waaseyaa/foundation:v2:foo` — colons are not in the path regex) or
 * package names (`waaseyaa/cli` — no slash-then-extension).
 */
final readonly class OutputSanitizer
{
    public function __construct(public bool $isProduction) {}

    /**
     * Replace absolute filesystem paths in the message with `<path>`
     * when running in production mode. In development mode, returns the
     * message unchanged.
     */
    public function sanitize(string $message): string
    {
        if (! $this->isProduction) {
            return $message;
        }

        // File-shaped paths are replaced first, using their extension as the
        // boundary so following prose survives. The remaining patterns cover
        // bare directories up to a diagnostic delimiter or end-of-message.
        // Spaces are accepted because production paths commonly contain them.
        $patterns = [
            '#file:///(?:[A-Za-z0-9._%\-]+(?: [A-Za-z0-9._%\-]+)*/)*[A-Za-z0-9._%\-]+(?: [A-Za-z0-9._%\-]+)*\.[A-Za-z0-9]{1,12}#i',
            '#(?<![A-Za-z0-9._:/\-])(?:/|[A-Za-z]:\\\\|\\\\\\\\)(?:[A-Za-z0-9._\-]+(?: [A-Za-z0-9._\-]+)*[\\\\/])*[A-Za-z0-9._\-]+(?: [A-Za-z0-9._\-]+)*\.[A-Za-z0-9]{1,12}#',
            '#file:///[^;\r\n,)\]"]+?(?=$|[;,)\]"])#i',
            '#(?<![A-Za-z0-9])\\\\\\\\[^;\r\n,)\]"]+?(?=$|[;,)\]"])#',
            '#(?<![A-Za-z0-9])[A-Za-z]:\\\\[^;\r\n,)\]"]+?(?=$|[;,)\]"])#',
            '#(?<![A-Za-z0-9._:/\-])/(?!/)[^;\r\n,)\]"]+?(?=$|[;,)\]"])#',
        ];
        $sanitized = preg_replace($patterns, '<path>', $message);

        return $sanitized ?? $message;
    }
}
