<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Advisory;

use Waaseyaa\Entity\EntityInterface;

/** Candidate-bound warning emitted by an application pre-save policy. @api */
final readonly class SaveAdvisory
{
    public const string SEVERITY_WARNING = 'warning';

    private const string TOKEN_DOMAIN = "waaseyaa.save-advisory.v1\0";
    private const int MAX_MESSAGE_BYTES = 1_000;
    private const int MAX_CANONICAL_DEPTH = 16;
    private const int MAX_CANONICAL_ITEMS = 1_024;

    private function __construct(
        public string $code,
        public string $field,
        public string $severity,
        public string $message,
        public string $acknowledgement,
    ) {}

    /** Build an advisory bound to the entity's current candidate field value. */
    public static function forEntityField(
        EntityInterface $entity,
        string $code,
        string $field,
        string $message,
    ): self {
        self::assertCode($code);
        self::assertField($field);
        self::assertMessage($message);

        $identity = $entity->isNew() ? 'new' : $entity->uuid();
        if ($identity !== 'new' && $identity === '') {
            $id = $entity->id();
            $identity = $id === null || $id === '' ? 'unidentified' : 'id:' . (string) $id;
        } elseif ($identity !== 'new') {
            $identity = 'uuid:' . $identity;
        }

        $binding = [
            'bundle' => $entity->bundle(),
            'code' => $code,
            'entity_identity' => $identity,
            'entity_type' => $entity->getEntityTypeId(),
            'field' => $field,
            'value' => self::canonicalValue($entity->get($field)),
            'version' => 1,
        ];
        $canonical = json_encode(
            $binding,
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );

        return new self(
            code: $code,
            field: $field,
            severity: self::SEVERITY_WARNING,
            message: $message,
            acknowledgement: hash('sha256', self::TOKEN_DOMAIN . $canonical),
        );
    }

    /** @return array{code:string,field:string,severity:string,message:string,acknowledgement:string} */
    public function payload(): array
    {
        return [
            'code' => $this->code,
            'field' => $this->field,
            'severity' => $this->severity,
            'message' => $this->message,
            'acknowledgement' => $this->acknowledgement,
        ];
    }

    public static function assertCode(string $code): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,127}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Save advisory codes must match ^[A-Z][A-Z0-9_]{2,127}$.');
        }
    }

    private static function assertField(string $field): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/D', $field) !== 1) {
            throw new \InvalidArgumentException('Save advisory fields must match ^[A-Za-z_][A-Za-z0-9_.-]{0,127}$.');
        }
    }

    private static function assertMessage(string $message): void
    {
        if ($message === '' || strlen($message) > self::MAX_MESSAGE_BYTES || preg_match('//u', $message) !== 1) {
            throw new \InvalidArgumentException(
                'Save advisory messages must be non-empty valid UTF-8 data of at most 1000 bytes.',
            );
        }
    }

    private static function canonicalValue(mixed $value, int $depth = 0, int &$items = 0): mixed
    {
        if ($depth > self::MAX_CANONICAL_DEPTH) {
            throw new \InvalidArgumentException('Save advisory candidate values exceed the maximum nesting depth.');
        }
        ++$items;
        if ($items > self::MAX_CANONICAL_ITEMS) {
            throw new \InvalidArgumentException('Save advisory candidate values exceed the maximum item count.');
        }

        return match (true) {
            $value === null => ['type' => 'null'],
            is_bool($value) => ['type' => 'bool', 'value' => $value],
            is_int($value) => ['type' => 'int', 'value' => (string) $value],
            is_float($value) => self::canonicalFloat($value),
            is_string($value) => self::canonicalString($value),
            is_array($value) => self::canonicalArray($value, $depth, $items),
            default => throw new \InvalidArgumentException(
                'Save advisory candidate values must contain only scalar, null, or array data.',
            ),
        };
    }

    /** @return array{type:string,value:string} */
    private static function canonicalFloat(float $value): array
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('Save advisory candidate floats must be finite.');
        }

        return ['type' => 'float', 'value' => sprintf('%.17g', $value)];
    }

    /** @return array{type:string,value:string} */
    private static function canonicalString(string $value): array
    {
        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Save advisory candidate strings must be valid UTF-8.');
        }

        return ['type' => 'string', 'value' => $value];
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array{type:string,value:list<array{key:array{type:string,value:string},value:mixed}>}
     */
    private static function canonicalArray(array $value, int $depth, int &$items): array
    {
        $entries = [];
        foreach ($value as $key => $item) {
            $keyValue = is_int($key)
                ? ['type' => 'int', 'value' => (string) $key]
                : self::canonicalString($key);
            $entries[] = [
                'key' => $keyValue,
                'value' => self::canonicalValue($item, $depth + 1, $items),
            ];
        }
        usort($entries, static function (array $left, array $right): int {
            $leftKey = $left['key']['type'] . "\0" . $left['key']['value'];
            $rightKey = $right['key']['type'] . "\0" . $right['key']['value'];

            return $leftKey <=> $rightKey;
        });

        return ['type' => 'array', 'value' => $entries];
    }
}
