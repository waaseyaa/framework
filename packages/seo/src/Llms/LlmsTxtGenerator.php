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
        $title = trim($siteTitle) !== '' ? trim($siteTitle) : 'Site';
        $lines = ['# ' . $title, ''];

        if ($siteSummary !== null && trim($siteSummary) !== '') {
            $lines[] = '> ' . trim($siteSummary);
            $lines[] = '';
        }

        foreach ($topics as $topic) {
            if ($topic->links === []) {
                continue;
            }

            $lines[] = '## ' . $topic->title;
            if ($topic->summary !== '') {
                $lines[] = $topic->summary;
            }
            foreach ($topic->links as $link) {
                if ($link['url'] === '') {
                    continue;
                }
                $linkTitle = $link['title'] !== '' ? $link['title'] : $link['url'];
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

        foreach (array_keys($entityTypeManager->getDefinitions()) as $entityTypeId) {
            $meta = $describeType($entityTypeId);
            if ($meta === null) {
                continue;
            }

            $ids = $entityTypeManager
                ->getStorage($entityTypeId)
                ->getQuery()
                ->accessCheck(false)
                ->range(0, max(0, $maxPerType))
                ->execute();

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

    private function escape(string $text): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], $text);
    }
}
