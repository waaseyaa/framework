<?php

declare(strict_types=1);

namespace Waaseyaa\Path;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Represents a URL path alias entity.
 *
 * Maps an internal system path (e.g. '/node/42') to a clean URL alias
 * (e.g. '/about-us'). Supports language-specific aliases and a published
 * status to control alias visibility.
 */
#[ContentEntityType(id: 'path_alias', label: 'Path Alias', description: 'URL aliases for human-readable paths', api: true)]
#[ContentEntityKeys(label: 'alias', langcode: 'langcode')]
final class PathAlias extends ContentEntityBase
{
    #[Field(label: 'System path', description: 'Internal path such as /node/1.', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $path = '';

    #[Field(label: 'Alias', description: 'Public alias path.', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $alias = '';

    #[Field(label: 'Language', description: 'Alias language code.', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $langcode = 'en';

    #[Field(type: 'boolean', label: 'Published', description: 'Whether this alias is active.', default: true, read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public bool $status = true;

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see ContentEntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        // Set defaults before passing to parent.
        $values += [
            'langcode' => 'en',
            'status' => true,
        ];

        // EntityBase::__construct() assigns $values straight into $this->values
        // (no setAlias() call), so a PathAlias built via EntityRepository::create()
        // (the JSON:API POST path) would otherwise store whatever byte form the
        // caller sent. Normalize here so every construction path stores NFC.
        if (isset($values['alias']) && is_string($values['alias'])) {
            $values['alias'] = self::normalizeAlias($values['alias']);
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    /**
     * Normalize an alias to its canonical storage and lookup form.
     *
     * Waaseyaa is an Indigenous-language CMS: aliases legitimately carry
     * Unicode letters (long-vowel diacritics, the glottal ʼ U+02BC, Canadian
     * syllabics) and MUST NOT be transliterated, lowercased, or slugified —
     * only canonically composed. Without this, NFC and NFD forms of the same
     * visible alias are distinct byte strings that can both be stored,
     * resolving first-match-wins. A non-root trailing slash is also removed so
     * WordPress-style inbound paths and stored aliases share one canonical
     * lookup key. Modeled on the guard in
     * {@see \Waaseyaa\Foundation\SlugGenerator::generate()}.
     *
     * Exposed as `public` so {@see \Waaseyaa\Path\PathAliasUniquenessListener}
     * can compute the same canonical form for its uniqueness comparison
     * without duplicating the guard.
     */
    public static function normalizeAlias(string $alias): string
    {
        $normalized = \Normalizer::normalize($alias, \Normalizer::FORM_C);

        $canonical = is_string($normalized) ? $normalized : $alias;

        return $canonical === '/' ? $canonical : rtrim($canonical, '/');
    }

    /**
     * Get the internal system path (e.g. '/node/42').
     */
    public function getPath(): string
    {
        return (string) ($this->get('path') ?? '');
    }

    /**
     * Set the internal system path.
     */
    public function setPath(string $path): static
    {
        $this->set('path', $path);

        return $this;
    }

    /**
     * Get the clean URL alias (e.g. '/about-us').
     */
    public function getAlias(): string
    {
        return (string) ($this->get('alias') ?? '');
    }

    /**
     * Set the clean URL alias.
     *
     * @throws \InvalidArgumentException If the alias does not start with '/'.
     */
    public function setAlias(string $alias): static
    {
        if ($alias !== '' && !str_starts_with($alias, '/')) {
            throw new \InvalidArgumentException(
                sprintf('The alias "%s" must start with a forward slash.', $alias),
            );
        }

        $this->set('alias', self::normalizeAlias($alias));

        return $this;
    }

    /**
     * Get the language code for this alias.
     */
    public function getLanguage(): string
    {
        return (string) ($this->get('langcode') ?? 'en');
    }

    /**
     * Set the language code for this alias.
     */
    public function setLanguage(string $langcode): static
    {
        if ($langcode === '') {
            throw new \InvalidArgumentException('Path alias language cannot be empty.');
        }
        $this->_hydrateStructuralLanguages($langcode, $langcode, [$langcode]);

        return $this;
    }

    /**
     * Check if this alias is published (active).
     */
    public function isPublished(): bool
    {
        return (bool) ($this->get('status') ?? true);
    }

    /**
     * Set the published status.
     */
    public function setPublished(bool $published): static
    {
        $this->set('status', $published);

        return $this;
    }
}
