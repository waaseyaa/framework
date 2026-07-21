<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\StaffDirectory;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\SqlEntityQuery;

/** @internal Resolve and call StaffDirectoryReaderInterface from applications. */
final class StaffDirectoryReader implements StaffDirectoryReaderInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly StaffDirectoryReadDeclaration $declaration,
    ) {}

    public function list(AuthorizationPrincipalInterface $principal, int $offset = 0, int $limit = 50): StaffDirectoryPage
    {
        if (!$this->canRead($principal) || $offset < 0 || $limit < 1 || $limit > 100
            || !$this->entityTypeManager->hasDefinition('user')
        ) {
            return new StaffDirectoryPage([], 0);
        }
        $query = $this->entityTypeManager->getRepository('user')->getQuery();
        if (!$query instanceof SqlEntityQuery) {
            return new StaffDirectoryPage([], 0);
        }
        $page = $query
            ->withAccessHandler($this->accessHandler)
            ->requireContextualPolicy(CapabilityScopedStaffDirectoryReadPolicy::contextKeyFor($this->declaration))
            ->setAccount($principal)
            ->sort('uid', 'ASC')
            ->range($offset, $limit)
            ->executeEntityPage();

        return new StaffDirectoryPage($page->entities, $page->total);
    }

    public function detail(AuthorizationPrincipalInterface $principal, int|string $userId): ?EntityInterface
    {
        if (!$this->canRead($principal) || (string) $userId === ''
            || !$this->entityTypeManager->hasDefinition('user')
        ) {
            return null;
        }
        $query = $this->entityTypeManager->getRepository('user')->getQuery();
        if (!$query instanceof SqlEntityQuery) {
            return null;
        }
        $page = $query
            ->withAccessHandler($this->accessHandler)
            ->requireContextualPolicy(CapabilityScopedStaffDirectoryReadPolicy::contextKeyFor($this->declaration))
            ->setAccount($principal)
            ->condition('uid', $userId)
            ->range(0, 1)
            ->executeEntityPage();

        return $page->entities[0] ?? null;
    }

    private function canRead(AuthorizationPrincipalInterface $principal): bool
    {
        return $principal->isAuthenticated() && $principal->hasPermission($this->declaration->capability);
    }
}
