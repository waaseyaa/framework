<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

final readonly class HandlerArgument
{
    public string|int|float|bool|array|null $default;

    public function __construct(
        public string $name,
        public HandlerArgumentMode $mode = HandlerArgumentMode::Optional,
        public string $description = '',
        string|int|float|bool|array|null $default = null,
        public bool $isArray = false,
    ) {
        $this->default = $isArray && $default === null ? [] : $default;
    }
}
