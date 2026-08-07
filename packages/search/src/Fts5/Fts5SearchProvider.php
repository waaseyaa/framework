<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Fts5;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Search\FacetBucket;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchFacet;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchHit;
use Waaseyaa\Search\SearchIndexerInterface;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\SearchResult;

/**
 * Bounded FTS5 candidate generator with principal-safe canonical projection.
 *
 * @api
 */
final class Fts5SearchProvider implements SearchProviderInterface
{
    /** Public-query work bound, aligned with the agent surface result window. */
    private const int MAX_CANDIDATE_SCAN = 1_000;
    private const int SNIPPET_LENGTH = 240;

    private readonly LoggerInterface $logger;
    private bool $schemaReady = false;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly SearchIndexerInterface $indexer,
        private readonly SearchCandidateResolverInterface $candidateResolver,
        ?LoggerInterface $logger = null,
        private readonly float $titleWeight = 1.0,
        private readonly float $bodyWeight = 1.0,
    ) {
        if (!is_finite($titleWeight) || !is_finite($bodyWeight) || $titleWeight < 0.0 || $bodyWeight < 0.0 || ($titleWeight === 0.0 && $bodyWeight === 0.0)) {
            throw new \InvalidArgumentException('Search weights must be non-negative and not both zero.');
        }
        $this->logger = $logger ?? new NullLogger();
    }

    public function search(SearchRequest $request, AuthorizationPrincipalInterface $principal): SearchResult
    {
        $queryTokens = $this->queryTokens($request->query);
        if ($queryTokens === [] || !$this->schemaExists()) {
            return $this->emptyResult($request);
        }

        $ftsQuery = implode(' ', array_map(
            static fn(string $token): string => '"' . str_replace('"', '""', $token) . '"',
            $queryTokens,
        ));
        $rankExpression = $this->rankExpression();
        // One bounded statement gives this request a stable pointer snapshot:
        // no OFFSET race across concurrent lifecycle writes, no quadratic
        // rescans, and no unbounded projection/access-check amplification.
        // The extra row is a content-free truncation sentinel.
        $sql = "SELECT m.document_id, m.entity_type, m.schema_version FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE search_index MATCH :query ORDER BY $rankExpression, m.document_id ASC LIMIT :scanLimit";

        /** @var list<array{projection: SearchCandidateProjection, score: float}> $safeMatches */
        $safeMatches = [];
        $staleDetected = false;
        $schemaVersion = $this->indexer->getSchemaVersion();

        $candidateRows = iterator_to_array($this->database->query($sql, [
            'query' => $ftsQuery,
            'scanLimit' => self::MAX_CANDIDATE_SCAN + 1,
        ]), false);
        $isComplete = count($candidateRows) <= self::MAX_CANDIDATE_SCAN;
        if (!$isComplete) {
            array_pop($candidateRows);
            $this->logger->warning('Search candidate window exhausted; result totals and facets are lower bounds.', [
                'candidate_limit' => self::MAX_CANDIDATE_SCAN,
            ]);
        }

        foreach ($candidateRows as $row) {
            try {
                $reference = new SearchCandidateReference(
                    documentId: (string) ($row['document_id'] ?? ''),
                    entityType: (string) ($row['entity_type'] ?? ''),
                );
                $projection = $this->candidateResolver->resolve($reference, $principal);
                if ($projection === null || $projection->id !== $reference->documentId) {
                    continue;
                }
                $score = $this->safeScore($projection, $queryTokens);
                if ($score === null || !$this->matchesFilters($projection, $request->filters)) {
                    continue;
                }
            } catch (\Throwable) {
                $this->logger->warning('Search candidate resolution failed; candidate omitted.');
                continue;
            }
            if (($row['schema_version'] ?? null) !== $schemaVersion) {
                $staleDetected = true;
            }
            $safeMatches[] = ['projection' => $projection, 'score' => $score];
        }

        if ($staleDetected) {
            $this->logger->warning('Search index contains stale accessible documents. Run search:reindex to rebuild.');
        }

        $this->sortSafeMatches($safeMatches, $request->filters);
        $totalHits = count($safeMatches);
        if ($totalHits === 0) {
            return $this->emptyResult($request, $isComplete);
        }

        $offset = ($request->page - 1) * $request->pageSize;
        $page = array_slice($safeMatches, $offset, $request->pageSize);
        $hits = array_map(
            fn(array $match): SearchHit => $this->toHit($match['projection'], $match['score'], $queryTokens),
            $page,
        );

        return new SearchResult(
            totalHits: $totalHits,
            totalPages: (int) ceil($totalHits / $request->pageSize),
            currentPage: $request->page,
            pageSize: $request->pageSize,
            hits: $hits,
            facets: $request->includeFacets ? $this->facets($safeMatches) : [],
            isComplete: $isComplete,
        );
    }

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

    /** @return list<string> */
    private function queryTokens(string $query): array
    {
        $matched = preg_match_all("/[\\p{L}\\p{N}]+(?:['’ʼ][\\p{L}\\p{N}]+)*/u", mb_strtolower($query, 'UTF-8'), $matches);
        if ($matched === false || $matches[0] === []) {
            return [];
        }

        $tokens = [];
        $tokens = $matches[0];

        return array_values(array_unique($tokens));
    }

    /** @return list<string> */
    private function textTokens(string $text): array
    {
        $matched = preg_match_all("/[\\p{L}\\p{N}]+(?:['’ʼ][\\p{L}\\p{N}]+)*/u", mb_strtolower($text, 'UTF-8'), $matches);

        return $matched === false ? [] : $matches[0];
    }

    /** @param list<string> $queryTokens */
    private function safeScore(SearchCandidateProjection $projection, array $queryTokens): ?float
    {
        $titleCounts = array_count_values($this->textTokens($projection->title));
        $bodyCounts = array_count_values($this->textTokens($projection->body));
        $score = 0.0;

        foreach ($queryTokens as $token) {
            $titleCount = $titleCounts[$token] ?? 0;
            $bodyCount = $bodyCounts[$token] ?? 0;
            if ($titleCount + $bodyCount === 0) {
                return null;
            }
            $score += ($titleCount * $this->titleWeight) + ($bodyCount * $this->bodyWeight);
        }

        return $score;
    }

    private function matchesFilters(SearchCandidateProjection $projection, SearchFilters $filters): bool
    {
        if ($filters->contentType !== '' && $projection->contentType !== $filters->contentType) {
            return false;
        }
        if ($projection->qualityScore < $filters->minQuality) {
            return false;
        }
        if ($filters->sourceNames !== [] && !in_array($projection->sourceName, $filters->sourceNames, true)) {
            return false;
        }
        if ($filters->topics !== [] && array_intersect($projection->topics, $filters->topics) === []) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array{projection: SearchCandidateProjection, score: float}> $matches
     */
    private function sortSafeMatches(array &$matches, SearchFilters $filters): void
    {
        $direction = strtolower($filters->sortOrder) === 'asc' ? 1 : -1;
        usort($matches, static function (array $left, array $right) use ($filters, $direction): int {
            $a = $left['projection'];
            $b = $right['projection'];
            $comparison = match ($filters->sortField) {
                'created_at' => strcmp($a->crawledAt, $b->crawledAt),
                'quality_score' => $a->qualityScore <=> $b->qualityScore,
                'entity_type' => strcmp($a->entityType, $b->entityType),
                'content_type' => strcmp($a->contentType, $b->contentType),
                default => $left['score'] <=> $right['score'],
            };
            if ($comparison !== 0) {
                return $comparison * $direction;
            }

            return strcmp($a->id, $b->id);
        });
    }

    /** @param list<string> $queryTokens */
    private function toHit(SearchCandidateProjection $projection, float $score, array $queryTokens): SearchHit
    {
        return new SearchHit(
            id: $projection->id,
            title: $projection->title,
            url: $projection->url,
            sourceName: $projection->sourceName,
            crawledAt: $projection->crawledAt,
            qualityScore: $projection->qualityScore,
            contentType: $projection->contentType,
            topics: $projection->topics,
            score: $score,
            ogImage: $projection->ogImage,
            highlight: $this->snippet($projection, $queryTokens),
        );
    }

    /** @param list<string> $queryTokens */
    private function snippet(SearchCandidateProjection $projection, array $queryTokens): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $projection->body));
        if ($text === '') {
            $text = trim((string) preg_replace('/\s+/u', ' ', $projection->title));
        }
        if ($text === '') {
            return '';
        }

        $position = null;
        foreach ($queryTokens as $token) {
            $candidate = mb_stripos($text, $token, 0, 'UTF-8');
            if ($candidate !== false && ($position === null || $candidate < $position)) {
                $position = $candidate;
            }
        }
        $start = max(0, ($position ?? 0) - 80);
        $snippet = mb_substr($text, $start, self::SNIPPET_LENGTH, 'UTF-8');

        return ($start > 0 ? '…' : '') . $snippet
            . ($start + mb_strlen($snippet, 'UTF-8') < mb_strlen($text, 'UTF-8') ? '…' : '');
    }

    /**
     * @param list<array{projection: SearchCandidateProjection, score: float}> $matches
     * @return list<SearchFacet>
     */
    private function facets(array $matches): array
    {
        $contentTypes = [];
        $sourceNames = [];
        $topics = [];
        foreach ($matches as $match) {
            $projection = $match['projection'];
            if ($projection->contentType !== '') {
                $contentTypes[$projection->contentType] = ($contentTypes[$projection->contentType] ?? 0) + 1;
            }
            if ($projection->sourceName !== '') {
                $sourceNames[$projection->sourceName] = ($sourceNames[$projection->sourceName] ?? 0) + 1;
            }
            foreach ($projection->topics as $topic) {
                $topics[$topic] = ($topics[$topic] ?? 0) + 1;
            }
        }

        $facets = [];
        foreach (['content_type' => $contentTypes, 'source_name' => $sourceNames, 'topics' => $topics] as $name => $counts) {
            if ($counts === []) {
                continue;
            }
            arsort($counts);
            $buckets = [];
            foreach ($counts as $key => $count) {
                $buckets[] = new FacetBucket($key, $count);
            }
            $facets[] = new SearchFacet($name, $buckets);
        }

        return $facets;
    }

    private function rankExpression(): string
    {
        if ($this->titleWeight === 1.0 && $this->bodyWeight === 1.0) {
            return 'si.rank';
        }

        return sprintf(
            'bm25(search_index, 0.0, %s, %s)',
            number_format($this->titleWeight, 6, '.', ''),
            number_format($this->bodyWeight, 6, '.', ''),
        );
    }

    private function emptyResult(SearchRequest $request, bool $isComplete = true): SearchResult
    {
        return new SearchResult(
            totalHits: 0,
            totalPages: 0,
            currentPage: $request->page,
            pageSize: $request->pageSize,
            hits: [],
            isComplete: $isComplete,
        );
    }
}
