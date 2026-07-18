<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Factory;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Validation\EntityTypeValidationConstraints;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldStorage;

/**
 * Builds storage-shaped entity value bags for tests and seeds from {@see EntityTypeInterface}
 * metadata and the same merged validation constraint map as {@see EntityTypeValidationConstraints}.
 *
 * Distinct from production hydration ({@see \Waaseyaa\EntityStorage\Hydration\EntityInstantiator},
 * static `make()` / #1188): this path is for **dummy data** only (optional `fakerphp/faker` package).
 */
final class EntityTypeFixtureValues
{
    private int $sequence;

    /**
     * Optional callable(string $field, list<Constraint> $constraints): mixed|null —
     * non-null return replaces auto-generated value; null means use built-in rules.
     */
    private mixed $customResolver;

    public function __construct(int $sequence = 1, mixed $customResolver = null)
    {
        if ($customResolver !== null && !is_callable($customResolver)) {
            throw new \InvalidArgumentException('customResolver must be callable or null.');
        }

        $this->sequence = $sequence;
        $this->customResolver = $customResolver;
    }

    /**
     * @param array<string, mixed> $overrides Merged last; use for explicit nulls or replacements.
     * @return array<string, mixed>
     */
    public function values(EntityTypeInterface $type, array $overrides = []): array
    {
        $values = [];
        $this->applyEntityKeyDefaults($type, $values);

        $constraintMap = EntityTypeValidationConstraints::forEntityType($type);
        $fieldDefinitions = $type->getFieldDefinitions();

        $allFields = array_unique(array_merge(
            array_keys($fieldDefinitions),
            array_keys($constraintMap),
        ));
        sort($allFields);

        foreach ($allFields as $field) {
            if (array_key_exists($field, $values)) {
                continue;
            }

            $def = $fieldDefinitions[$field] ?? null;
            /** @var list<Constraint> $constraints */
            $constraints = $constraintMap[$field] ?? [];

            $resolved = $this->resolveWithCustom($field, $constraints);
            if ($resolved !== self::useBuiltin()) {
                $values[$field] = $resolved;

                continue;
            }

            if ($constraints === []) {
                if (!$def instanceof FieldDefinitionInterface) {
                    continue;
                }
                $fallback = $this->valueFromFieldDefinitionOnly($def);
                if ($fallback !== self::skipField()) {
                    $values[$field] = $fallback;
                }

                continue;
            }

            $definition = $def instanceof FieldDefinitionInterface
                ? $def
                : new FieldDefinition(
                    name: $field,
                    type: 'string',
                    targetEntityTypeId: '',
                    targetBundle: null,
                    stored: FieldStorage::Column,
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
                );
            $values[$field] = $this->valueFromConstraints($definition, $constraints);
        }

        return array_merge($values, $overrides);
    }

    private static function useBuiltin(): \stdClass
    {
        static $marker = null;

        return $marker ??= new \stdClass();
    }

    private static function skipField(): \stdClass
    {
        static $marker = null;

        return $marker ??= new \stdClass();
    }

    /**
     * @param list<Constraint> $constraints
     */
    private function resolveWithCustom(string $field, array $constraints): mixed
    {
        if ($this->customResolver === null) {
            return self::useBuiltin();
        }

        $result = ($this->customResolver)($field, $constraints);

        return $result === null ? self::useBuiltin() : $result;
    }

    private function applyEntityKeyDefaults(EntityTypeInterface $type, array &$values): void
    {
        $keys = $type->getKeys();
        if ($keys === []) {
            return;
        }

        if (isset($keys['uuid'])) {
            $k = $keys['uuid'];
            if (!array_key_exists($k, $values)) {
                $values[$k] = Uuid::v4()->toRfc4122();
            }
        }

        if (isset($keys['label'])) {
            $k = $keys['label'];
            if (!array_key_exists($k, $values)) {
                $values[$k] = $this->readableString('title');
            }
        }

        if (isset($keys['bundle'])) {
            $k = $keys['bundle'];
            if (!array_key_exists($k, $values)) {
                $values[$k] = $type->id();
            }
        }

        if (isset($keys['langcode'])) {
            $k = $keys['langcode'];
            if (!array_key_exists($k, $values)) {
                $values[$k] = 'en';
            }
        }
    }

    private function valueFromFieldDefinitionOnly(FieldDefinitionInterface $def): mixed
    {
        $required = $def->isRequired();
        if (!$required) {
            return self::skipField();
        }

        $type = $def->getType();

        return $this->scalarDefaultForFieldType($type);
    }

    /**
     * @param list<Constraint> $constraints
     */
    private function valueFromConstraints(FieldDefinitionInterface $def, array $constraints): mixed
    {
        $choiceValues = null;
        $hasEmail = false;
        $lengthMin = null;
        $lengthMax = null;
        $phpTypes = [];
        $needsNotNull = false;
        $needsNotBlank = false;

        foreach ($constraints as $c) {
            if ($c instanceof Choice) {
                $choiceValues = array_values($c->choices ?? []);
            } elseif ($c instanceof Email) {
                $hasEmail = true;
            } elseif ($c instanceof Length) {
                if ($c->max !== null) {
                    $lengthMax = $lengthMax === null ? $c->max : min($lengthMax, $c->max);
                }
                if ($c->min !== null) {
                    $lengthMin = $lengthMin === null ? $c->min : max($lengthMin, $c->min);
                }
            } elseif ($c instanceof Type) {
                $t = $c->type;
                if (is_string($t)) {
                    $phpTypes[] = $t;
                } elseif (is_array($t)) {
                    foreach ($t as $one) {
                        if (is_string($one)) {
                            $phpTypes[] = $one;
                        }
                    }
                }
            } elseif ($c instanceof NotNull) {
                $needsNotNull = true;
            } elseif ($c instanceof NotBlank) {
                $needsNotBlank = true;
            }
        }

        if ($choiceValues !== null && $choiceValues !== []) {
            $idx = ($this->sequence - 1) % count($choiceValues);

            return $choiceValues[$idx];
        }

        if ($hasEmail) {
            return $this->generatedEmail();
        }

        $fieldType = $def->getType();
        $primaryPhpType = $phpTypes[0] ?? null;

        if ($primaryPhpType === 'int' || $primaryPhpType === 'float' || $fieldType === 'integer'
            || $fieldType === 'int' || $fieldType === 'float' || $fieldType === 'double') {
            return $this->sequence;
        }

        if ($primaryPhpType === 'bool' || $fieldType === 'boolean' || $fieldType === 'bool') {
            return false;
        }

        if ($primaryPhpType === 'array' || $fieldType === 'array' || $fieldType === 'json') {
            return [];
        }

        if ($fieldType === 'entity_reference' || $fieldType === 'timestamp' || $fieldType === 'datetime'
            || $fieldType === 'datetime_immutable') {
            return $this->sequence;
        }

        $minLen = $lengthMin ?? ($needsNotBlank || $needsNotNull ? 1 : 0);
        if ($minLen < 1 && ($needsNotBlank || ($needsNotNull && $primaryPhpType === 'string'))) {
            $minLen = 1;
        }

        $maxLen = $lengthMax ?? max($minLen, 32);

        return $this->fixedLengthString($minLen, $maxLen);
    }

    private function fixedLengthString(int $minLen, int $maxLen): string
    {
        $target = min($maxLen, max($minLen, strlen('fixture') + $this->sequence));
        $base = $this->readableString('f');
        $out = $base;
        while (strlen($out) < $target) {
            $out .= $base;
        }

        if (strlen($out) > $maxLen) {
            $out = substr($out, 0, $maxLen);
        }

        if (strlen($out) < $minLen) {
            $out = str_pad($out, $minLen, 'x');
        }

        return $out;
    }

    private function readableString(string $prefix): string
    {
        if (class_exists('Faker\\Factory')) {
            $faker = \call_user_func(['Faker\\Factory', 'create']);

            return $prefix . '-' . $faker->lexify('????');
        }

        return $prefix . '-' . $this->sequence;
    }

    private function generatedEmail(): string
    {
        if (class_exists('Faker\\Factory')) {
            $faker = \call_user_func(['Faker\\Factory', 'create']);

            return $faker->safeEmail();
        }

        return 'fixture-' . $this->sequence . '@example.com';
    }

    private function scalarDefaultForFieldType(string $type): string|int|bool|array
    {
        return match ($type) {
            'boolean', 'bool' => false,
            'integer', 'int', 'float', 'double', 'entity_reference', 'timestamp', 'datetime', 'datetime_immutable' => $this->sequence,
            'array', 'json' => [],
            default => $this->readableString('v'),
        };
    }

}
