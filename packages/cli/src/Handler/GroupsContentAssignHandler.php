<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Groups\Membership\GroupMembershipService;

/**
 * `groups:content-assign <entity_type> <id> <group>` — CW-v1 WP-4
 * membership write surface (#1920, design decision 7).
 *
 * @api
 */
final class GroupsContentAssignHandler
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
            $this->membershipService->assignContent($entityType, $id, $group);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Assigned %s/%s to group %s.', $entityType, $id, $group));

        return 0;
    }
}
