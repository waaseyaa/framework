<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

interface FieldFormatterInterface
{
    /**
     * @param array<string, mixed> $settings
     */
    public function format(mixed $value, array $settings = []): string;
}
