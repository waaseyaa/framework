<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

/**
 * Canonical permission identifiers and definitions for node access policies.
 *
 * Applications pass their authoritative bundle ids to {@see forBundles()};
 * role providers use the operation methods below so grants and catalogue
 * definitions cannot drift into separately maintained string templates.
 *
 * @api
 */
final class NodePermissions
{
    public const string ADMINISTER = 'administer nodes';
    public const string ACCESS_CONTENT = 'access content';
    public const string VIEW_OWN_UNPUBLISHED = 'view own unpublished content';

    public static function create(string $bundle): string
    {
        return 'create ' . self::subject($bundle) . ' content';
    }

    public static function editAny(string $bundle): string
    {
        return 'edit any ' . self::subject($bundle) . ' content';
    }

    public static function editOwn(string $bundle): string
    {
        return 'edit own ' . self::subject($bundle) . ' content';
    }

    public static function deleteAny(string $bundle): string
    {
        return 'delete any ' . self::subject($bundle) . ' content';
    }

    public static function deleteOwn(string $bundle): string
    {
        return 'delete own ' . self::subject($bundle) . ' content';
    }

    /**
     * @param iterable<mixed> $bundles
     * @return array<string, array{title: string, description: string}>
     */
    public static function forBundles(iterable $bundles): array
    {
        $definitions = [];
        foreach (self::subjects($bundles) as $bundle) {
            $label = self::label($bundle);
            $definitions[self::create($bundle)] = self::definition("Create $label content", "Create content in the $bundle bundle.");
            $definitions[self::editAny($bundle)] = self::definition("Edit any $label content", "Edit content in the $bundle bundle regardless of owner.");
            $definitions[self::editOwn($bundle)] = self::definition("Edit own $label content", "Edit content owned by the acting account in the $bundle bundle.");
            $definitions[self::deleteAny($bundle)] = self::definition("Delete any $label content", "Delete content in the $bundle bundle regardless of owner.");
            $definitions[self::deleteOwn($bundle)] = self::definition("Delete own $label content", "Delete content owned by the acting account in the $bundle bundle.");
        }
        ksort($definitions, SORT_STRING);

        return $definitions;
    }

    /** @return array{title: string, description: string} */
    private static function definition(string $title, string $description): array
    {
        return ['title' => $title, 'description' => $description];
    }

    /** @param iterable<mixed> $subjects @return list<string> */
    private static function subjects(iterable $subjects): array
    {
        $result = [];
        foreach ($subjects as $subject) {
            if (!is_string($subject)) {
                throw new \InvalidArgumentException('Node permission bundle ids must be strings.');
            }
            $result[self::subject($subject)] = true;
        }
        $ids = array_keys($result);
        sort($ids, SORT_STRING);

        return $ids;
    }

    private static function subject(string $subject): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $subject) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid node permission bundle id "%s".', $subject));
        }

        return $subject;
    }

    private static function label(string $subject): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $subject));
    }
}
