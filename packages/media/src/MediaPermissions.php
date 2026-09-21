<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

/** Canonical permission family for media access policies. @api */
final class MediaPermissions
{
    public const string ADMINISTER = 'administer media';
    public const string ACCESS = 'access media';
    public const string VIEW_OWN_UNPUBLISHED = 'view own unpublished media';

    public static function create(string $type): string
    {
        return 'create ' . self::subject($type) . ' media';
    }
    public static function editAny(string $type): string
    {
        return 'edit any ' . self::subject($type) . ' media';
    }
    public static function editOwn(string $type): string
    {
        return 'edit own ' . self::subject($type) . ' media';
    }
    public static function deleteAny(string $type): string
    {
        return 'delete any ' . self::subject($type) . ' media';
    }
    public static function deleteOwn(string $type): string
    {
        return 'delete own ' . self::subject($type) . ' media';
    }

    /** @param iterable<mixed> $types @return array<string, array{title: string, description: string}> */
    public static function forTypes(iterable $types): array
    {
        $subjects = [];
        foreach ($types as $type) {
            if (!is_string($type)) {
                throw new \InvalidArgumentException('Media permission type ids must be strings.');
            }
            $subjects[self::subject($type)] = true;
        }
        $definitions = [];
        $ids = array_keys($subjects);
        sort($ids, SORT_STRING);
        foreach ($ids as $type) {
            $label = ucfirst(str_replace(['_', '-'], ' ', $type));
            $definitions[self::create($type)] = ['title' => "Create $label media", 'description' => "Create media in the $type media type."];
            $definitions[self::editAny($type)] = ['title' => "Edit any $label media", 'description' => "Edit media in the $type media type regardless of owner."];
            $definitions[self::editOwn($type)] = ['title' => "Edit own $label media", 'description' => "Edit media owned by the acting account in the $type media type."];
            $definitions[self::deleteAny($type)] = ['title' => "Delete any $label media", 'description' => "Delete media in the $type media type regardless of owner."];
            $definitions[self::deleteOwn($type)] = ['title' => "Delete own $label media", 'description' => "Delete media owned by the acting account in the $type media type."];
        }
        ksort($definitions, SORT_STRING);

        return $definitions;
    }

    private static function subject(string $subject): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $subject) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid media permission type id "%s".', $subject));
        }

        return $subject;
    }
}
