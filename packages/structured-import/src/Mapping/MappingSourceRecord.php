<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Mapping;

/**
 * Caller-identified source record whose values stay off ordinary object views.
 *
 * @api
 */
final class MappingSourceRecord implements \JsonSerializable
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $identities = null;

    /** @var \WeakMap<object, mixed>|null */
    private static ?\WeakMap $fields = null;

    /** @param array<array-key, mixed> $fields */
    public function __construct(#[\SensitiveParameter] string $identity, #[\SensitiveParameter] array $fields)
    {
        ProtectedRecordValues::validateOpaqueIdentity($identity, 'Source identity');
        $fields = ProtectedRecordValues::validate($fields);
        /** @var \WeakMap<self, string> $identities */
        $identities = self::$identities ?? new \WeakMap();
        /** @var \WeakMap<object, mixed> $fieldValues */
        $fieldValues = self::$fields ?? new \WeakMap();
        self::$identities = $identities;
        self::$fields = $fieldValues;
        $identities[$this] = $identity;
        $fieldValues[$this] = $fields;
    }

    public function identityHash(): string
    {
        return ProtectedRecordValues::hashIdentity($this->identity());
    }

    /** @internal */
    public function identity(): string
    {
        return self::$identities[$this] ?? throw new \LogicException('Source-record identity custody is unavailable.');
    }

    /** @return array<string, scalar|null> @internal */
    public function protectedFields(): array
    {
        $fields = self::$fields[$this] ?? throw new \LogicException('Source-record value custody is unavailable.');
        /** @var array<string, scalar|null> $fields */

        return $fields;
    }

    /** @return array{source_identity_hash: string, fields: list<string>, values: string} */
    public function jsonSerialize(): array
    {
        $fields = array_keys($this->protectedFields());
        sort($fields, SORT_STRING);

        return ['source_identity_hash' => $this->identityHash(), 'fields' => $fields, 'values' => '[REDACTED]'];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Protected mapping source records cannot be serialized.');
    }

    private function __clone() {}
}
