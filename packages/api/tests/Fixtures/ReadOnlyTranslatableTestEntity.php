<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\TranslatableInterface;

/**
 * A translatable entity that intentionally does NOT implement
 * MutableTranslatableInterface. Used to verify that TranslationController
 * returns a 422 when store() is called on an entity that only supports
 * reading translations, not creating them.
 */
#[ContentEntityType(id: 'readonly')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', bundle: 'type', langcode: 'langcode', default_langcode: 'default_langcode')]
class ReadOnlyTranslatableTestEntity extends ContentEntityBase implements TranslatableInterface
{
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        if (!isset($values['langcode'])) {
            $values['langcode'] = 'en';
        }

        parent::__construct($values, $entityTypeId, $entityKeys, ApiFixtureFieldDefinitions::mergePublic($values, $fieldDefinitions));
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

        return (string) ($this->values[$langcodeKey] ?? 'en');
    }

    /** @return string[] */
    public function getTranslationLanguages(): array
    {
        return [$this->language()];
    }

    public function hasTranslation(string $langcode): bool
    {
        return $langcode === $this->language();
    }

    public function getTranslation(string $langcode): static
    {
        if ($langcode !== $this->language()) {
            throw new \InvalidArgumentException(
                "Translation '{$langcode}' does not exist.",
            );
        }

        return $this;
    }

    public function fieldLangcode(string $fieldName): ?string
    {
        return $this->language();
    }
}
