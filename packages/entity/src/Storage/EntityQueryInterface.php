<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Storage;

use Waaseyaa\Access\AccountInterface;

/**
 * Query builder returned by custom {@see EntityStorageInterface} implementations.
 *
 * @api
 */
interface EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static;

    public function exists(string $field): static;

    public function notExists(string $field): static;

    public function sort(string $field, string $direction = 'ASC'): static;

    /**
     * Limit the observable result set.
     *
     * Access-checked implementations apply offset and limit after the access
     * decision, so offset counts authorized rows and pages remain dense until
     * those rows are exhausted. An explicit accessCheck(false) system context
     * may apply the range directly in storage.
     */
    public function range(int $offset, int $limit): static;

    public function count(): static;

    public function accessCheck(bool $check = true): static;

    /**
     * Bind the account used for the access check. Pass null to clear any bound account.
     * Chainable.
     *
     * When the access check is enabled (the default) and no account is bound at
     * execute() time, implementations MUST throw
     * {@see \Waaseyaa\EntityStorage\Exception\MissingQueryAccountException} —
     * silent bypass is forbidden.
     *
     * @api
     */
    public function setAccount(?AccountInterface $account): static;

    /**
     * Execute the query and return entity IDs.
     *
     * @return array<int|string>
     */
    public function execute(): array;
}
