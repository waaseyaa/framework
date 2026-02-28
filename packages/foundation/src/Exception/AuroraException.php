<?php

declare(strict_types=1);

namespace Aurora\Foundation\Exception;

abstract class AuroraException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $problemType = 'aurora:internal-error',
        public readonly int $statusCode = 500,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function toApiError(): array
    {
        return [
            'type' => $this->problemType,
            'title' => (new \ReflectionClass($this))->getShortName(),
            'detail' => $this->getMessage(),
            'status' => $this->statusCode,
        ];
    }
}
