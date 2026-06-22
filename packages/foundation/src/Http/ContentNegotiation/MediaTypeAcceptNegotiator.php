<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\ContentNegotiation;

/**
 * Resolves the response media type from the HTTP `Accept` header (RFC 7231),
 * and from an explicit `?raw` / `?format=` query override.
 *
 * The same physical URL can serve more than one representation (e.g.
 * `text/html` for browsers, `text/markdown` for agents). This negotiator is
 * the single place that decides which representation a request wants, so the
 * `Accept`-driven path and the human-facing `?raw` toggle converge on one
 * answer.
 *
 * Negotiation algorithm (per supported type, in server-preference order):
 *   1. Find the most specific matching `Accept` entry — exact `type/subtype`
 *      beats `type/*` beats `*​/*`.
 *   2. The chosen type is the supported type with the highest matched quality
 *      (> 0). Ties resolve to the earliest entry in `$supported` (server
 *      preference wins).
 *   3. An empty header, or no entry with quality > 0, yields `$default`.
 *
 * @api
 */
final class MediaTypeAcceptNegotiator
{
    public const string HTML = 'text/html';
    public const string MARKDOWN = 'text/markdown';

    /**
     * Negotiate the best supported media type for an `Accept` header.
     *
     * @param list<string> $supported Server-supported media types, in
     *                                descending server preference. Must be
     *                                non-empty; `$default` should be a member.
     *
     * @return string One of `$supported` — `$default` when nothing matches.
     */
    public function negotiate(string $acceptHeader, array $supported, string $default): string
    {
        if ($supported === []) {
            return $default;
        }

        $acceptHeader = trim($acceptHeader);
        if ($acceptHeader === '') {
            return $default;
        }

        $entries = $this->parse($acceptHeader);
        if ($entries === []) {
            return $default;
        }

        $bestType = null;
        $bestQuality = 0.0;
        $bestSupportedOrder = \PHP_INT_MAX;

        foreach ($supported as $supportedOrder => $candidate) {
            $quality = $this->qualityFor($candidate, $entries);

            // Quality 0 explicitly rejects the type; skip it.
            if ($quality <= 0.0) {
                continue;
            }

            if ($quality > $bestQuality
                || ($quality === $bestQuality && $supportedOrder < $bestSupportedOrder)
            ) {
                $bestType = $candidate;
                $bestQuality = $quality;
                $bestSupportedOrder = $supportedOrder;
            }
        }

        return $bestType ?? $default;
    }

    /**
     * Resolve an explicit representation override from query parameters.
     *
     * Recognized: `?raw` (any value, including empty) -> markdown;
     * `?format=md|markdown` -> markdown; `?format=html` -> HTML. The result is
     * only returned when it is a member of `$supported`; otherwise `null`,
     * letting the caller fall back to {@see self::negotiate()}.
     *
     * @param array<string, mixed> $query
     * @param list<string>         $supported
     */
    public function resolveQueryOverride(array $query, array $supported): ?string
    {
        $resolved = null;

        if (\array_key_exists('raw', $query)) {
            $resolved = self::MARKDOWN;
        }

        if (isset($query['format']) && \is_string($query['format'])) {
            $resolved = match (strtolower(trim($query['format']))) {
                'md', 'markdown' => self::MARKDOWN,
                'html' => self::HTML,
                default => $resolved,
            };
        }

        if ($resolved !== null && \in_array($resolved, $supported, true)) {
            return $resolved;
        }

        return null;
    }

    /**
     * Best quality value an `Accept` entry assigns to a concrete media type,
     * honouring specificity (exact > type/* > *​/*).
     *
     * @param list<array{type: string, subtype: string, quality: float, specificity: int}> $entries
     */
    private function qualityFor(string $mediaType, array $entries): float
    {
        [$type, $subtype] = $this->splitType($mediaType);

        $bestQuality = 0.0;
        $bestSpecificity = -1;

        foreach ($entries as $entry) {
            $matches = match (true) {
                $entry['type'] === '*' => true,                                   // */*
                $entry['type'] === $type && $entry['subtype'] === '*' => true,    // type/*
                $entry['type'] === $type && $entry['subtype'] === $subtype => true, // exact
                default => false,
            };

            if (!$matches) {
                continue;
            }

            // More specific match wins even at lower quality, per RFC 7231.
            if ($entry['specificity'] > $bestSpecificity) {
                $bestSpecificity = $entry['specificity'];
                $bestQuality = $entry['quality'];
            }
        }

        return $bestQuality;
    }

    /**
     * Parse an `Accept` header into ranked media-range entries.
     *
     * @return list<array{type: string, subtype: string, quality: float, specificity: int}>
     */
    private function parse(string $header): array
    {
        $entries = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = explode(';', $part);
            $range = trim($segments[0]);
            if ($range === '') {
                continue;
            }

            [$type, $subtype] = $this->splitType($range);
            $quality = 1.0;

            for ($i = 1, $count = \count($segments); $i < $count; $i++) {
                $param = trim($segments[$i]);
                if (str_starts_with($param, 'q=')) {
                    $qValue = substr($param, 2);
                    if (is_numeric($qValue)) {
                        $quality = max(0.0, min(1.0, (float) $qValue));
                    }
                }
            }

            // Specificity: exact type/subtype = 2, type/* = 1, */* = 0.
            $specificity = match (true) {
                $type === '*' => 0,
                $subtype === '*' => 1,
                default => 2,
            };

            $entries[] = [
                'type' => $type,
                'subtype' => $subtype,
                'quality' => $quality,
                'specificity' => $specificity,
            ];
        }

        return $entries;
    }

    /**
     * Split a media range into [type, subtype], lowercased; parameters dropped.
     *
     * @return array{0: string, 1: string}
     */
    private function splitType(string $range): array
    {
        $range = strtolower(trim($range));
        $pos = strpos($range, '/');
        if ($pos === false) {
            return [$range, '*'];
        }

        return [substr($range, 0, $pos), substr($range, $pos + 1)];
    }
}
