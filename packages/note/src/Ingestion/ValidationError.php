<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Ingestion;

final class ValidationError
{
    public function __construct(
        public readonly string $path,
        public readonly string $code,
        public readonly string $message,
    ) {}

    /** @return array{path: string, code: string, message: string} */
    public function toArray(): array
    {
        return [
            'path'    => $this->path,
            'code'    => $this->code,
            'message' => $this->message,
        ];
    }
}
