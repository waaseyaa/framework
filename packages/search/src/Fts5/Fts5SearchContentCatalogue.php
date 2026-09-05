<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Fts5;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchCataloguePage;
use Waaseyaa\Search\SearchCatalogueScanPosition;
use Waaseyaa\Search\SearchContentCatalogueInterface;

/** Bounded protected-index catalogue; raw rows are candidate pointers only. @api */
final class Fts5SearchContentCatalogue implements SearchContentCatalogueInterface
{
    private const int MAX_CANDIDATE_SCAN = 500;
    private const int MAX_VISIBLE_RESOURCES = 50;
    // A pathological index with more collisions conservatively under-returns;
    // it never widens access or performs unbounded work.
    private const int MAX_DIRECT_CANDIDATES = 50;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly SearchCandidateResolverInterface $candidateResolver,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function list(
        AuthorizationPrincipalInterface $principal,
        ?SearchCatalogueScanPosition $after = null,
    ): SearchCataloguePage {
        if (!$this->schemaExists()) {
            return new SearchCataloguePage([]);
        }

        $rows = $after === null
            ? $this->database->query(
                'SELECT document_id, entity_type, created_at FROM search_metadata '
                . 'ORDER BY created_at DESC, document_id ASC LIMIT :scanCap',
                ['scanCap' => self::MAX_CANDIDATE_SCAN],
            )
            : $this->database->query(
                'SELECT document_id, entity_type, created_at FROM search_metadata '
                . 'WHERE created_at < :createdAt '
                . 'OR (created_at = :createdAt AND document_id > :documentId) '
                . 'ORDER BY created_at DESC, document_id ASC LIMIT :scanCap',
                [
                    'createdAt' => $after->createdAt,
                    'documentId' => $after->documentId,
                    'scanCap' => self::MAX_CANDIDATE_SCAN,
                ],
            );

        $visible = [];
        $paths = [];
        $scanned = 0;
        $lastPosition = null;
        foreach ($rows as $row) {
            ++$scanned;
            $createdAt = (string) ($row['created_at'] ?? '');
            $documentId = (string) ($row['document_id'] ?? '');
            try {
                $lastPosition = new SearchCatalogueScanPosition($createdAt, $documentId);
            } catch (\InvalidArgumentException) {
                $this->logger->warning('Search content catalogue omitted a row with invalid scan coordinates.');
                continue;
            }

            $projection = $this->resolveRow($row, $principal);
            if ($projection === null || $projection->url === '' || isset($paths[$projection->url])) {
                continue;
            }
            $paths[$projection->url] = true;
            $visible[] = $projection;
            if (count($visible) === self::MAX_VISIBLE_RESOURCES) {
                break;
            }
        }

        $next = ($scanned === self::MAX_CANDIDATE_SCAN || count($visible) === self::MAX_VISIBLE_RESOURCES)
            && $lastPosition instanceof SearchCatalogueScanPosition
            ? $lastPosition
            : null;

        // A short final chunk under both caps has no continuation.
        if ($scanned < self::MAX_CANDIDATE_SCAN && count($visible) < self::MAX_VISIBLE_RESOURCES) {
            $next = null;
        }

        return new SearchCataloguePage($visible, $next);
    }

    public function readByPublicPath(
        string $publicPath,
        AuthorizationPrincipalInterface $principal,
    ): ?SearchCandidateProjection {
        if (!$this->schemaExists()) {
            return null;
        }

        $rows = $this->database->query(
            'SELECT document_id, entity_type FROM search_metadata '
            . 'WHERE url = :url ORDER BY document_id ASC LIMIT :scanCap',
            ['url' => $publicPath, 'scanCap' => self::MAX_DIRECT_CANDIDATES],
        );
        foreach ($rows as $row) {
            $projection = $this->resolveRow($row, $principal);
            if ($projection !== null && hash_equals($publicPath, $projection->url)) {
                return $projection;
            }
        }

        return null;
    }

    private function schemaExists(): bool
    {
        return iterator_to_array($this->database->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'search_metadata'",
        )) !== [];
    }

    /** @param array<string, mixed> $row */
    private function resolveRow(array $row, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
    {
        try {
            $reference = new SearchCandidateReference(
                (string) ($row['document_id'] ?? ''),
                (string) ($row['entity_type'] ?? ''),
            );
            $projection = $this->candidateResolver->resolve($reference, $principal);

            return $projection?->id === $reference->documentId ? $projection : null;
        } catch (\Throwable) {
            $this->logger->warning('Search content candidate resolution failed; candidate omitted.');

            return null;
        }
    }
}
