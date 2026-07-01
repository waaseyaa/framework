<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Llms;

use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Generates `llms.txt` — an INDEX of per-topic Markdown URLs (per the llms.txt
 * convention), not a single concatenated `llms-full.txt`.
 *
 * Output shape:
 *
 *     # {site title}
 *
 *     > {site summary}
 *
 *     ## {topic title}
 *     {topic summary}
 *     - [link title](link url)
 *     ...
 *
 * Topic model (default): one topic per *public content entity type* — a type
 * with a public canonical URL — each linking to that type's member `.md` URLs.
 * The default is overridable: callers may supply curated {@see LlmsTopic}s
 * directly to {@see self::generate()}. URL generation stays in the caller (a
 * `buildEntityUrl` callback), so this L3 generator never depends on routing.
 *
 * @api
 */
final class LlmsTxtGenerator
{
    /**
     * Render the llms.txt index.
     *
     * @param iterable<LlmsTopic> $topics
     */
    public function generate(string $siteTitle, ?string $siteSummary, iterable $topics): string
    {
        $rawTitle = $this->sanitizeText($siteTitle);
        $title = $rawTitle !== '' ? $rawTitle : 'Site';
        $lines = ['# ' . $title, ''];

        if ($siteSummary !== null) {
            $cleanSummary = $this->sanitizeText($siteSummary);
            if ($cleanSummary !== '') {
                $lines[] = '> ' . $cleanSummary;
                $lines[] = '';
            }
        }

        foreach ($topics as $topic) {
            if ($topic->links === []) {
                continue;
            }

            $lines[] = '## ' . $this->sanitizeText($topic->title);
            $cleanSummary = $this->sanitizeText($topic->summary);
            if ($cleanSummary !== '') {
                $lines[] = $cleanSummary;
            }
            foreach ($topic->links as $link) {
                if (!$this->isSafeLinkUrl($link['url'])) {
                    continue;
                }
                $rawLinkTitle = $this->sanitizeText($link['title']);
                $linkTitle = $rawLinkTitle !== '' ? $rawLinkTitle : $link['url'];
                $lines[] = '- [' . $this->escape($linkTitle) . '](' . $link['url'] . ')';
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines), "\n") . "\n";
    }

    /**
     * Derive the default topic set: one topic per public content entity type.
     *
     * Mirrors {@see \Waaseyaa\Seo\SitemapGenerator::collectFromEntityTypes()} —
     * member IDs are enumerated with `accessCheck(false)` because this builds a
     * public crawler inventory; per-entity access is enforced when the entity's
     * page is subsequently rendered (C-004 audited bypass).
     *
     * @param callable(string $entityTypeId): ?array{title: string, summary: string} $describeType
     *        Returns topic metadata for a public content type, or null to skip the
     *        type (i.e. it is not part of the public agent-readable surface).
     * @param callable(string $entityTypeId, int|string $id, string $label): ?array{title: string, url: string} $buildLink
     *        Builds the per-entity Markdown link, or null/empty to skip the entity.
     *
     * @return list<LlmsTopic>
     */
    public function collectTopics(
        EntityTypeManagerInterface $entityTypeManager,
        callable $describeType,
        callable $buildLink,
        int $maxPerType = 1000,
    ): array {
        $topics = [];

        foreach ($entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
            $meta = $describeType($entityTypeId);
            if ($meta === null) {
                continue;
            }

            // C-22 WP2: the query builder now lives on the repository.
            $query = $entityTypeManager
                ->getRepository($entityTypeId)
                ->getQuery()
                ->accessCheck(false);

            // A public llms.txt must only advertise PUBLISHED content — the
            // accessCheck(false) bypass (C-004) is for URL generation, not a
            // licence to leak unpublished/draft URLs to anonymous crawlers.
            // Types declaring a `status` (published) field are filtered to
            // published rows; types with no published concept are listed as-is.
            if (array_key_exists('status', $definition->getFieldDefinitions())) {
                $query->condition('status', 1);
            }

            $ids = $query->range(0, max(0, $maxPerType))->execute();

            $links = [];
            foreach ($ids as $id) {
                $link = $buildLink($entityTypeId, $id, '');
                if ($link === null || $link['url'] === '') {
                    continue;
                }
                $links[] = $link;
            }

            if ($links === []) {
                continue;
            }

            $topics[] = new LlmsTopic(
                key: $entityTypeId,
                title: $meta['title'],
                summary: $meta['summary'],
                links: $links,
            );
        }

        return $topics;
    }

    /**
     * Collapse CR/LF/tab/other control characters to spaces so an entity-derived
     * value cannot forge llms.txt line structure (new ## sections / - link lines).
     */
    private function sanitizeText(string $text): string
    {
        $collapsed = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);

        return trim($collapsed ?? $text);
    }

    /**
     * Reject link URLs that would break llms.txt structure or carry an unsafe
     * scheme.
     *
     * The generator is routing-agnostic: the caller's `buildLink` callback may
     * legitimately produce site-relative paths (e.g. `/articles/first.md`), so a
     * relative URL (no scheme) is allowed. Only an explicit, non-http(s) scheme
     * (javascript:, data:, file:, vbscript:, …) is rejected. Control characters
     * and whitespace are always rejected — they would break the ](url) markdown
     * syntax or inject new lines.
     */
    private function isSafeLinkUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        // Reject control chars / whitespace — they would break the ](url) markdown or inject lines.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        // Relative URL (no scheme) is allowed; an explicit scheme must be http/https
        // (blocks javascript:/data:/file:/vbscript: etc.).
        if ($scheme === null || $scheme === false) {
            return true;
        }
        $scheme = strtolower($scheme);

        return $scheme === 'http' || $scheme === 'https';
    }

    private function escape(string $text): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], $text);
    }
}
