<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient;

final class HttpRequestException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $url,
        public readonly string $method,
        public readonly ?HttpResponse $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
