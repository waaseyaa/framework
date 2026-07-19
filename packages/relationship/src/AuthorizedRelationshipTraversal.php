<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Principal-scoped consumer facade over Protected relationship topology.
 *
 * Consumers pass domain traversal options, never field names or privileged
 * capability handles. The framework performs its fixed-shape topology query,
 * establishes the principal's ordinary field-read scope, and returns only
 * live edges whose source, relationship record, and related endpoint are all
 * viewable by that principal.
 *
 * @api
 */
final class AuthorizedRelationshipTraversal
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly EntityAccessHandler $accessHandler,
        private readonly AccountFieldReadScopeInterface $fieldReadScope,
    ) {}

    /**
     * @param array{
     *   direction?: 'outbound'|'inbound'|'both',
     *   relationship_types?: list<string>,
     *   at?: int|string|null,
     *   limit?: int|null
     * } $options
     * @return list<AuthorizedRelationshipEdge>
     */
    public function edges(
        AuthorizationPrincipalInterface $principal,
        string $sourceEntityType,
        int|string $sourceEntityId,
        array $options = [],
    ): array {
        return $this->fieldReadScope->run(
            $principal,
            fn(): array => $this->edgesInScope($principal, $sourceEntityType, (string) $sourceEntityId, $options),
        );
    }

    /**
     * @param array{
     *   direction?: 'outbound'|'inbound'|'both',
     *   relationship_types?: list<string>,
     *   at?: int|string|null,
     *   limit?: int|null
     * } $options
     * @return list<AuthorizedRelationshipEdge>
     */
    private function edgesInScope(
        AuthorizationPrincipalInterface $principal,
        string $sourceEntityType,
        string $sourceEntityId,
        array $options,
    ): array {
        if ($sourceEntityId === '' || !$this->entityTypeManager->hasDefinition($sourceEntityType)) {
            return [];
        }

        $source = $this->entityTypeManager->getRepository($sourceEntityType)->find($sourceEntityId);
        if ($source === null || !$this->accessHandler->check($source, 'view', $principal)->isAllowed()) {
            return [];
        }

        // `status: all` disables the publication-only visibility filter inside
        // the lower-level service; this facade replaces it with the stronger
        // per-principal endpoint gate wired below, then admits active edges
        // only. Callers cannot request the `all` bypass themselves.
        $browse = new RelationshipTraversalService(
            $this->entityTypeManager,
            $this->database,
            accessHandler: $this->accessHandler,
            account: $principal,
        )->browse($sourceEntityType, $sourceEntityId, [
            'relationship_types' => $options['relationship_types'] ?? [],
            'status' => 'all',
            'at' => $options['at'] ?? null,
            // Apply the consumer limit only after inactive and inaccessible
            // relationship records have been removed.
            'limit' => null,
        ]);

        $direction = $this->normalizeDirection($options['direction'] ?? 'both');
        $candidates = match ($direction) {
            'outbound' => $browse['outbound'],
            'inbound' => $browse['inbound'],
            default => [...$browse['outbound'], ...$browse['inbound']],
        };
        if ($candidates === []) {
            return [];
        }

        $relationshipIds = array_values(array_unique(array_map(
            static fn(array $edge): string => $edge['relationship_id'],
            $candidates,
        )));
        $relationships = [];
        foreach ($this->entityTypeManager->getRepository('relationship')->findMany($relationshipIds) as $relationship) {
            $relationships[(string) $relationship->id()] = $relationship;
        }

        $result = [];
        foreach ($candidates as $edge) {
            if ($edge['status'] !== 1) {
                continue;
            }

            $relationship = $relationships[$edge['relationship_id']] ?? null;
            if ($relationship === null || !$this->accessHandler->check($relationship, 'view', $principal)->isAllowed()) {
                continue;
            }

            $result[] = new AuthorizedRelationshipEdge(
                relationshipId: $edge['relationship_id'],
                relationshipType: $edge['relationship_type'],
                direction: $edge['direction'],
                inverse: $edge['inverse'],
                relatedEntityType: $edge['related_entity_type'],
                relatedEntityId: $edge['related_entity_id'],
                relatedEntityLabel: $edge['related_entity_label'],
                relatedEntityPath: $edge['related_entity_path'],
                directionality: $edge['directionality'],
                weight: is_float($edge['weight']) ? $edge['weight'] : null,
                confidence: is_float($edge['confidence']) ? $edge['confidence'] : null,
                startDate: is_int($edge['start_date']) ? $edge['start_date'] : null,
                endDate: is_int($edge['end_date']) ? $edge['end_date'] : null,
            );
        }

        $limit = $this->normalizeLimit($options['limit'] ?? null);

        return $limit === null ? $result : array_slice($result, 0, $limit);
    }

    /** @return 'outbound'|'inbound'|'both' */
    private function normalizeDirection(mixed $direction): string
    {
        return is_string($direction) && in_array($direction, ['outbound', 'inbound', 'both'], true)
            ? $direction
            : 'both';
    }

    private function normalizeLimit(mixed $limit): ?int
    {
        if (!is_int($limit) || $limit < 1) {
            return null;
        }

        return $limit;
    }
}
