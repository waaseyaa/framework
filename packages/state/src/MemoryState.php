<?php

declare(strict_types=1);

namespace Aurora\State;

final class MemoryState implements StateInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }

        return $default;
    }

    public function getMultiple(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                $values[$key] = $this->data[$key];
            }
        }

        return $values;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function setMultiple(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->data[$key] = $value;
        }
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    public function deleteMultiple(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->data[$key]);
        }
    }
}
