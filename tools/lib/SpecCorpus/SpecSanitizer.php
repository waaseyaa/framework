<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling\SpecCorpus;

/**
 * Strips workflow noise from retrieval text while preserving provenance.
 *
 * @api
 */
final class SpecSanitizer
{
    private const SPEC_BASE = 'docs/specs';

    private const INTERNAL_PREFIXES = [
        'kitty-specs/',
        'docs/history/',
        'changes/',
    ];

    /**
     * @return array{
     *   retrieval_text: string,
     *   provenance: array{
     *     spec_reviewed_comments: list<string>,
     *     internal_links: list<array{label: string, target: string}>,
     *     unsupported_reference_links: list<string>
     *   }
     * }
     */
    public static function sanitize(string $body): array
    {
        $specReviewed = [];
        $retrieval = preg_replace_callback(
            '/<!--\s*Spec reviewed.*?-->/is',
            static function (array $match) use (&$specReviewed): string {
                $specReviewed[] = $match[0];
                return '';
            },
            $body,
        ) ?? $body;

        // Reference-definition resolution is outside this bounded inline parser.
        // Refuse internal targets instead of emitting a falsely sanitized corpus.
        if (preg_match_all('/^ {0,3}\[[^\]\n]+\]:[ \t]*(?:<([^>\n]+)>|([^\s]+))/m', $retrieval, $definitions, PREG_SET_ORDER) > 0) {
            foreach ($definitions as $definition) {
                $target = ($definition[1] ?? '') !== '' ? $definition[1] : ($definition[2] ?? '');
                if (self::isInternalExecutionLink($target)) {
                    throw new SpecCorpusException('Internal reference-style execution links must be converted to inline links before compilation.');
                }
            }
        }

        $unsupportedReferenceLinks = [];
        if (preg_match_all('/\[[^\]]+\]\[[^\]]+\]/', $retrieval, $referenceMatches) > 0) {
            $unsupportedReferenceLinks = array_values($referenceMatches[0]);
        }

        $internalLinks = [];
        $retrieval = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            static function (array $match) use (&$internalLinks): string {
                $label = $match[1];
                $target = $match[2];
                if (!self::isInternalExecutionLink($target)) {
                    return $match[0];
                }
                $internalLinks[] = ['label' => $label, 'target' => $target];
                return $label;
            },
            $retrieval,
        ) ?? $retrieval;

        $retrieval = preg_replace("/\n{3,}/", "\n\n", $retrieval) ?? $retrieval;

        return [
            'retrieval_text' => trim($retrieval) . "\n",
            'provenance' => [
                'spec_reviewed_comments' => $specReviewed,
                'internal_links' => $internalLinks,
                'unsupported_reference_links' => $unsupportedReferenceLinks,
            ],
        ];
    }

    public static function isInternalExecutionLink(string $target): bool
    {
        $target = trim($target);
        if (str_starts_with($target, '<') && str_ends_with($target, '>')) {
            $target = substr($target, 1, -1);
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
            return false;
        }

        if (str_starts_with($target, '#')) {
            return false;
        }

        $normalizedPaths = [
            self::normalizeRelativePath(str_replace('\\', '/', $target), ''),
            self::normalizeRelativePath(str_replace('\\', '/', $target), self::SPEC_BASE),
        ];

        foreach ($normalizedPaths as $normalized) {
            foreach (self::INTERNAL_PREFIXES as $prefix) {
                if ($normalized === rtrim($prefix, '/') || str_starts_with($normalized, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function normalizeRelativePath(string $path, string $baseDir): string
    {
        $parts = $baseDir !== '' ? explode('/', trim($baseDir, '/')) : [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}
