<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\List;

use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;

/**
 * Validated inert metadata for a host-declared generic list.
 *
 * Unknown keys and executable/presentation-language values fail the complete
 * declaration closed. Consumers either receive this normalized value object or
 * no x-list contract at all.
 * @api
 */
final readonly class ListMetadata
{
    /** @param array<string, mixed> $normalized */
    private function __construct(private array $normalized) {}

    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw) || array_is_list($raw)) {
            return null;
        }

        $allowedTopLevel = ['columns', 'search', 'filters', 'sorts', 'defaultSort'];
        if (array_diff(array_keys($raw), $allowedTopLevel) !== []) {
            return null;
        }

        $out = [];
        if (array_key_exists('columns', $raw)) {
            $columns = self::normalizeColumns($raw['columns']);
            if ($columns === null) {
                return null;
            }
            $out['columns'] = $columns;
        }
        if (array_key_exists('search', $raw)) {
            $search = self::normalizeSearch($raw['search']);
            if ($search === null) {
                return null;
            }
            $out['search'] = $search;
        }
        if (array_key_exists('filters', $raw)) {
            $filters = self::normalizeFilters($raw['filters']);
            if ($filters === null) {
                return null;
            }
            $out['filters'] = $filters;
        }
        if (array_key_exists('sorts', $raw)) {
            $sorts = self::normalizeSorts($raw['sorts']);
            if ($sorts === null) {
                return null;
            }
            $out['sorts'] = $sorts;
        }
        if (array_key_exists('defaultSort', $raw)) {
            $default = self::normalizeDefaultSort($raw['defaultSort']);
            if ($default === null || !self::sortIsDeclared($default, $out['sorts'] ?? [])) {
                return null;
            }
            $out['defaultSort'] = $default;
        }
        foreach ($out['columns'] ?? [] as $column) {
            if ($column['sortable'] && !self::sortFieldIsDeclared($column['field'], $out['sorts'] ?? [])) {
                return null;
            }
        }

        return new self($out);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->normalized;
    }

    /** @return list<array{field: string, operator: string}> */
    public function allowedFilters(): array
    {
        $allowed = [];
        if (isset($this->normalized['search'])) {
            $allowed[] = [
                'field' => $this->normalized['search']['field'],
                'operator' => $this->normalized['search']['operator'],
            ];
        }
        foreach ($this->normalized['filters'] ?? [] as $filter) {
            $allowed[] = ['field' => $filter['field'], 'operator' => $filter['operator']];
        }

        return $allowed;
    }

    /** @return list<array{field: string, direction: string, label: string}> */
    public function allowedSorts(): array
    {
        return $this->normalized['sorts'] ?? [];
    }

    private static function normalizeColumns(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $column) {
            if (!is_array($column) || array_diff(array_keys($column), ['field', 'label', 'sortable', 'formatter', 'valueLabels']) !== []) {
                return null;
            }
            $field = self::field($column['field'] ?? null);
            $label = self::label($column['label'] ?? null);
            $formatter = is_string($column['formatter'] ?? null) ? ListFormatter::tryFrom($column['formatter']) : null;
            if ($field === null || $label === null || !is_bool($column['sortable'] ?? null) || $formatter === null) {
                return null;
            }
            $item = ['field' => $field, 'label' => $label, 'sortable' => $column['sortable'], 'formatter' => $formatter->value];
            if (array_key_exists('valueLabels', $column)) {
                $labels = self::normalizeValueLabels($column['valueLabels']);
                if ($labels === null) {
                    return null;
                }
                $item['valueLabels'] = $labels;
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function normalizeSearch(mixed $raw): ?array
    {
        if (!is_array($raw) || array_is_list($raw) || array_diff(array_keys($raw), ['field', 'operator', 'label', 'description']) !== []) {
            return null;
        }
        $field = self::field($raw['field'] ?? null);
        $label = self::label($raw['label'] ?? null);
        $operator = is_string($raw['operator'] ?? null) ? SurfaceFilterOperator::fromString($raw['operator']) : null;
        if ($field === null || $label === null || $operator === null) {
            return null;
        }
        $out = ['field' => $field, 'operator' => $operator->value, 'label' => $label];
        if (array_key_exists('description', $raw)) {
            $description = self::label($raw['description']);
            if ($description === null) {
                return null;
            }
            $out['description'] = $description;
        }

        return $out;
    }

    private static function normalizeFilters(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $filter) {
            if (!is_array($filter) || array_diff(array_keys($filter), ['field', 'operator', 'label', 'options', 'default']) !== []) {
                return null;
            }
            $field = self::field($filter['field'] ?? null);
            $label = self::label($filter['label'] ?? null);
            $operator = is_string($filter['operator'] ?? null) ? SurfaceFilterOperator::fromString($filter['operator']) : null;
            if ($field === null || $label === null || $operator === null) {
                return null;
            }
            $item = ['field' => $field, 'operator' => $operator->value, 'label' => $label];
            if (array_key_exists('options', $filter)) {
                $options = self::normalizeOptions($filter['options']);
                if ($options === null) {
                    return null;
                }
                $item['options'] = $options;
            }
            if (array_key_exists('default', $filter)) {
                if (!is_scalar($filter['default']) && $filter['default'] !== null) {
                    return null;
                }
                $item['default'] = $filter['default'];
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function normalizeSorts(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $sort) {
            if (!is_array($sort) || array_diff(array_keys($sort), ['field', 'direction', 'label']) !== []) {
                return null;
            }
            $field = self::field($sort['field'] ?? null);
            $label = self::label($sort['label'] ?? null);
            $direction = $sort['direction'] ?? null;
            if ($field === null || $label === null || !in_array($direction, ['ASC', 'DESC'], true)) {
                return null;
            }
            $out[] = ['field' => $field, 'direction' => $direction, 'label' => $label];
        }

        return $out;
    }

    private static function normalizeDefaultSort(mixed $raw): ?array
    {
        if (!is_array($raw) || array_is_list($raw) || array_diff(array_keys($raw), ['field', 'direction']) !== []) {
            return null;
        }
        $field = self::field($raw['field'] ?? null);
        $direction = $raw['direction'] ?? null;

        return $field !== null && in_array($direction, ['ASC', 'DESC'], true)
            ? ['field' => $field, 'direction' => $direction]
            : null;
    }

    private static function sortIsDeclared(array $default, array $sorts): bool
    {
        foreach ($sorts as $sort) {
            if ($sort['field'] === $default['field'] && $sort['direction'] === $default['direction']) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{field: string, direction: string, label: string}> $sorts */
    private static function sortFieldIsDeclared(string $field, array $sorts): bool
    {
        foreach ($sorts as $sort) {
            if ($sort['field'] === $field) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeOptions(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $option) {
            if (!is_array($option) || array_diff(array_keys($option), ['value', 'label']) !== [] || !array_key_exists('value', $option)) {
                return null;
            }
            $label = self::label($option['label'] ?? null);
            if ((!is_scalar($option['value']) && $option['value'] !== null) || $label === null) {
                return null;
            }
            $out[] = ['value' => $option['value'], 'label' => $label];
        }

        return $out;
    }

    private static function normalizeValueLabels(mixed $raw): ?array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $value => $label) {
            $normalized = self::label($label);
            if ($normalized === null) {
                return null;
            }
            $out[(string) $value] = $normalized;
        }

        return $out;
    }

    private static function field(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $value) === 1 ? $value : null;
    }

    private static function label(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= 500 ? $value : null;
    }
}
