<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Repository;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * Records what a caller built on the query surface so a test can assert the
 * access posture ({@see $accessCheck}, {@see $accountBindings}) rather than
 * only the returned ids.
 *
 * @internal Test double.
 */
final class RecordingEntityQuery implements EntityQueryInterface
{
    /** @var list<array{string, mixed, string}> */
    public array $conditions = [];

    /** @var list<array{int, int}> */
    public array $ranges = [];

    /** @var list<bool> */
    public array $accessCheck = [];

    /** @var list<AccountInterface|null> */
    public array $accountBindings = [];

    public int $executions = 0;

    /** @param array<int|string> $result */
    public function __construct(private array $result = [])
    {
    }

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $this->conditions[] = [$field, $value, $operator];

        return $this;
    }

    public function exists(string $field): static
    {
        return $this;
    }

    public function notExists(string $field): static
    {
        return $this;
    }

    public function sort(string $field, string $direction = 'ASC'): static
    {
        return $this;
    }

    public function range(int $offset, int $limit): static
    {
        $this->ranges[] = [$offset, $limit];

        return $this;
    }

    public function count(): static
    {
        return $this;
    }

    public function accessCheck(bool $check = true): static
    {
        $this->accessCheck[] = $check;

        return $this;
    }

    public function setAccount(?AccountInterface $account): static
    {
        $this->accountBindings[] = $account;

        return $this;
    }

    public function execute(): array
    {
        ++$this->executions;

        return $this->result;
    }
}
