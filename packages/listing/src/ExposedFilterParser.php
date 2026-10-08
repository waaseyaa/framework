<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

use Closure;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Listing\Exception\ListingCoercionException;

/**
 * Parses an inbound URL query-string slice into a typed
 * {@see ExposedFilterValues} map, applying per-filter coercion via
 * {@see ExposedFilterCoercer}.
 *
 * Two modes (FR-044, FR-045):
 *
 *   - **Permissive** (default, production) — coercion failures are
 *     silently dropped from the resulting values map; each failure is
 *     logged at debug level via the injected
 *     {@see LoggerInterface}. The resolver then falls through to the
 *     filter's declared default `$value`.
 *   - **Strict** (tests, debug envs) — the first
 *     {@see ListingCoercionException} propagates to the caller with full
 *     context (param, raw, operator, expected type, reason).
 *
 * Stable charter §5.X surface:
 *
 *   - `static create(): self`
 *   - `withCoercer()`, `withLogger()`, `withTypeResolver()`, `strict()`
 *   - `parse(array $queryParams, ListingDefinition $def): ExposedFilterValues`
 *
 * Filters without an `exposedParam` are ignored. Raw query-string keys
 * that do not correspond to a declared exposed filter are also ignored —
 * the parser is strictly listing-definition-driven.
 *
 * @api
 */
final class ExposedFilterParser
{
    /**
     * @param Closure(FilterDefinition): non-empty-string $typeResolver
     *   Closure returning the typed-data type identifier for a given
     *   filter definition. The default resolver infers from the filter's
     *   default `$value` shape; sites with rich typed-data introspection
     *   should inject a manager-backed resolver via
     *   {@see self::withTypeResolver()}.
     */
    public function __construct(
        private readonly ExposedFilterCoercer $coercer,
        private readonly LoggerInterface $logger,
        private readonly Closure $typeResolver,
        private readonly bool $strict = false,
    ) {}

    /**
     * Production-shaped factory: permissive mode, null logger, default
     * coercer + default type resolver.
     */
    public static function create(): self
    {
        return new self(
            coercer: new ExposedFilterCoercer(),
            logger: new NullLogger(),
            typeResolver: self::defaultTypeResolver(),
            strict: false,
        );
    }

    /**
     * Return a clone configured with the supplied concrete coercer.
     * Custom field type selection belongs in withTypeResolver().
     */
    public function withCoercer(ExposedFilterCoercer $coercer): self
    {
        return new self($coercer, $this->logger, $this->typeResolver, $this->strict);
    }

    /**
     * Return a clone with a real logger attached (debug-level drops are
     * routed here in permissive mode).
     */
    public function withLogger(LoggerInterface $logger): self
    {
        return new self($this->coercer, $logger, $this->typeResolver, $this->strict);
    }

    /**
     * Return a clone with a custom type resolver.
     *
     * @param Closure(FilterDefinition): non-empty-string $typeResolver
     */
    public function withTypeResolver(Closure $typeResolver): self
    {
        return new self($this->coercer, $this->logger, $typeResolver, $this->strict);
    }

    /**
     * Return a clone with strict mode enabled (coercion failures
     * propagate as {@see ListingCoercionException}).
     */
    public function strict(): self
    {
        return new self($this->coercer, $this->logger, $this->typeResolver, true);
    }

    /**
     * @param array<string, mixed> $queryParams URL-decoded query-string slice (`$_GET`-equivalent).
     *
     * @throws ListingCoercionException in strict mode on the first coercion failure
     */
    public function parse(array $queryParams, ListingDefinition $def): ExposedFilterValues
    {
        $values = [];
        foreach ($def->filters as $filter) {
            if ($filter->exposedParam === null) {
                continue;
            }

            $param = $filter->exposedParam;
            $raw = $queryParams[$param] ?? null;
            if ($raw === null || $raw === '' || $raw === []) {
                // Filter not applied; resolver falls through to declared default $value.
                continue;
            }
            if (!is_string($raw)) {
                if ($this->strict) {
                    throw new ListingCoercionException(
                        param: $param,
                        raw: get_debug_type($raw),
                        operatorName: $filter->op->value,
                        expectedType: ($this->typeResolver)($filter),
                        reason: 'exposed filter raw value must be a string (URL-decoded $_GET scalar)',
                    );
                }
                $this->logger->debug('exposed filter coercion failed: non-string raw value', [
                    'param' => $param,
                    'raw_type' => get_debug_type($raw),
                    'operator' => $filter->op->value,
                ]);

                continue;
            }

            $typedDataType = ($this->typeResolver)($filter);
            try {
                $coerced = $this->coercer->coerce($param, $raw, $filter->op, $typedDataType);
            } catch (ListingCoercionException $coercionException) {
                if ($this->strict) {
                    throw $coercionException;
                }
                $this->logger->debug('exposed filter coercion failed', [
                    'param' => $param,
                    'raw' => $raw,
                    'operator' => $filter->op->value,
                    'expected_type' => $coercionException->expectedType,
                    'reason' => $coercionException->reason,
                ]);

                continue;
            }

            $values[$param] = $coerced;
        }

        return new ExposedFilterValues($values);
    }

    /**
     * Default type resolver — infers from the filter's declared default
     * `$value` shape. Falls back to `string` when no signal is available.
     *
     * @return Closure(FilterDefinition): non-empty-string
     */
    private static function defaultTypeResolver(): Closure
    {
        return static function (FilterDefinition $filter): string {
            $sample = match (true) {
                is_array($filter->value) && $filter->value !== [] => $filter->value[0] ?? null,
                default => $filter->value,
            };

            return match (true) {
                is_int($sample) => 'int',
                is_float($sample) => 'float',
                is_bool($sample) => 'bool',
                default => 'string',
            };
        };
    }
}
