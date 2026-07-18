<?php

declare(strict_types=1);

namespace Waaseyaa\State;

/** Dormant write-boundary diagnostic; activation replaces this with rejection. @api */
final class ProjectionDeprecationDiagnostic
{
    /** @var \Closure(mixed): bool */
    private readonly \Closure $detectEntity;
    /** @var \Closure(string, array<string, mixed>): void */
    private readonly \Closure $emit;
    /** @var array<string, true> */
    private array $emitted = [];

    /** @param callable(mixed): bool $detectEntity @param callable(string, array<string, mixed>): void $emit */
    public function __construct(callable $detectEntity, callable $emit)
    {
        $this->detectEntity = \Closure::fromCallable($detectEntity);
        $this->emit = \Closure::fromCallable($emit);
    }

    public function inspect(string $stateKey, mixed $value): mixed
    {
        if (($this->detectEntity)($value)) {
            $type = get_debug_type($value);
            if (!isset($this->emitted[$type])) {
                $this->emitted[$type] = true;
                ($this->emit)('entity.deprecation', ['boundary' => 'state', 'value_type' => $type, 'state_key' => $stateKey]);
            }
        }

        return $value;
    }
}
