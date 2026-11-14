<?php

declare(strict_types=1);

namespace EngagementConsumer;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Engagement\EngagementPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

final class SyntheticEngagementPolicy implements EngagementPolicyInterface
{
    public function __construct(private readonly DBALDatabase $database) {}
    public function databaseIdentity(): string
    {
        return $this->database->databaseIdentity();
    }
    private function state(): array
    {
        return $this->database->getConnection()->fetchAssociative('SELECT * FROM engagement_fixture_policy WHERE id = 1') ?: [];
    }
    public function generation(AuthorizationPrincipalInterface $viewer): string
    {
        return (string) ($this->state()['generation'] ?? '0');
    }
    public function canViewRoot(AuthorizationPrincipalInterface $viewer, EntityInterface $root): bool
    {
        return $root instanceof SyntheticPost && (int) ($this->state()['root_visible'] ?? 0) === 1;
    }
    public function isEligible(AuthorizationPrincipalInterface $actor): bool
    {
        $id = (string) $actor->id();
        return in_array($id, ['1', '2'], true)
            && ($id !== '1' || (int) ($this->state()['alice_suspended'] ?? 0) === 0);
    }
    public function canInteract(AuthorizationPrincipalInterface $actor, EntityInterface $root, string $operation): bool
    {
        return in_array($operation, ['like', 'comment', 'edit', 'remove'], true)
            && $this->isEligible($actor) && $this->canViewRoot($actor, $root);
    }
    public function canViewActor(AuthorizationPrincipalInterface $viewer, int $authorId, EntityInterface $root): bool
    {
        return $this->canViewRoot($viewer, $root)
            && !((string) $viewer->id() === '2' && $authorId === 1 && (int) ($this->state()['alice_blocked'] ?? 0) === 1);
    }
    public function canModerate(AuthorizationPrincipalInterface $actor, EntityInterface $root): bool
    {
        return (string) $actor->id() === '2' && (int) ($this->state()['bob_moderator'] ?? 0) === 1 && $this->canViewRoot($actor, $root);
    }
}
