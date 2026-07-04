<?php

declare(strict_types=1);

namespace Waaseyaa\Seo;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * @api
 */
final class SitemapGenerator
{
    /**
     * @param list<SitemapUrl> $urls
     */
    public function toXml(array $urls): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $this->escapeXml($url->loc) . '</loc>';

            if ($url->lastmod !== null && $url->lastmod !== '') {
                $lines[] = '    <lastmod>' . $this->escapeXml($url->lastmod) . '</lastmod>';
            }

            if ($url->changefreq !== null) {
                $lines[] = '    <changefreq>' . $this->escapeXml($url->changefreq) . '</changefreq>';
            }

            if ($url->priority !== null) {
                $lines[] = '    <priority>' . htmlspecialchars(
                    (string) round($url->priority, 1),
                    \ENT_XML1 | \ENT_QUOTES,
                    'UTF-8',
                ) . '</priority>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Collect sitemap rows by paging entity IDs per type. URL generation stays in application code.
     *
     * @param callable(string,int|string): ?string $buildLoc Returning null or '' skips the ID.
     * @param list<string>|null $includeEntityTypes When null, all registered types (from getDefinitions) are considered.
     * @param list<string> $excludeEntityTypes
     * @param array<string, array<string, mixed>> $perTypeOptions Optional keys: `changefreq`, `priority`, `max`.
     * @param ?AccountInterface $account The account the enumeration is performed on behalf of
     *   (e.g. an anonymous crawler). Bound via `setAccount()` so the underlying entity query
     *   runs the SAME `AccessPolicyInterface` pipeline the rest of the framework uses — a
     *   published-but-access-restricted entity (a classification hold, a genealogy privacy
     *   rule, etc.) is excluded, not just unpublished ones. When null, no account is bound and
     *   the query is left at its fail-closed default (an unbound, access-checked query throws
     *   `MissingQueryAccountException`) — callers building a PUBLIC crawler surface MUST pass
     *   an account (typically `Waaseyaa\User\AnonymousUser`); this is not an opt-out bypass.
     *
     * @return list<SitemapUrl>
     */
    public function collectFromEntityTypes(
        EntityTypeManagerInterface $entityTypeManager,
        callable $buildLoc,
        ?array $includeEntityTypes = null,
        array $excludeEntityTypes = [],
        int $maxUrlsPerType = 50000,
        array $perTypeOptions = [],
        ?AccountInterface $account = null,
    ): array {
        $exclude = array_flip($excludeEntityTypes);
        $include = $includeEntityTypes !== null ? array_flip($includeEntityTypes) : null;
        $out = [];

        foreach ($entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
            if (isset($exclude[$entityTypeId])) {
                continue;
            }

            if ($include !== null && !isset($include[$entityTypeId])) {
                continue;
            }

            if (!$entityTypeManager->hasDefinition($entityTypeId)) {
                continue;
            }

            $typeOpts = $perTypeOptions[$entityTypeId] ?? [];
            $rawMax = $typeOpts['max'] ?? null;
            $limit = is_numeric($rawMax) ? max(0, (int) $rawMax) : $maxUrlsPerType;

            if ($limit === 0) {
                continue;
            }

            // R6/M3 (audit M3, closes the C-004 bypass for this callsite):
            // enumeration must be ACCESS-AWARE, not a blanket bypass — a
            // published-but-access-restricted entity (a classification hold,
            // a genealogy privacy rule, etc.) must not be enumerated to an
            // anonymous crawler. Binding $account runs SqlEntityQuery's
            // per-candidate EntityAccessHandler::check() (the SAME pipeline
            // single-entity loads use), so a Forbidden/Neutral policy result
            // excludes the row. When no account is supplied the query is left
            // at its fail-closed default rather than falling back to
            // accessCheck(false) — see the $account param doc above.
            // C-22 WP2: the query builder now lives on the repository.
            $query = $entityTypeManager->getRepository($entityTypeId)->getQuery();

            if ($account !== null) {
                $query->setAccount($account);
            }

            // ...but a *public* sitemap must still only advertise PUBLISHED content:
            // the accessCheck(false) bypass is for URL-generation performance, not a
            // licence to leak unpublished/draft URLs to anonymous crawlers (C-004's
            // own rationale is "public URLs"). Entity types that declare a `status`
            // (published) field are filtered to published rows; types with no
            // published concept are listed as-is.
            if (array_key_exists('status', $definition->getFieldDefinitions())) {
                $query->condition('status', 1);
            }

            $ids = $query->range(0, $limit)->execute();
            $changefreqRaw = $typeOpts['changefreq'] ?? null;
            $changefreq = is_string($changefreqRaw) && $changefreqRaw !== '' ? $changefreqRaw : null;
            $priority = null;
            $priorityRaw = $typeOpts['priority'] ?? null;

            if (is_numeric($priorityRaw)) {
                $priority = (float) $priorityRaw;
            }

            foreach ($ids as $id) {
                $loc = $buildLoc($entityTypeId, $id);

                if ($loc === null || $loc === '') {
                    continue;
                }

                $out[] = new SitemapUrl(
                    loc: $loc,
                    changefreq: $changefreq,
                    priority: $priority,
                );
            }
        }

        return $out;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
