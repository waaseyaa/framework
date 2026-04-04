<?php

declare(strict_types=1);

namespace Waaseyaa\State;

/**
 * @internal
 */
interface StateInterface
{
    public function get(string $key, mixed $default = null): mixed;

    /**
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array;

    public function set(string $key, mixed $value): void;

    /**
     * @param array<string, mixed> $values
     */
    public function setMultiple(array $values): void;

    public function delete(string $key): void;

    /**
     * @param string[] $keys
     */
    public function deleteMultiple(array $keys): void;
}
