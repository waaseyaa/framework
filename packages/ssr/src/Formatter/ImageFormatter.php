<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Formatter;

use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\SSR\Attribute\AsFormatter;

#[AsFormatter(fieldType: 'image')]
final class ImageFormatter implements FieldFormatterInterface
{
    public function format(mixed $value, array $settings = []): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $src = (string) $value;
        $alt = is_string($settings['alt'] ?? null) ? $settings['alt'] : '';
        $class = is_string($settings['image_style'] ?? null) && $settings['image_style'] !== ''
            ? 'image-style-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $settings['image_style'])
            : '';

        return sprintf(
            '<img src="%s" alt="%s"%s>',
            htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($alt, ENT_QUOTES, 'UTF-8'),
            $class !== '' ? ' class="' . htmlspecialchars((string) $class, ENT_QUOTES, 'UTF-8') . '"' : '',
        );
    }
}
