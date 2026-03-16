<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

/**
 * Value object representing an admin surface operation result.
 *
 * Maps to AdminSurfaceResult<T> in contract/types.ts.
 */
final readonly class AdminSurfaceResultData
{
    /**
     * @param bool                      $ok
     * @param mixed                     $data
     * @param array<string, mixed>|null $error
     * @param array<string, mixed>      $meta
     */
    private function __construct(
        public bool $ok,
        public mixed $data = null,
        public ?array $error = null,
        public array $meta = [],
    ) {}

    /**
     * @param mixed                $data
     * @param array<string, mixed> $meta
     */
    public static function success(mixed $data, array $meta = []): self
    {
        return new self(ok: true, data: $data, meta: $meta);
    }

    public static function error(int $status, string $title, ?string $detail = null): self
    {
        return new self(
            ok: false,
            error: array_filter([
                'status' => $status,
                'title' => $title,
                'detail' => $detail,
            ], fn($v) => $v !== null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'ok' => $this->ok,
            'data' => $this->data,
            'error' => $this->error,
            'meta' => $this->meta ?: null,
        ], fn($v) => $v !== null);
    }
}
