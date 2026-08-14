<?php

declare(strict_types=1);

namespace Waaseyaa\Config;

use Waaseyaa\Config\Exception\ConfigMutationFailedException;
use Waaseyaa\Config\Exception\ImmutableConfigException;

/**
 * @api
 */
final class Config implements ConfigInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $data;

    private bool $isNew;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $name,
        private readonly StorageInterface $storage,
        array $data = [],
        private readonly bool $immutable = false,
        ?bool $isNew = null,
    ) {
        $this->data = $data;
        $this->isNew = $isNew ?? empty($data);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function get(string $key = ''): mixed
    {
        if ($key === '') {
            return $this->data;
        }

        return self::getNestedValue($this->data, $key);
    }

    public function set(string $key, mixed $value): static
    {
        $this->assertMutable();

        self::setNestedValue($this->data, $key, $value);

        return $this;
    }

    public function clear(string $key): static
    {
        $this->assertMutable();

        self::clearNestedValue($this->data, $key);

        return $this;
    }

    public function delete(): static
    {
        $this->assertMutable();

        if (!$this->storage->delete($this->name)) {
            throw ConfigMutationFailedException::forOperation('delete', $this->name);
        }
        $this->data = [];
        $this->isNew = true;

        return $this;
    }

    public function save(): static
    {
        $this->assertMutable();

        if (!$this->storage->write($this->name, $this->data)) {
            throw ConfigMutationFailedException::forOperation('save', $this->name);
        }
        $this->isNew = false;

        return $this;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function getRawData(): array
    {
        return $this->data;
    }

    public function isImmutable(): bool
    {
        return $this->immutable;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function getNestedValue(array $data, string $key): mixed
    {
        $parts = explode('.', $key);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function setNestedValue(array &$data, string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $current = &$data;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
            } else {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }

                $current = &$current[$part];
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function clearNestedValue(array &$data, string $key): void
    {
        $parts = explode('.', $key);
        $current = &$data;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                unset($current[$part]);
            } else {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    return;
                }

                $current = &$current[$part];
            }
        }
    }

    private function assertMutable(): void
    {
        if ($this->immutable) {
            throw ImmutableConfigException::fromConfigName($this->name);
        }
    }
}
