<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Access;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchIndexableInterface;

/** Canonical entity-backed candidate resolver. @api */
final readonly class EntitySearchCandidateResolver implements SearchCandidateResolverInterface
{
    public function __construct(
        private EntityTypeManagerInterface $entityTypeManager,
        private EntityAccessHandler $accessHandler,
        private AccountFieldReadScopeInterface $fieldReadScope,
    ) {}

    public function resolve(
        SearchCandidateReference $reference,
        AuthorizationPrincipalInterface $principal,
    ): ?SearchCandidateProjection {
        if (
            $reference->entityType === ''
            || !$this->entityTypeManager->hasDefinition($reference->entityType)
        ) {
            return null;
        }
        $prefix = $reference->entityType . ':';
        if (!str_starts_with($reference->documentId, $prefix)) {
            return null;
        }
        $entityId = substr($reference->documentId, strlen($prefix));
        if ($entityId === '') {
            return null;
        }

        $entity = $this->entityTypeManager->getRepository($reference->entityType)->find($entityId);
        if (!$entity instanceof SearchIndexableInterface) {
            return null;
        }
        if (!$this->accessHandler->check($entity, 'view', $principal)->isAllowed()) {
            return null;
        }

        try {
            $resolved = $this->fieldReadScope->run($principal, static fn(): array => [
                $entity->getSearchDocumentId(),
                $entity->toSearchDocument(),
                $entity->toSearchMetadata(),
            ]);
        } catch (FieldReadDenied|MissingFieldReadContext) {
            return null;
        }

        [$documentId, $document, $metadata] = $resolved;
        if ($documentId !== $reference->documentId) {
            return null;
        }

        return $this->projection($documentId, $reference->entityType, $document, $metadata);
    }

    /** @param array<string, mixed> $document @param array<string, mixed> $metadata */
    private function projection(
        string $documentId,
        string $entityType,
        array $document,
        array $metadata,
    ): ?SearchCandidateProjection {
        $title = $document['title'] ?? '';
        $body = $document['body'] ?? '';
        $topics = $metadata['topics'] ?? [];
        if (!is_string($title) || !is_string($body) || !is_array($topics) || !array_is_list($topics)) {
            return null;
        }
        $title = self::boundedText($title, SearchCandidateProjection::MAX_TITLE_LENGTH);
        $body = self::boundedText($body, SearchCandidateProjection::MAX_BODY_LENGTH);
        $safeTopics = [];
        foreach (array_slice($topics, 0, SearchCandidateProjection::MAX_TOPICS) as $topic) {
            if (is_string($topic)) {
                $safeTopics[] = self::boundedText($topic, SearchCandidateProjection::MAX_TOPIC_LENGTH);
            }
        }
        $safeTopics = array_values(array_unique($safeTopics));

        $strings = [];
        foreach (['url', 'source_name', 'created_at', 'content_type', 'og_image'] as $key) {
            $value = $metadata[$key] ?? '';
            $strings[$key] = is_string($value)
                ? self::boundedText($value, SearchCandidateProjection::MAX_METADATA_LENGTH)
                : '';
        }
        $qualityScore = $metadata['quality_score'] ?? 0;
        if (is_float($qualityScore) && is_finite($qualityScore)) {
            $qualityScore = (int) round($qualityScore);
        } elseif (is_string($qualityScore) && preg_match('/^\d{1,3}$/', $qualityScore) === 1) {
            $qualityScore = (int) $qualityScore;
        } elseif (!is_int($qualityScore)) {
            $qualityScore = 0;
        }
        $qualityScore = max(0, min(100, $qualityScore));

        try {
            return new SearchCandidateProjection(
                id: $documentId,
                entityType: $entityType,
                title: $title,
                body: $body,
                url: $strings['url'],
                sourceName: $strings['source_name'],
                crawledAt: $strings['created_at'],
                qualityScore: $qualityScore,
                contentType: $strings['content_type'],
                topics: $safeTopics,
                ogImage: $strings['og_image'],
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function boundedText(string $value, int $maximum): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return mb_substr($value, 0, $maximum, 'UTF-8');
    }
}
