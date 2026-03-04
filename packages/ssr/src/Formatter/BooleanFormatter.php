<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Formatter;

use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\SSR\Attribute\AsFormatter;

#[AsFormatter(fieldType: 'boolean')]
final class BooleanFormatter implements FieldFormatterInterface
{
    public function format(mixed $value, array $settings = []): string
    {
        $trueLabel = is_string($settings['true_label'] ?? null) ? $settings['true_label'] : 'Yes';
        $falseLabel = is_string($settings['false_label'] ?? null) ? $settings['false_label'] : 'No';

        return (bool) $value ? $trueLabel : $falseLabel;
    }
}
