<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope;

/**
 * Value object representing a single recorded telescope entry.
 */
final class TelescopeEntry
{
    public readonly string $id;
    public readonly \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
        ?string $id = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = $id ?? bin2hex(random_bytes(16));
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s.u'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            type: $row['type'],
            data: is_string($row['data']) ? self::decodeData($row['data']) : $row['data'],
            id: $row['id'],
            createdAt: new \DateTimeImmutable($row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeData(string $json): array
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['_raw' => $json, '_error' => 'Failed to decode telescope entry data'];
        }
    }
}
