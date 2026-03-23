<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Fts5;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Search\FacetBucket;
use Waaseyaa\Search\SearchFacet;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchHit;
use Waaseyaa\Search\SearchIndexerInterface;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\SearchResult;

final class Fts5SearchProvider implements SearchProviderInterface
{
    private const ALLOWED_SORT_COLUMNS = ['created_at', 'quality_score', 'entity_type', 'content_type'];
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly SearchIndexerInterface $indexer,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function search(SearchRequest $request): SearchResult
    {
        $startTime = hrtime(true);

        $query = $this->escapeQuery($request->query);
        if ($query === '') {
            return SearchResult::empty();
        }

        $params = [];
        $whereClauses = ['search_index MATCH :query'];
        $params['query'] = $query;

        $this->applyFilters($request->filters, $whereClauses, $params);

        $whereSQL = implode(' AND ', $whereClauses);

        // Count total hits
        $countSQL = "SELECT COUNT(*) as cnt FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL";
        $countRows = iterator_to_array($this->database->query($countSQL, $params));
        $totalHits = (int) ($countRows[0]['cnt'] ?? 0);

        if ($totalHits === 0) {
            $tookMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            return new SearchResult(
                totalHits: 0,
                totalPages: 0,
                currentPage: $request->page,
                pageSize: $request->pageSize,
                tookMs: $tookMs,
                hits: [],
            );
        }

        $totalPages = (int) ceil($totalHits / $request->pageSize);
        $offset = ($request->page - 1) * $request->pageSize;

        // Sort
        $orderBy = 'si.rank';
        if ($request->filters->sortField !== 'relevance' && in_array($request->filters->sortField, self::ALLOWED_SORT_COLUMNS, true)) {
            $direction = strtoupper($request->filters->sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            $orderBy = "m.{$request->filters->sortField} $direction";
        }

        // Fetch page
        $sql = "SELECT m.*, si.title, snippet(search_index, 2, '<b>', '</b>', '…', 32) as highlight, si.rank FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL ORDER BY $orderBy LIMIT :limit OFFSET :offset";
        $params['limit'] = $request->pageSize;
        $params['offset'] = $offset;

        $hits = [];
        $staleDetected = false;
        $currentVersion = $this->indexer->getSchemaVersion();

        foreach ($this->database->query($sql, $params) as $row) {
            if ($row['schema_version'] !== $currentVersion) {
                $staleDetected = true;
            }

            $topics = json_decode($row['topics'], true, 512, JSON_THROW_ON_ERROR);

            $hits[] = new SearchHit(
                id: $row['document_id'],
                title: $row['title'] ?? '',
                url: $row['url'] ?? '',
                sourceName: $row['source_name'] ?? '',
                crawledAt: $row['created_at'] ?? '',
                qualityScore: (int) ($row['quality_score'] ?? 0),
                contentType: $row['content_type'] ?? '',
                topics: $topics,
                score: abs((float) ($row['rank'] ?? 0.0)),
                ogImage: $row['og_image'] ?? '',
                highlight: $row['highlight'] ?? '',
            );
        }

        if ($staleDetected) {
            $this->logger->warning('Search index contains stale documents. Run search:reindex to rebuild.');
        }

        // Facets — run on the full filtered result set (not just the page)
        $facets = $this->buildFacets($whereSQL, $params);

        $tookMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return new SearchResult(
            totalHits: $totalHits,
            totalPages: $totalPages,
            currentPage: $request->page,
            pageSize: $request->pageSize,
            tookMs: $tookMs,
            hits: $hits,
            facets: $facets,
        );
    }

    private function escapeQuery(string $query): string
    {
        // Remove FTS5 operators and special characters to prevent query injection
        $query = preg_replace('/\b(AND|OR|NOT|NEAR)\b/i', '', $query);
        $query = preg_replace('/[*^{}:"]/', '', $query);
        $query = trim(preg_replace('/\s+/', ' ', $query));

        if ($query === '') {
            return '';
        }

        // Quote individual terms for safety
        $terms = array_filter(explode(' ', $query), fn(string $t): bool => $t !== '');
        $quoted = array_map(fn(string $term): string => '"' . str_replace('"', '""', $term) . '"', $terms);

        return implode(' ', $quoted);
    }

    private function applyFilters(SearchFilters $filters, array &$whereClauses, array &$params): void
    {
        if ($filters->contentType !== '') {
            $whereClauses[] = 'm.content_type = :contentType';
            $params['contentType'] = $filters->contentType;
        }

        if ($filters->minQuality > 0) {
            $whereClauses[] = 'm.quality_score >= :minQuality';
            $params['minQuality'] = $filters->minQuality;
        }

        if ($filters->sourceNames !== []) {
            $placeholders = [];
            foreach ($filters->sourceNames as $i => $name) {
                $key = "source_$i";
                $placeholders[] = ":$key";
                $params[$key] = $name;
            }
            $whereClauses[] = 'm.source_name IN (' . implode(', ', $placeholders) . ')';
        }

        if ($filters->topics !== []) {
            $placeholders = [];
            foreach ($filters->topics as $i => $topic) {
                $key = "topic_$i";
                $placeholders[] = ":$key";
                $params[$key] = $topic;
            }
            $whereClauses[] = 'EXISTS (SELECT 1 FROM json_each(m.topics) WHERE value IN (' . implode(', ', $placeholders) . '))';
        }
    }

    /**
     * @return SearchFacet[]
     */
    private function buildFacets(string $whereSQL, array $params): array
    {
        // Remove pagination params for facet queries
        unset($params['limit'], $params['offset']);

        $facets = [];

        // Content type facet
        $sql = "SELECT m.content_type as facet_key, COUNT(*) as cnt FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL AND m.content_type != '' GROUP BY m.content_type ORDER BY cnt DESC";
        $buckets = [];
        foreach ($this->database->query($sql, $params) as $row) {
            $buckets[] = new FacetBucket($row['facet_key'], (int) $row['cnt']);
        }
        if ($buckets !== []) {
            $facets[] = new SearchFacet('content_type', $buckets);
        }

        // Source name facet
        $sql = "SELECT m.source_name as facet_key, COUNT(*) as cnt FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL AND m.source_name != '' GROUP BY m.source_name ORDER BY cnt DESC";
        $buckets = [];
        foreach ($this->database->query($sql, $params) as $row) {
            $buckets[] = new FacetBucket($row['facet_key'], (int) $row['cnt']);
        }
        if ($buckets !== []) {
            $facets[] = new SearchFacet('source_name', $buckets);
        }

        // Topics facet
        $sql = "SELECT je.value as facet_key, COUNT(*) as cnt FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id, json_each(m.topics) je WHERE $whereSQL GROUP BY je.value ORDER BY cnt DESC";
        $buckets = [];
        foreach ($this->database->query($sql, $params) as $row) {
            $buckets[] = new FacetBucket($row['facet_key'], (int) $row['cnt']);
        }
        if ($buckets !== []) {
            $facets[] = new SearchFacet('topics', $buckets);
        }

        return $facets;
    }
}
