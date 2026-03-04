<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Formatter;

use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\SSR\Attribute\AsFormatter;

#[AsFormatter(fieldType: 'text_long')]
final class HtmlFormatter implements FieldFormatterInterface
{
    public function format(mixed $value, array $settings = []): string
    {
        return (string) ($value ?? '');
    }
}
