<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

final readonly class HandlerOption
{
    public string|int|float|bool|array|null $default;

    public function __construct(
        public string $name,
        public ?string $shortcut = null,
        public HandlerOptionMode $mode = HandlerOptionMode::None,
        public string $description = '',
        string|int|float|bool|array|null $default = null,
    ) {
        if ($mode === HandlerOptionMode::Array_) {
            $default ??= [];
        } elseif ($mode === HandlerOptionMode::None) {
            $default = null;
        } elseif ($mode === HandlerOptionMode::Negatable) {
            $default = null;
        }

        $this->default = $default;
    }
}
