<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/**
 * Explicit process-local access to one selected cell value.
 *
 * @api
 */
final class XlsxProtectedCell implements \JsonSerializable
{
    /** @var \WeakMap<object, mixed>|null */
    private static ?\WeakMap $values = null;

    public function __construct(
        public readonly string $sheetKey,
        public readonly string $coordinate,
        public readonly XlsxCellType $type,
        public readonly bool $isDate,
        #[\SensitiveParameter]
        mixed $value = null,
    ) {
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException('Protected XLSX cell values must be scalar or null.');
        }
        /** @var \WeakMap<object, mixed> $values */
        $values = self::$values ?? new \WeakMap();
        self::$values = $values;
        $values[$this] = $value;
    }

    public function value(): string|int|float|bool|null
    {
        $value = self::$values[$this] ?? null;
        if ($value !== null && !is_scalar($value)) {
            throw new \LogicException('Protected XLSX cell value custody is invalid.');
        }

        return $value;
    }

    /** @return array{sheet_key: string, coordinate: string, type: string, is_date: bool, value: string} */
    public function jsonSerialize(): array
    {
        return [
            'sheet_key' => $this->sheetKey,
            'coordinate' => $this->coordinate,
            'type' => $this->type->value,
            'is_date' => $this->isDate,
            'value' => '[REDACTED]',
        ];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __toString(): string
    {
        throw new \LogicException('Protected XLSX cells cannot be cast to strings.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Protected XLSX cells cannot be serialized.');
    }

    private function __clone() {}
}
