<?php

declare(strict_types=1);

namespace Waaseyaa\Cache;

use Waaseyaa\Cache\Exception\EntityProjectionWriteForbidden;

/** Dormant write-boundary diagnostic; activation replaces this with rejection. @api */
final class ProjectionDeprecationDiagnostic
{
    /** @var \Closure(mixed): bool */
    private readonly \Closure $detectEntity;
    /** @var \Closure(string, array<string, mixed>): void */
    private readonly \Closure $emit;
    /** @var array<string, true> */
    private array $emitted = [];
    private readonly EntityPayloadBoundaryConfig $config;

    /** @param callable(mixed): bool $detectEntity @param callable(string, array<string, mixed>): void $emit */
    public function __construct(callable $detectEntity, callable $emit, ?EntityPayloadBoundaryConfig $config = null)
    {
        $this->detectEntity = \Closure::fromCallable($detectEntity);
        $this->emit = \Closure::fromCallable($emit);
        $this->config = $config ?? EntityPayloadBoundaryConfig::dormant();
    }

    /** @param callable(string, array<string, mixed>): void $emit */
    public static function forEntityPayloads(callable $emit, ?EntityPayloadBoundaryConfig $config = null): self
    {
        return new self(
            static function (mixed $value): bool {
                $seen = new \WeakMap();
                $remaining = 1_000;

                return self::containsEntity($value, 0, $remaining, $seen);
            },
            $emit,
            $config,
        );
    }

    public function inspect(string $cacheId, mixed $value): mixed
    {
        if (($this->detectEntity)($value)) {
            if ($this->config->rejectEntityPayloads) {
                throw new EntityProjectionWriteForbidden(sprintf('Cache entry "%s" must use identifiers or a public projection, not an entity object.', $cacheId));
            }
            $type = get_debug_type($value);
            if (!isset($this->emitted[$type])) {
                $this->emitted[$type] = true;
                ($this->emit)('entity.deprecation', ['boundary' => 'cache', 'value_type' => $type, 'cache_id' => $cacheId]);
            }
        }

        return $value;
    }

    /** @param \WeakMap<object, true> $seen */
    private static function containsEntity(mixed $value, int $depth, int &$remaining, \WeakMap $seen): bool
    {
        if ($depth > 16 || --$remaining < 0) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if (self::containsEntity($child, $depth + 1, $remaining, $seen)) {
                    return true;
                }
            }

            return false;
        }
        if (!is_object($value) || isset($seen[$value])) {
            return false;
        }
        $seen[$value] = true;
        $entityInterface = implode('\\', ['Waaseyaa', 'Entity', 'EntityInterface']);
        if ($value instanceof $entityInterface) {
            return true;
        }
        $reflection = new \ReflectionObject($value);
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || !$property->isInitialized($value)) {
                continue;
            }
            try {
                $child = $property->getValue($value);
            } catch (\Throwable) {
                continue;
            }
            if (self::containsEntity($child, $depth + 1, $remaining, $seen)) {
                return true;
            }
        }

        return false;
    }
}
