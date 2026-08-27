<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Mapping;

/**
 * Caller-supplied current target snapshot; this class performs no storage read.
 *
 * @api
 */
final class MappingTargetRecord implements \JsonSerializable
{
    /** @var \WeakMap<object, mixed>|null */
    private static ?\WeakMap $values = null;

    /** @param array<array-key, mixed> $fields */
    public function __construct(
        #[\SensitiveParameter]
        string $recordId,
        #[\SensitiveParameter]
        string $sourceIdentity,
        #[\SensitiveParameter]
        array $fields,
    ) {
        ProtectedRecordValues::validateOpaqueIdentity($recordId, 'Target record id');
        ProtectedRecordValues::validateOpaqueIdentity($sourceIdentity, 'Target source identity');
        $fields = ProtectedRecordValues::validate($fields);
        /** @var \WeakMap<object, mixed> $values */
        $values = self::$values ?? new \WeakMap();
        self::$values = $values;
        $values[$this] = ['record_id' => $recordId, 'identity' => $sourceIdentity, 'fields' => $fields];
    }

    /** @internal */
    public function recordId(): string
    {
        return $this->data()['record_id'];
    }

    /** @internal */
    public function sourceIdentity(): string
    {
        return $this->data()['identity'];
    }

    /** @return array<string, scalar|null> @internal */
    public function protectedFields(): array
    {
        return $this->data()['fields'];
    }

    /** @return array{source_identity_hash: string, target_record_id_hash: string, fields: list<string>, values: string} */
    public function jsonSerialize(): array
    {
        $fields = array_keys($this->protectedFields());
        sort($fields, SORT_STRING);

        return [
            'source_identity_hash' => ProtectedRecordValues::hashIdentity($this->sourceIdentity()),
            'target_record_id_hash' => ProtectedRecordValues::hashRecordId($this->recordId()),
            'fields' => $fields,
            'values' => '[REDACTED]',
        ];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Protected mapping target records cannot be serialized.');
    }

    /** @return array{record_id: string, identity: string, fields: array<string, scalar|null>} */
    private function data(): array
    {
        $data = self::$values[$this] ?? throw new \LogicException('Target-record value custody is unavailable.');
        /** @var array{record_id: string, identity: string, fields: array<string, scalar|null>} $data */

        return $data;
    }

    private function __clone() {}
}
