<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Mapping;

/**
 * Explicit source-field mapping and conflict policy for one dry-run plan.
 *
 * @api
 */
final readonly class MappingDefinition implements \JsonSerializable
{
    /** @var array<string, string> */
    public array $sourceToTarget;

    /** @var list<string> */
    public array $ignoredSourceKeys;

    /** @var list<string> */
    public array $uniqueTargetFields;

    /**
     * @param array<string, string> $sourceToTarget
     * @param list<string> $ignoredSourceKeys
     * @param list<string> $uniqueTargetFields
     */
    public function __construct(
        public string $id,
        array $sourceToTarget,
        array $ignoredSourceKeys = [],
        array $uniqueTargetFields = [],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Mapping definition id must be a stable lowercase token.');
        }
        if ($sourceToTarget === []) {
            throw new \InvalidArgumentException('Mapping definition requires at least one source-to-target field mapping.');
        }

        $normalizedMapping = [];
        $targets = [];
        foreach ($sourceToTarget as $source => $target) {
            $source = self::normalizeFieldName($source, 'source mapping key');
            self::assertFieldName($target, 'target mapping field');
            if (array_key_exists($source, $normalizedMapping)) {
                throw new \InvalidArgumentException('Source mapping keys must be unique after normalization.');
            }
            if (isset($targets[$target])) {
                throw new \InvalidArgumentException('Each target field may be mapped from only one source key.');
            }
            $normalizedMapping[$source] = $target;
            $targets[$target] = true;
        }

        $ignored = [];
        foreach ($ignoredSourceKeys as $source) {
            self::assertFieldName($source, 'ignored source key');
            if (array_key_exists($source, $normalizedMapping)) {
                throw new \InvalidArgumentException('Mapped and ignored source keys must be disjoint.');
            }
            if (isset($ignored[$source])) {
                throw new \InvalidArgumentException('Ignored source keys must be unique.');
            }
            $ignored[$source] = true;
        }

        $unique = [];
        foreach ($uniqueTargetFields as $target) {
            self::assertFieldName($target, 'unique target field');
            if (!isset($targets[$target])) {
                throw new \InvalidArgumentException('Unique target fields must be present in the mapping.');
            }
            if (isset($unique[$target])) {
                throw new \InvalidArgumentException('Unique target fields must be unique.');
            }
            $unique[$target] = true;
        }

        $this->sourceToTarget = $normalizedMapping;
        $this->ignoredSourceKeys = $ignoredSourceKeys;
        $this->uniqueTargetFields = $uniqueTargetFields;
    }

    /** @return array{id: string, source_to_target: array<string, string>, ignored_source_keys: list<string>, unique_target_fields: list<string>} */
    public function normalized(): array
    {
        $mapping = $this->sourceToTarget;
        ksort($mapping, SORT_STRING);
        $ignored = $this->ignoredSourceKeys;
        sort($ignored, SORT_STRING);
        $unique = $this->uniqueTargetFields;
        sort($unique, SORT_STRING);

        return [
            'id' => $this->id,
            'source_to_target' => $mapping,
            'ignored_source_keys' => $ignored,
            'unique_target_fields' => $unique,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->normalized();
    }

    private static function assertFieldName(mixed $name, string $label): void
    {
        if (!is_string($name) || $name === '' || strlen($name) > 255 || !mb_check_encoding($name, 'UTF-8')) {
            throw new \InvalidArgumentException(ucfirst($label) . ' must be a non-empty bounded UTF-8 string.');
        }
    }

    private static function normalizeFieldName(mixed $name, string $label): string
    {
        if (is_int($name)) {
            $name = (string) $name;
        }
        self::assertFieldName($name, $label);

        return $name;
    }
}
