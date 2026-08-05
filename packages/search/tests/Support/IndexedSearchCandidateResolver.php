<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Support;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;

/** Test-only raw-index adapter for tests of FTS mechanics, never production access. */
final readonly class IndexedSearchCandidateResolver implements SearchCandidateResolverInterface
{
    /** @param (\Closure(SearchCandidateReference, AuthorizationPrincipalInterface): bool)|null $allows */
    public function __construct(
        private DatabaseInterface $database,
        private ?\Closure $allows = null,
    ) {}

    public function resolve(
        SearchCandidateReference $reference,
        AuthorizationPrincipalInterface $principal,
    ): ?SearchCandidateProjection {
        if ($this->allows !== null && !($this->allows)($reference, $principal)) {
            return null;
        }
        $rows = iterator_to_array($this->database->query(
            'SELECT m.*, si.title, si.body FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE m.document_id = ?',
            [$reference->documentId],
        ));
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $topics = json_decode((string) ($row['topics'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($topics) || !array_is_list($topics)) {
            return null;
        }

        return new SearchCandidateProjection(
            id: $reference->documentId,
            entityType: $reference->entityType,
            title: (string) ($row['title'] ?? ''),
            body: (string) ($row['body'] ?? ''),
            url: (string) ($row['url'] ?? ''),
            sourceName: (string) ($row['source_name'] ?? ''),
            crawledAt: (string) ($row['created_at'] ?? ''),
            qualityScore: (int) ($row['quality_score'] ?? 0),
            contentType: (string) ($row['content_type'] ?? ''),
            topics: $topics,
            ogImage: (string) ($row['og_image'] ?? ''),
        );
    }
}
