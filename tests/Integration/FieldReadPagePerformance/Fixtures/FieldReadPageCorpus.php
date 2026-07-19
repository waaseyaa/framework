<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FieldReadPagePerformance\Fixtures;

final class FieldReadPageCorpus
{
    public const int MEMBER_COUNT = 100;
    public const int DYNAMIC_CONTENT_FIELDS = 24;
    public const int CONTENT_RENDERED_FIELDS = 31;
    public const int FIXED_TIMESTAMP = 1_735_689_600;

    /** @return list<string> */
    public static function contentFieldNames(): array
    {
        $fields = [];
        for ($i = 1; $i <= self::DYNAMIC_CONTENT_FIELDS; ++$i) {
            $fields[] = sprintf('section_%02d', $i);
        }

        return $fields;
    }

    /** @return array<string, array<string, mixed>> */
    public static function contentDisplay(): array
    {
        $display = [
            'title' => ['formatter' => 'string', 'weight' => 0],
            'type' => ['formatter' => 'string', 'weight' => 1],
            'slug' => ['formatter' => 'string', 'weight' => 2],
            'promote' => ['formatter' => 'boolean', 'weight' => 3],
            'sticky' => ['formatter' => 'boolean', 'weight' => 4],
            'created' => ['formatter' => 'datetime', 'settings' => ['format' => 'Y-m-d'], 'weight' => 5],
            'changed' => ['formatter' => 'datetime', 'settings' => ['format' => 'Y-m-d'], 'weight' => 6],
        ];
        foreach (self::contentFieldNames() as $index => $field) {
            $display[$field] = ['formatter' => 'text', 'weight' => 7 + $index];
        }

        return $display;
    }

    /** @return array<string, mixed> */
    public static function nodeValues(): array
    {
        $values = [
            'title' => 'Frozen Public Performance Page',
            'type' => 'article',
            'slug' => 'frozen-public-performance-page',
            'status' => 1,
            'promote' => 0,
            'sticky' => 0,
            'created' => self::FIXED_TIMESTAMP,
            'changed' => self::FIXED_TIMESTAMP,
        ];
        foreach (self::contentFieldNames() as $index => $field) {
            $prefix = sprintf('SECTION-%02d:', $index + 1);
            $values[$field] = $prefix . str_repeat(chr(65 + ($index % 26)), 2_720 - strlen($prefix));
        }

        return $values;
    }

    /** @return list<array<string, mixed>> */
    public static function users(): array
    {
        $users = [[
            'bundle' => 'user',
            'name' => 'performance-viewer',
            'mail' => 'viewer@example.invalid',
            'status' => 1,
            'roles' => ['authenticated'],
            'permissions' => ['access user profiles'],
            'created' => self::FIXED_TIMESTAMP,
        ]];
        for ($i = 1; $i <= self::MEMBER_COUNT; ++$i) {
            $users[] = [
                'bundle' => 'user',
                'name' => sprintf('member-%03d-frozen-display-name', $i),
                'mail' => sprintf('member-%03d@example.invalid', $i),
                'status' => 1,
                'roles' => ['authenticated'],
                'permissions' => [],
                'created' => self::FIXED_TIMESTAMP + $i,
            ];
        }

        return $users;
    }
}
