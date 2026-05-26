<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

/**
 * @api
 */
interface SelectInterface
{
    public function fields(string $tableAlias, array $fields = []): static;

    public function addField(string $tableAlias, string $field, string $alias = ''): static;

    public function condition(string $field, mixed $value, string $operator = '='): static;

    public function isNull(string $field): static;

    public function isNotNull(string $field): static;

    public function orderBy(string $field, string $direction = 'ASC'): static;

    public function range(int $offset, int $limit): static;

    public function join(string $table, string $alias, string $condition): static;

    public function leftJoin(string $table, string $alias, string $condition): static;

    public function countQuery(): static;

    /** @return \Traversable */
    public function execute(): \Traversable;
}
