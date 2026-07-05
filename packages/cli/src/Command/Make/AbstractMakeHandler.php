<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Make;

/**
 * Base class for make:* scaffolding handlers.
 *
 * Provides shared helpers for stub loading, placeholder replacement, and
 * input validation. Every make:* handler renders user-supplied CLI input
 * (`--name`/`--domain`/`--event`/type ids) directly into GENERATED PHP
 * source that later executes on autoload — so any value that becomes a
 * class name, machine id, namespace segment, or filesystem path MUST be
 * validated against a strict allowlist here, at the boundary, before it is
 * used for anything (R10 WP3 / audit A7 F3+F4).
 *
 * @internal
 */
abstract class AbstractMakeHandler
{
    /**
     * Allowlist for values that become a PHP class/identifier base (e.g. the
     * `name` argument on make:entity, make:job, make:policy, make:test,
     * make:listener, make:provider). Unicode letters/digits + underscore only:
     * no quotes, semicolons, backslashes, slashes, whitespace, or newlines can
     * reach a generated PHP literal, a bare code position, or a filesystem path
     * through this gate — a string of `\p{L}\p{N}_` contains none of them.
     *
     * The `u` flag makes `\p{L}`/`\p{N}` match multi-byte Unicode (PHP allows
     * identifier bytes >= 0x80, so Indigenous-orthography names with diacritics
     * or Canadian Aboriginal Syllabics are valid PHP identifiers — this gate
     * MUST accept them; transliterating/stripping non-ASCII would corrupt the
     * orthography, which the framework charter forbids). The `D` flag anchors
     * `$` to the true end of string so a trailing `\n` cannot smuggle a payload
     * past the anchor (without `D`, PHP's `$` matches before a final newline).
     */
    protected const string IDENTIFIER_PATTERN = '/^[\p{L}\p{N}_]+$/uD';

    /**
     * Allowlist for machine names that become entity-type ids, plugin ids,
     * or table names: Unicode letter/underscore start, then letters/digits/
     * underscore. Mirrors the field-name convention already enforced by
     * {@see \Waaseyaa\CLI\Handler\MakeContentTypeHandler::parseFields()} but
     * Unicode-aware. `u`+`D` flags per {@see self::IDENTIFIER_PATTERN}.
     */
    protected const string MACHINE_NAME_PATTERN = '/^[\p{L}_][\p{L}\p{N}_]*$/uD';

    /**
     * Allowlist for a (possibly fully-qualified) PHP class name, e.g. the
     * `--event` option on make:listener. Backslash-separated namespace
     * segments are allowed; each segment must itself be a valid identifier
     * segment (Unicode-aware), so a payload cannot smuggle a closing paren/
     * brace, a statement terminator, or a trailing-newline breakout through the
     * FQCN position. `u`+`D` flags per {@see self::IDENTIFIER_PATTERN}.
     */
    protected const string FQCN_PATTERN = '/^\\\\?[\p{L}_][\p{L}\p{N}_]*(\\\\[\p{L}_][\p{L}\p{N}_]*)*$/uD';

    /**
     * Reject $value unless it matches $pattern. Called at the TOP of a
     * handler, before the value is used to build a class name, machine id,
     * namespace, filesystem path, or generated code literal.
     *
     * Deliberately does not sanitize (e.g. strip disallowed characters) —
     * silently rewriting the value could produce a colliding or empty
     * identifier. Reject and tell the caller what was wrong instead.
     *
     * @throws \RuntimeException When $value does not match $pattern.
     */
    protected function validateIdentifier(string $value, string $what, string $pattern = self::IDENTIFIER_PATTERN): string
    {
        if ($value === '' || preg_match($pattern, $value) !== 1) {
            throw new \RuntimeException(sprintf(
                'Invalid %s "%s": must match %s.',
                $what,
                $value,
                $pattern,
            ));
        }

        return $value;
    }

    /**
     * Convenience wrapper for machine-name identifiers (entity type ids,
     * plugin ids, table names): {@see self::MACHINE_NAME_PATTERN}.
     *
     * @throws \RuntimeException When $value does not match the pattern.
     */
    protected function validateMachineName(string $value, string $what): string
    {
        return $this->validateIdentifier($value, $what, self::MACHINE_NAME_PATTERN);
    }

    /**
     * Convert a snake_case or lower name to PascalCase.
     */
    protected function toPascalCase(string $name): string
    {
        // If already PascalCase (starts with an uppercase Unicode letter, no
        // underscores), return as-is. `u`+`D` flags: `\p{Lu}` matches non-ASCII
        // uppercase, and `D` anchors `$` to the true end of string so a
        // trailing `\n` cannot pass this fast path unstripped (that was the
        // root cause of a `Foo\nServiceProvider` corruption before the `D`
        // fix — the fast path returned the value with its newline intact).
        if (preg_match('/^\p{Lu}[\p{L}\p{N}]*$/uD', $name)) {
            return $name;
        }

        $pascal = str_replace('_', '', ucwords($name, '_'));

        // Defensive hardening: callers are expected to validate $name against
        // IDENTIFIER_PATTERN before it ever reaches this helper, but strip
        // anything outside the safe identifier character set here too, so
        // this helper can never itself reintroduce a breakout sequence
        // (quote, semicolon, newline, ...) into generated PHP. Unicode
        // letters/digits/underscore are preserved (orthography-safe).
        return preg_replace('/[^\p{L}\p{N}_]/u', '', $pascal) ?? '';
    }

    /**
     * Load a stub file and apply placeholder replacements.
     *
     * @param string $stubName The stub filename without extension (e.g. 'entity').
     * @param array<string, string> $replacements Placeholders => values.
     * @return string The rendered stub content.
     */
    protected function renderStub(string $stubName, array $replacements): string
    {
        $stubPath = dirname(__DIR__, 3) . '/stubs/' . $stubName . '.stub';

        if (!file_exists($stubPath)) {
            throw new \RuntimeException(sprintf('Stub file not found: %s', $stubPath));
        }

        $content = file_get_contents($stubPath);

        foreach ($replacements as $placeholder => $value) {
            $content = str_replace('{{ ' . $placeholder . ' }}', $value, $content);
        }

        return $content;
    }
}
