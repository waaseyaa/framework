<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;

/** Test-only explicit classification for public API fixture values. */
final class ApiFixtureFieldDefinitions
{
    /** @param array<string, mixed> $values @param array<string, mixed> $definitions @return array<string, mixed> */
    public static function mergePublic(array $values, array $definitions): array
    {
        foreach (array_keys($values) as $name) {
            if (!isset($definitions[$name])) {
                $definitions[$name] = new FieldDefinition(name: $name, type: 'string', read: FieldReadLevel::Public);
            }
        }

        return $definitions;
    }
}
