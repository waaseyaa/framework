<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Groups\Membership\GroupMembershipService;

/**
 * `groups:member-add <uid> <group>` — CW-v1 WP-4 membership write surface
 * (#1920, design decision 7).
 *
 * @api
 */
final class GroupsMemberAddHandler
{
    public function __construct(
        private readonly GroupMembershipService $membershipService,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $uid = (string) $io->argument('uid');
        $group = (string) $io->argument('group');

        try {
            $this->membershipService->addMember($uid, $group);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Added user %s as a member of group %s.', $uid, $group));

        return 0;
    }
}
