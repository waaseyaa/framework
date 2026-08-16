<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

/** One deterministically sortable bundle-validation diagnostic. @api */
final readonly class ConfigBundleDiagnostic
{
    public function __construct(
        public string $path,
        public string $phase,
        public string $field,
        public string $message,
    ) {}

    /** @return array{path: string, phase: string, field: string, message: string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'phase' => $this->phase,
            'field' => $this->field,
            'message' => $this->message,
        ];
    }
}
