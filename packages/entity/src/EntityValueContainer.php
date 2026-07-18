<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Exception\EntitySerializationForbidden;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Exception\InternalFieldArrayExportDenied;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;

/** @internal Private authoritative value storage owned only by EntityBase. */
final class EntityValueContainer
{
    /** @var array<string, mixed|RestrictedEntityValue> */
    private array $values;

    private object $viewIdentity;

    private int $valueGeneration = 1;

    /**
     * @param array<string, mixed|RestrictedEntityValue> $values
     */
    private function __construct(
        array $values,
        private readonly ?EntityReadLayout $layout,
        private readonly ?EntityValueReadGuardInterface $guard,
        ?object $viewIdentity = null,
    ) {
        $this->viewIdentity = $viewIdentity ?? new \stdClass();
        $this->values = $values;
    }

    /** @param array<string, mixed> $values */
    public static function compatibility(array $values): self
    {
        return new self($values, null, null);
    }

    /** @param array<string, mixed> $values */
    public static function seal(array $values, EntityReadLayout $layout, ?EntityValueReadGuardInterface $guard): self
    {
        $layout->assertCurrent();
        $viewIdentity = new \stdClass();
        $sealed = [];
        foreach ($values as $field => $value) {
            $level = $layout->level($field);
            $sealed[$field] = $level === FieldReadLevel::Public
                ? $value
                : RestrictedEntityValue::seal($field, $value, $viewIdentity, 1);
        }

        return new self($sealed, $layout, $guard, $viewIdentity);
    }

    public function isSealed(): bool
    {
        return $this->layout !== null;
    }

    public function read(EntityBase $entity, string $field): mixed
    {
        $this->layout?->assertCurrent();
        if (!array_key_exists($field, $this->values)) {
            return null;
        }
        $stored = $this->values[$field];
        if (!$stored instanceof RestrictedEntityValue) {
            return $stored;
        }

        $level = $this->layout?->level($field) ?? FieldReadLevel::Internal;
        if ($level === FieldReadLevel::Internal) {
            throw new FieldReadDenied(sprintf('Field %s.%s requires an explicit audited capability.', $entity->getEntityTypeId(), $field));
        }
        if ($this->guard === null) {
            throw new MissingFieldReadContext(sprintf('Field %s.%s requires an account read context.', $entity->getEntityTypeId(), $field));
        }
        $this->guard->assertProtectedReadable($entity, $field, $this->viewIdentity);

        return $stored->release($field, $this->viewIdentity);
    }

    public function write(EntityBase $entity, string $field, mixed $value): void
    {
        $this->layout?->assertCurrent();
        ++$this->valueGeneration;
        $level = $this->layout?->level($field) ?? FieldReadLevel::Public;
        $this->values[$field] = $level === FieldReadLevel::Public
            ? $value
            : RestrictedEntityValue::seal($field, $value, $this->viewIdentity, $this->valueGeneration);
        $this->guard?->invalidate($entity);
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        $names = array_keys($this->values);
        sort($names);

        return $names;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    public function level(string $field): FieldReadLevel
    {
        return $this->layout?->level($field) ?? FieldReadLevel::Public;
    }

    /**
     * Closed comparison that releases no value or container reference.
     *
     * @param list<string> $targetFields
     * @return list<string>
     */
    public function changedFieldNames(self $target, array $targetFields): array
    {
        $this->layout?->assertCurrent();
        $target->layout?->assertCurrent();
        $changed = [];
        foreach ($targetFields as $field) {
            if (!array_key_exists($field, $target->values)) {
                continue;
            }
            if (!array_key_exists($field, $this->values)
                || $this->comparableValue($field) !== $target->comparableValue($field)
            ) {
                $changed[] = $field;
            }
        }
        sort($changed);

        return array_values(array_unique($changed));
    }

    /**
     * Closed, type-lenient submitted-value comparison for exact bookkeeping names.
     *
     * @param array<string, mixed> $submitted
     * @param list<string> $fields
     * @return list<string>
     */
    public function matchingSubmittedFieldNames(array $submitted, array $fields): array
    {
        $this->layout?->assertCurrent();
        $matches = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $submitted)) {
                continue;
            }
            $stored = array_key_exists($field, $this->values) ? $this->comparableValue($field) : null;
            $candidate = $submitted[$field];
            if ($candidate === null || $stored === null) {
                if ($candidate === null && $stored === null) {
                    $matches[] = $field;
                }
                continue;
            }
            if (is_scalar($candidate) && is_scalar($stored) && (string) $candidate === (string) $stored) {
                $matches[] = $field;
            }
        }
        sort($matches);

        return array_values(array_unique($matches));
    }

    private function comparableValue(string $field): mixed
    {
        $value = $this->values[$field];

        return $value instanceof RestrictedEntityValue
            ? $value->release($field, $this->viewIdentity)
            : $value;
    }

    /** @return array<string, mixed> */
    public function publicArray(EntityBase $entity): array
    {
        $this->layout?->assertCurrent();
        if ($this->layout !== null) {
            foreach ($this->values as $field => $value) {
                if ($value instanceof RestrictedEntityValue && $this->layout->level($field) === FieldReadLevel::Internal) {
                    throw new InternalFieldArrayExportDenied(sprintf('Entity %s contains Internal field %s and cannot be exported as an array.', $entity->getEntityTypeId(), $field));
                }
            }
        }

        $result = [];
        foreach ($this->values as $field => $_value) {
            $result[$field] = $this->read($entity, $field);
        }

        return $result;
    }

    /** @internal Closed validation/persistence authorities only. @return array<string, mixed> */
    public function rawValues(): array
    {
        $this->layout?->assertCurrent();
        $result = [];
        foreach ($this->values as $field => $value) {
            $result[$field] = $value instanceof RestrictedEntityValue
                ? $value->release($field, $this->viewIdentity)
                : $value;
        }

        return $result;
    }

    public function reissue(): self
    {
        $this->layout?->assertCurrent();
        $identity = new \stdClass();
        $values = [];
        foreach ($this->values as $field => $value) {
            $values[$field] = $value instanceof RestrictedEntityValue ? $value->reissue($identity) : $value;
        }
        $copy = new self($values, $this->layout, $this->guard, $identity);
        $copy->valueGeneration = $this->valueGeneration;

        return $copy;
    }

    /** @param array<string, mixed> $values */
    public function relatedView(array $values): self
    {
        return $this->layout === null
            ? self::compatibility($values)
            : self::seal($values, $this->layout, $this->guard);
    }

    public function __serialize(): array
    {
        if ($this->layout !== null) {
            throw new EntitySerializationForbidden('Sealed entities cannot be serialized.');
        }

        return ['values' => $this->values];
    }

    /** @param array{values?: array<string, mixed>} $data */
    public function __unserialize(array $data): void
    {
        $this->values = $data['values'] ?? [];
        $this->layout = null;
        $this->guard = null;
        $this->viewIdentity = new \stdClass();
        $this->valueGeneration = 1;
    }
}
