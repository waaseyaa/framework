<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Fence;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DBALDatabase;

/** Transactional last-accepted-fence authority for database-local effects. */
final class DatabaseFenceGuard implements FenceGuardInterface
{
    private const string TABLE = 'waaseyaa_scheduler_effect_fences';

    public function __construct(private readonly DBALDatabase $database) {}

    public function execute(string $resourceKey, string $fenceDomain, int $fence, string $effectId, \Closure $effect): bool
    {
        if ($resourceKey === '' || $fenceDomain === '' || $effectId === '' || $fence < 1) {
            throw new \InvalidArgumentException('A fenced effect requires resource, domain, positive fence, and effect id.');
        }

        return $this->database->transactional(function () use ($resourceKey, $fenceDomain, $fence, $effectId, $effect): bool {
            $row = $this->row($resourceKey, $fenceDomain);
            if ($row === null) {
                try {
                    $this->database->insert(self::TABLE)->values([
                        'resource_key' => $resourceKey,
                        'fence_domain' => $fenceDomain,
                        'accepted_fence' => $fence,
                        'effect_id' => $effectId,
                    ])->execute();
                } catch (UniqueConstraintViolationException $exception) {
                    throw new StaleFenceException('The resource fence changed concurrently.', previous: $exception);
                }
            } else {
                $accepted = (int) $row['accepted_fence'];
                if ($fence < $accepted) {
                    throw new StaleFenceException('A stale scheduler fence cannot mutate this resource.');
                }
                if ($fence === $accepted) {
                    if (hash_equals((string) $row['effect_id'], $effectId)) {
                        return false;
                    }
                    throw new StaleFenceException('Distinct effects at one fence require explicit ordering.');
                }
                $affected = $this->database->update(self::TABLE)->fields([
                    'accepted_fence' => $fence,
                    'effect_id' => $effectId,
                ])->condition('resource_key', $resourceKey)
                    ->condition('fence_domain', $fenceDomain)
                    ->condition('accepted_fence', $accepted)
                    ->condition('effect_id', (string) $row['effect_id'])
                    ->execute();
                if ($affected !== 1) {
                    throw new StaleFenceException('The resource fence changed concurrently.');
                }
                if (hash_equals((string) $row['effect_id'], $effectId)) {
                    // Recovery under a higher lease fence advances ownership
                    // without replaying an effect already committed by this
                    // deterministic occurrence.
                    return false;
                }
            }

            $effect();

            return true;
        });
    }

    /** @return array<string, mixed>|null */
    private function row(string $resourceKey, string $fenceDomain): ?array
    {
        $row = $this->database->getConnection()->fetchAssociative(
            'SELECT accepted_fence, effect_id FROM ' . self::TABLE . ' WHERE resource_key = ? AND fence_domain = ?',
            [$resourceKey, $fenceDomain],
        );

        return $row === false ? null : $row;
    }
}
