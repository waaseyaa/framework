<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Groups\Membership\GroupMembershipService;

/**
 * `groups:content-unassign <entity_type> <id> <group>` — CW-v1 WP-4
 * membership write surface (#1920, design decision 7). Soft-revokes; never
 * deletes the underlying relationship row (see
 * {@see GroupMembershipService::unassignContent()}).
 *
 * @api
 */
final class GroupsContentUnassignHandler
{
    public function __construct(
        private readonly GroupMembershipService $membershipService,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $entityType = (string) $io->argument('entity_type');
        $id = (string) $io->argument('id');
        $group = (string) $io->argument('group');

        try {
            $this->membershipService->unassignContent($entityType, $id, $group);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Unassigned %s/%s from group %s.', $entityType, $id, $group));

        return 0;
    }
}
