<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Fixtures;

use Aurora\Entity\ContentEntityBase;
use Aurora\Entity\TranslatableInterface;

/**
 * Test entity that supports translations.
 *
 * Each TranslatableTestEntity object represents one language. Translations
 * are stored as separate entity objects tracked by the original entity.
 */
class TranslatableTestEntity extends ContentEntityBase implements TranslatableInterface
{
    /**
     * Translation storage: langcode => TranslatableTestEntity.
     *
     * @var array<string, TranslatableTestEntity>
     */
    private array $translations = [];

    public function __construct(
        array $values = [],
        string $entityTypeId = 'article',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        $defaultKeys = [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
            'bundle' => 'type',
            'langcode' => 'langcode',
        ];

        // Set default langcode if not provided.
        if (!isset($values['langcode'])) {
            $values['langcode'] = 'en';
        }

        parent::__construct(
            $values,
            $entityTypeId,
            $entityKeys !== [] ? $entityKeys : $defaultKeys,
            $fieldDefinitions,
        );
    }

    public function language(): string
    {
        $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';

        return (string) ($this->values[$langcodeKey] ?? 'en');
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

        // Create a new translation with the same base values.
        $values = $this->values;
        $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';
        $values[$langcodeKey] = $langcode;

        // Keep the same uuid for the translation.
        $translation = new static(
            values: $values,
            entityTypeId: $this->entityTypeId,
            entityKeys: $this->entityKeys,
            fieldDefinitions: $this->fieldDefinitions,
        );

        // Share the same ID.
        $idKey = $this->entityKeys['id'] ?? 'id';
        if (isset($this->values[$idKey])) {
            $translation->values[$idKey] = $this->values[$idKey];
        }

        // Override uuid to use the parent's uuid.
        $uuidKey = $this->entityKeys['uuid'] ?? 'uuid';
        $translation->values[$uuidKey] = $this->values[$uuidKey];

        // Track the translation.
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
