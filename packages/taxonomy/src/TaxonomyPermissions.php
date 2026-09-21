<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

/** Canonical permission family for taxonomy access policies. @api */
final class TaxonomyPermissions
{
    public const string ADMINISTER = 'administer taxonomy';

    public static function create(string $vocabulary): string
    {
        return 'create terms in ' . self::subject($vocabulary);
    }
    public static function edit(string $vocabulary): string
    {
        return 'edit terms in ' . self::subject($vocabulary);
    }
    public static function delete(string $vocabulary): string
    {
        return 'delete terms in ' . self::subject($vocabulary);
    }

    /** @param iterable<mixed> $vocabularies @return array<string, array{title: string, description: string}> */
    public static function forVocabularies(iterable $vocabularies): array
    {
        $subjects = [];
        foreach ($vocabularies as $vocabulary) {
            if (!is_string($vocabulary)) {
                throw new \InvalidArgumentException('Taxonomy permission vocabulary ids must be strings.');
            }
            $subjects[self::subject($vocabulary)] = true;
        }
        $definitions = [];
        $ids = array_keys($subjects);
        sort($ids, SORT_STRING);
        foreach ($ids as $vocabulary) {
            $label = ucfirst(str_replace(['_', '-'], ' ', $vocabulary));
            $definitions[self::create($vocabulary)] = ['title' => "Create terms in $label", 'description' => "Create terms in the $vocabulary vocabulary."];
            $definitions[self::edit($vocabulary)] = ['title' => "Edit terms in $label", 'description' => "Edit terms in the $vocabulary vocabulary."];
            $definitions[self::delete($vocabulary)] = ['title' => "Delete terms in $label", 'description' => "Delete terms in the $vocabulary vocabulary."];
        }
        ksort($definitions, SORT_STRING);

        return $definitions;
    }

    private static function subject(string $subject): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $subject) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid taxonomy permission vocabulary id "%s".', $subject));
        }

        return $subject;
    }
}
