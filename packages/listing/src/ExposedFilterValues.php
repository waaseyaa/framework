<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

/**
 * Typed wrapper around the parsed `$_GET` slice that a controller passes
 * to {@see ListingResolver::resolve()}.
 *
 * The resolver reads exposed-filter values for filters whose
 * {@see FilterDefinition::$exposedParam} matches a key here; absent keys
 * fall through to the filter's declared `$value`.
 *
 * ExposedFilterParser validates URL input before constructing this map.
 * Direct PHP callers supply values matching each declared operator shape.
 *
 * @api
 */
final readonly class ExposedFilterValues
{
    /**
     * @param array<non-empty-string, mixed> $values URL-decoded, type-coerced values keyed by exposed-param name.
     */
    public function __construct(
        private array $values = [],
    ) {}

    /**
     * Return the coerced value for `$param`, or `null` if the key is absent.
     */
    public function get(string $param): mixed
    {
        return $this->values[$param] ?? null;
    }

    /**
     * Whether `$param` is present in the values map.
     */
    public function has(string $param): bool
    {
        return array_key_exists($param, $this->values);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Deterministic 16-hex-char digest of the values map (FR-037).
     *
     * Canonical JSON sorts object keys lexicographically so two PHP workers
     * with the same value-map produce the same digest.
     */
    public function cacheKeyHash(): string
    {
        return ListingHash::of($this->values);
    }


}
