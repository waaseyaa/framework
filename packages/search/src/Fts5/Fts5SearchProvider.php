<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Fts5;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Search\Access\SearchAccessChecker;
use Waaseyaa\Search\FacetBucket;
use Waaseyaa\Search\SearchFacet;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchHit;
use Waaseyaa\Search\SearchIndexerInterface;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\SearchResult;

/** @internal Parked until a first-party read endpoint adopts this provider. */
final class Fts5SearchProvider implements SearchProviderInterface
{
    private const ALLOWED_SORT_COLUMNS = ['created_at', 'quality_score', 'entity_type', 'content_type'];

    /**
     * Maximum number of candidate rows examined when deriving access-filtered
     * totalHits and facets (the checker path). Limits entity loads for broad
     * anonymous queries. When the candidate set exceeds this cap the filtered
     * count is conservative (under-count of the window), never an over-count,
     * so the existence oracle stays closed.
     */
    private const MAX_ACCESS_SCAN = 1000;

    private readonly LoggerInterface $logger;

    /**
     * Cached positive result of the FTS5 schema-existence probe. Since
     * {@see Fts5SearchIndexer::ensureSchema()} is now lazy (created on first
     * write, not at boot — audit D-35), a read on a never-written index must
     * not hit "no such table". We cache only the `true` result (the schema
     * never disappears once created) and re-probe while absent so a write that
     * lands later in the same process is picked up.
     */
    private bool $schemaReady = false;

    /**
     * @param ?SearchAccessChecker $accessChecker When set, every candidate hit
     *        is filtered through it so the read path enforces per-document
     *        access. When null (e.g. a doc-only index in a minimal setup) no
     *        filtering is applied — the canonical {@see \Waaseyaa\Search\SearchServiceProvider}
     *        wiring always supplies one.
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly SearchIndexerInterface $indexer,
        ?LoggerInterface $logger = null,
        private readonly float $titleWeight = 1.0,
        private readonly float $bodyWeight = 1.0,
        private readonly ?SearchAccessChecker $accessChecker = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Cheap existence probe for the FTS5 schema, so a read on a never-written
     * (lazily-initialised) index degrades to empty instead of erroring.
     */
    private function schemaExists(): bool
    {
        if ($this->schemaReady) {
            return true;
        }
        $rows = iterator_to_array($this->database->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'search_index'",
        ));

        return $this->schemaReady = $rows !== [];
    }

    public function search(SearchRequest $request): SearchResult
    {
        $startTime = hrtime(true);

        $query = $this->escapeQuery($request->query);
        if ($query === '') {
            return SearchResult::empty();
        }

        // D-35: schema is created lazily on first write. A search against an
        // index that has never been written returns empty rather than throwing
        // "no such table: search_index".
        if (!$this->schemaExists()) {
            return SearchResult::empty();
        }

        $params = [];
        $whereClauses = ['search_index MATCH :query'];
        $params['query'] = $query;

        $this->applyFilters($request->filters, $whereClauses, $params);

        $whereSQL = implode(' AND ', $whereClauses);

        // Sort. Relevance uses the FTS5 bm25 rank, optionally re-weighted so a
        // title-column match outranks a body-only match (see rankExpression()).
        // Computed up front (not just for the fetch) because the access-filtered
        // path below must scan and paginate in this SAME order.
        $rankExpr = $this->rankExpression();
        $orderBy = $rankExpr;
        if ($request->filters->sortField !== 'relevance' && in_array($request->filters->sortField, self::ALLOWED_SORT_COLUMNS, true)) {
            $direction = strtoupper($request->filters->sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            $orderBy = "m.{$request->filters->sortField} $direction";
        }

        // Count total hits, the requested page's rows, and facets.
        //
        // When no access checker is wired (doc-only index, no filtering anywhere)
        // use the fast SQL COUNT + LIMIT/OFFSET + buildFacets() path unchanged —
        // no behaviour or performance change.
        //
        // When a checker is wired, totalHits, the page, AND facets must all
        // reflect only the documents the acting account may view — and, just as
        // importantly, must all come from the SAME ordered, access-filtered
        // basis. Deriving totalHits/facets from one access-filtered scan while
        // fetching the page from an independent, unfiltered ORDER BY/LIMIT/OFFSET
        // query (the pre-#1915-R16 shape) let a fixed-size OFFSET walk the wrong
        // sequence: an accessible document sharing a page window with a
        // forbidden one was silently dropped from every page, while totalPages
        // (measured against the filtered count) promised a page that could never
        // fully materialize. accessFilteredSearch() below uses ONE bounded,
        // ordered, access-filtered basis to select page IDs, then fetches full
        // rows/snippets only for those IDs, so paging always agrees with the
        // total it is measured against.
        if ($this->accessChecker !== null) {
            [$totalHits, $pageRows, $facets] = $this->accessFilteredSearch(
                $whereSQL,
                $params,
                $orderBy,
                $rankExpr,
                $request->page,
                $request->pageSize,
                $request->includeFacets,
            );
        } else {
            $countSQL = "SELECT COUNT(*) as cnt FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL";
            $countRows = iterator_to_array($this->database->query($countSQL, $params));
            $totalHits = (int) ($countRows[0]['cnt'] ?? 0);

            $fetchParams = $params;
            $fetchParams['limit'] = $request->pageSize;
            $fetchParams['offset'] = ($request->page - 1) * $request->pageSize;
            $sql = "SELECT m.*, si.title, snippet(search_index, 2, '<b>', '</b>', '…', 32) as highlight, $rankExpr as rank FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL ORDER BY $orderBy LIMIT :limit OFFSET :offset";
            $pageRows = iterator_to_array($this->database->query($sql, $fetchParams));

            // Facets — run the three GROUP BY queries here, gated behind
            // includeFacets (audit D-36: skips the json_each cross-join for
            // callers that never render facets).
            $facets = $request->includeFacets ? $this->buildFacets($whereSQL, $params) : [];
        }

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

        $hits = [];
        $staleDetected = false;
        $currentVersion = $this->indexer->getSchemaVersion();

        foreach ($pageRows as $row) {
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

    /**
     * The relevance ORDER BY / score expression. With the default per-column
     * weights (1.0/1.0) this is FTS5's built-in `rank` (bm25 with equal column
     * weights) — byte-identical to prior behaviour, so existing consumers are
     * unaffected. When a caller passes non-default weights, the bm25()
     * auxiliary function applies them per column so a title-column match
     * outranks a body-only match. The FTS5 search_index table has three
     * columns in declaration order (document_id UNINDEXED, title, body), and
     * bm25() takes one weight per column, so the UNINDEXED document_id is
     * pinned to 0.0 (it never contributes a match anyway).
     */
    private function rankExpression(): string
    {
        if ($this->titleWeight === 1.0 && $this->bodyWeight === 1.0) {
            return 'si.rank';
        }

        return sprintf(
            'bm25(search_index, 0.0, %s, %s)',
            $this->weightLiteral($this->titleWeight),
            $this->weightLiteral($this->bodyWeight),
        );
    }

    /**
     * A locale-independent fixed-point literal for inlining into the bm25()
     * call. The weights are floats from the constructor (never user input), and
     * FTS5 auxiliary-function arguments cannot be bound parameters, so they are
     * formatted as a plain decimal — no exponent form, no locale separators
     * that SQLite would reject.
     */
    private function weightLiteral(float $weight): string
    {
        return number_format($weight, 6, '.', '');
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
     * Runs a bounded, ordered, access-filtered ID/rank scan and derives
     * totalHits, requested page IDs, and (optionally) facets from the SAME
     * filtered basis, then fetches full rows/snippets only for those approved
     * page IDs (#1915 R16; #2010 R21). Previously totalHits/facets came from an
     * access-filtered scan while the page was fetched by an independent,
     * unfiltered `ORDER BY ... LIMIT/OFFSET` query over ALL candidate rows
     * (forbidden included), so a fixed-size OFFSET walked the wrong sequence:
     * an accessible document sharing a page window with a forbidden one was
     * silently dropped from every page, while totalPages (measured against
     * the filtered count) promised a page that could never fully materialize.
     *
     * The scan is bounded by MAX_ACCESS_SCAN to prevent unbounded entity loads
     * on broad anonymous queries. When capped, totalHits (and therefore the
     * pages reachable from it) is a conservative under-count — never an
     * over-count — so the existence oracle stays closed; a page whose offset
     * falls beyond the scanned window simply returns fewer than pageSize rows
     * rather than fabricating more.
     *
     * @param array<string,mixed> $params
     * @return array{0:int,1:list<array<string,mixed>>,2:SearchFacet[]}
     */
    private function accessFilteredSearch(
        string $whereSQL,
        array $params,
        string $orderBy,
        string $rankExpr,
        int $page,
        int $pageSize,
        bool $buildFacets,
    ): array {
        // Remove pagination params — this is the bounded, ordered full-candidate scan.
        unset($params['limit'], $params['offset']);

        $sql = "SELECT m.document_id, m.entity_type, m.content_type, m.source_name, m.quality_score, m.topics, m.created_at, $rankExpr as rank FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL ORDER BY $orderBy LIMIT :scanCap";
        $params['scanCap'] = self::MAX_ACCESS_SCAN;

        $offset = ($page - 1) * $pageSize;

        $totalHits = 0;
        $pageIds = [];
        $contentTypeCounts = [];
        $sourceNameCounts = [];
        $topicCounts = [];

        foreach ($this->database->query($sql, $params) as $row) {
            $documentId = (string) $row['document_id'];
            $entityType = (string) ($row['entity_type'] ?? '');

            if (!$this->accessChecker->canView($documentId, $entityType)) {
                continue;
            }

            // $totalHits doubles as the accessible row's 0-based rank position
            // in the filtered, ordered sequence: it lands on the requested
            // page iff that position falls inside [offset, offset + pageSize).
            if ($totalHits >= $offset && $totalHits < $offset + $pageSize) {
                $pageIds[] = $documentId;
            }

            ++$totalHits;

            if (!$buildFacets) {
                continue;
            }

            $contentType = (string) ($row['content_type'] ?? '');
            if ($contentType !== '') {
                $contentTypeCounts[$contentType] = ($contentTypeCounts[$contentType] ?? 0) + 1;
            }

            $sourceName = (string) ($row['source_name'] ?? '');
            if ($sourceName !== '') {
                $sourceNameCounts[$sourceName] = ($sourceNameCounts[$sourceName] ?? 0) + 1;
            }

            $topics = json_decode((string) ($row['topics'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($topics)) {
                foreach ($topics as $topic) {
                    $topic = (string) $topic;
                    $topicCounts[$topic] = ($topicCounts[$topic] ?? 0) + 1;
                }
            }
        }

        $facets = [];

        if ($buildFacets) {
            if ($contentTypeCounts !== []) {
                arsort($contentTypeCounts);
                $buckets = [];
                foreach ($contentTypeCounts as $key => $count) {
                    $buckets[] = new FacetBucket($key, $count);
                }
                $facets[] = new SearchFacet('content_type', $buckets);
            }

            if ($sourceNameCounts !== []) {
                arsort($sourceNameCounts);
                $buckets = [];
                foreach ($sourceNameCounts as $key => $count) {
                    $buckets[] = new FacetBucket($key, $count);
                }
                $facets[] = new SearchFacet('source_name', $buckets);
            }

            if ($topicCounts !== []) {
                arsort($topicCounts);
                $buckets = [];
                foreach ($topicCounts as $key => $count) {
                    $buckets[] = new FacetBucket($key, $count);
                }
                $facets[] = new SearchFacet('topics', $buckets);
            }
        }

        $pageRows = $this->fetchAccessFilteredPage($whereSQL, $params, $orderBy, $rankExpr, $pageIds);

        return [$totalHits, $pageRows, $facets];
    }

    /**
     * Fetch full metadata, title, and snippet only for the access-approved page.
     *
     * @param array<string,mixed> $params
     * @param list<string> $pageIds
     * @return list<array<string,mixed>>
     */
    private function fetchAccessFilteredPage(
        string $whereSQL,
        array $params,
        string $orderBy,
        string $rankExpr,
        array $pageIds,
    ): array {
        if ($pageIds === []) {
            return [];
        }

        unset($params['scanCap'], $params['limit'], $params['offset']);
        $placeholders = [];
        foreach ($pageIds as $index => $documentId) {
            $key = 'page_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $documentId;
        }

        $sql = "SELECT m.*, si.title, snippet(search_index, 2, '<b>', '</b>', '…', 32) as highlight, $rankExpr as rank FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL AND m.document_id IN (" . implode(', ', $placeholders) . ") ORDER BY $orderBy";
        $rows = iterator_to_array($this->database->query($sql, $params));

        // The SQL ORDER BY is the canonical order. Re-apply the already chosen
        // page order defensively so a database planner's IN handling cannot
        // perturb pagination semantics.
        $positions = array_flip($pageIds);
        usort($rows, static fn(array $a, array $b): int => $positions[(string) $a['document_id']] <=> $positions[(string) $b['document_id']]);

        return $rows;
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
