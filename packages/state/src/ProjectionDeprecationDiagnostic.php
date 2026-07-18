<?php

declare(strict_types=1);

namespace Waaseyaa\State;

use Waaseyaa\State\Exception\EntityProjectionWriteForbidden;

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
                $remaining = 1_000;
                return self::containsEntity($value, 0, $remaining, new \WeakMap());
            },
            $emit,
            $config,
        );
    }

    public function inspect(string $stateKey, mixed $value): mixed
    {
        if (($this->detectEntity)($value)) {
            if ($this->config->rejectEntityPayloads) {
                throw new EntityProjectionWriteForbidden(sprintf('State key "%s" must use identifiers or a public projection, not an entity object.', $stateKey));
            }
            $type = get_debug_type($value);
            if (!isset($this->emitted[$type])) {
                $this->emitted[$type] = true;
                ($this->emit)('entity.deprecation', ['boundary' => 'state', 'value_type' => $type, 'state_key' => $stateKey]);
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
