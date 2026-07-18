<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Api\MutableTranslatableInterface;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Test entity that supports translations.
 *
 * Each TranslatableTestEntity object represents one language. Translations
 * are stored as separate entity objects tracked by the original entity.
 */
#[ContentEntityType(id: 'article')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', bundle: 'type', langcode: 'langcode', default_langcode: 'default_langcode')]
class TranslatableTestEntity extends ContentEntityBase implements MutableTranslatableInterface
{
    /**
     * Translation storage: langcode => TranslatableTestEntity.
     *
     * @var array<string, TranslatableTestEntity>
     */
    private array $translations = [];

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        // Set default langcode if not provided.
        if (!isset($values['langcode'])) {
            $values['langcode'] = 'en';
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    /**
     * @return array<string, string>
     */
    public static function definitionKeys(): array
    {
        return EntityMetadataReader::forClass(self::class)->keys;
    }

    public function language(): string
    {
        $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';

        return (string) ($this->get($langcodeKey) ?? 'en');
    }

    /** @return string[] */
    public function getTranslationLanguages(): array
    {
        $languages = [$this->language()];
        foreach (array_keys($this->translations) as $langcode) {
            if (!in_array($langcode, $languages, true)) {
                $languages[] = $langcode;
            }
        }

        return $languages;
    }

    public function hasTranslation(string $langcode): bool
    {
        if ($langcode === $this->language()) {
            return true;
        }

        return isset($this->translations[$langcode]);
    }

    public function getTranslation(string $langcode): static
    {
        if ($langcode === $this->language()) {
            return $this;
        }

        if (isset($this->translations[$langcode])) {
            return $this->translations[$langcode];
        }

        throw new \InvalidArgumentException(
            "Translation '{$langcode}' does not exist. Use addTranslation() to create it.",
        );
    }

    public function addTranslation(string $langcode): static
    {
        if ($this->hasTranslation($langcode)) {
            throw new \InvalidArgumentException(
                "Translation '{$langcode}' already exists. Use getTranslation() to retrieve it.",
            );
        }

        // Create a new translation object seeded with the base entity's values.
        $values = $this->toArray();
        $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';
        $values[$langcodeKey] = $langcode;

        $translation = new static(
            $values,
            $this->entityTypeId,
            $this->entityKeys,
            $this->fieldDefinitions,
        );

        // Share the same ID and UUID as the source entity.
        $idKey = $this->entityKeys['id'] ?? 'id';
        $id = $this->get($idKey);
        if ($id !== null) {
            $translation->set($idKey, $id);
        }

        $uuidKey = $this->entityKeys['uuid'] ?? 'uuid';
        $translation->set($uuidKey, $this->get($uuidKey));

        // Register the new translation so hasTranslation() and getTranslation()
        // return it from now on.
        $this->translations[$langcode] = $translation;

        return $translation;
    }

    /**
     * Remove a translation by language code.
     */
    public function removeTranslation(string $langcode): void
    {
        unset($this->translations[$langcode]);
    }
}
