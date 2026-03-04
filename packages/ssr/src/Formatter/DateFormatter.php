<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Formatter;

use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\SSR\Attribute\AsFormatter;

#[AsFormatter(fieldType: 'datetime')]
final class DateFormatter implements FieldFormatterInterface
{
    public function format(mixed $value, array $settings = []): string
    {
        $format = is_string($settings['format'] ?? null) ? $settings['format'] : 'Y-m-d H:i';

        if ($value === null || $value === '') {
            return '';
        }

        try {
            $date = is_numeric($value)
                ? new \DateTimeImmutable('@' . (int) $value)
                : new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return '';
        }

        return $date->format($format);
    }
}
