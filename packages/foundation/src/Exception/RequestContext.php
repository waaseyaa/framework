<?php

declare(strict_types=1);

namespace Aurora\Foundation\Exception;

final readonly class RequestContext
{
    public function __construct(
        public string $format = 'json',
        public string $requestId = '',
        public string $path = '',
        public string $method = 'GET',
    ) {}

    public function isApi(): bool
    {
        return $this->format === 'json';
    }

    public function isCli(): bool
    {
        return $this->format === 'cli';
    }
}
