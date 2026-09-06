<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

/**
 * The one closed shape a declared permission must satisfy, shared by every
 * admission point (#2788): the package manifest compiler admits
 * `extra.waaseyaa.permissions` entries for installed packages and the root
 * application, and `Waaseyaa\Access\PermissionHandler::fromProviders()`
 * admits provider contributions at boot. Both report the same
 * owner-grounded `\LogicException` messages, so a malformed entry is refused
 * deterministically wherever it first appears and never silently skipped or
 * coerced.
 *
 * Shape: the id is a non-empty string with no leading, trailing or control
 * characters (a non-string key is never an authored id); the definition is
 * an array carrying a non-empty string `title`, an optional string
 * `description`, and no other member.
 *
 * @internal Boot-integrity contract shared by discovery and the access catalogue.
 */
final class PermissionDefinitionShape
{
    /**
     * @throws \LogicException naming the owner
     */
    public static function assertId(mixed $id, string $owner): string
    {
        if (!is_string($id) || $id === '' || trim($id) !== $id || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new \LogicException(sprintf(
                '%s declares an invalid permission id %s: a permission id is a non-empty string with no leading, trailing or control characters.',
                $owner,
                var_export($id, true),
            ));
        }

        return $id;
    }

    /**
     * @return array{title: string, description: string}
     * @throws \LogicException naming the owner
     */
    public static function assertDefinition(string $id, mixed $definition, string $owner): array
    {
        if (!is_array($definition)) {
            throw new \LogicException(sprintf(
                'Permission "%s" declared by %s must be an array with a "title" and an optional "description"; got %s.',
                $id,
                $owner,
                get_debug_type($definition),
            ));
        }
        $title = $definition['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            throw new \LogicException(sprintf(
                'Permission "%s" declared by %s must carry a non-empty string "title".',
                $id,
                $owner,
            ));
        }
        $description = $definition['description'] ?? '';
        if (!is_string($description)) {
            throw new \LogicException(sprintf(
                'Permission "%s" declared by %s: "description" must be a string when present; got %s.',
                $id,
                $owner,
                get_debug_type($description),
            ));
        }
        foreach (array_keys($definition) as $member) {
            if ($member !== 'title' && $member !== 'description') {
                throw new \LogicException(sprintf(
                    'Permission "%s" declared by %s carries unknown member "%s"; only "title" and "description" are permitted.',
                    $id,
                    $owner,
                    (string) $member,
                ));
            }
        }

        return ['title' => $title, 'description' => $description];
    }
}
