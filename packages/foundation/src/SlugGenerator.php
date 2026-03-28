<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation;

final class SlugGenerator
{
    public static function generate(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
