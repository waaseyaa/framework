<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Groups\GroupType;

/**
 * `groups:create <type> <name>` — CW-v1 WP-4 membership write surface
 * (#1920, design decision 7).
 *
 * Ensures the `group_type` config entity with id `<type>` exists (creating
 * it with label `ucfirst(type)` when missing, and saying so on stdout), then
 * creates a `group` entity in that bundle with label `<name>`. Prints the
 * new gid on success. Mirrors {@see EntityCreateHandler}'s
 * create-via-repository shape.
 *
 * @api
 */
final class GroupsCreateHandler
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $type = (string) $io->argument('type');
        $name = (string) $io->argument('name');

        if (!$this->entityTypeManager->hasDefinition('group_type') || !$this->entityTypeManager->hasDefinition('group')) {
            $io->error('The "group"/"group_type" entity types are not registered; is waaseyaa/groups booted?');

            return 1;
        }

        try {
            $this->ensureGroupType($io, $type);

            $groupRepository = $this->entityTypeManager->getRepository('group');
            $entity = $groupRepository->create([
                'type' => $type,
                'name' => $name,
            ]);
            $groupRepository->save($entity);
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to create group: %s', $e->getMessage()));

            return 1;
        }

        $io->writeln(sprintf('Created group "%s" (type: %s) with ID: %s', $name, $type, (string) $entity->id()));

        return 0;
    }

    private function ensureGroupType(SymfonyCommandIO $io, string $type): void
    {
        $groupTypeRepository = $this->entityTypeManager->getRepository('group_type');
        if ($groupTypeRepository->find($type) !== null) {
            return;
        }

        $label = ucfirst($type);
        $entity = $groupTypeRepository->create([
            'id' => $type,
            'label' => $label,
        ]);
        \assert($entity instanceof GroupType);
        $groupTypeRepository->save($entity);

        $io->writeln(sprintf('Created group_type "%s" (label: %s) — it did not exist yet.', $type, $label));
    }
}
