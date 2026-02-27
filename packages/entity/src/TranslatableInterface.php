<?php

declare(strict_types=1);

namespace Aurora\Entity;

/**
 * Entities implementing this support multiple languages.
 *
 * Unlike Drupal, an Aurora entity object represents ONE language
 * at a time. getTranslation() returns a separate entity object
 * for the requested language. This simplification removes hidden
 * state and makes field values unambiguous.
 */
interface TranslatableInterface
{
    public function language(): string;

    /** @return string[] Language codes */
    public function getTranslationLanguages(): array;

    public function hasTranslation(string $langcode): bool;

    public function getTranslation(string $langcode): static;
}
