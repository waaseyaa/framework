<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Formatter;

use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\SSR\Attribute\AsFormatter;

#[AsFormatter(fieldType: 'entity_reference')]
final class EntityReferenceFormatter implements FieldFormatterInterface
{
    public function format(mixed $value, array $settings = []): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $id = (string) $value;
        $label = is_string($settings['label'] ?? null) && $settings['label'] !== ''
            ? $settings['label']
            : $id;
        $pattern = is_string($settings['url_pattern'] ?? null) && $settings['url_pattern'] !== ''
            ? $settings['url_pattern']
            : '/entity/{id}';
        $href = str_replace('{id}', rawurlencode($id), $pattern);

        return sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
        );
    }
}
