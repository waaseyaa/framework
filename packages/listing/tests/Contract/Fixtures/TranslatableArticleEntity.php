<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Contract\Fixtures;

use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\TranslatableInterface;
use Waaseyaa\Listing\Tests\Fixtures\PublicListingFields;

/**
 * Translatable variant of {@see ArticleEntity}.
 *
 * Implements `TranslatableInterface` with minimal semantics — enough for the
 * resolver contract suite to assert FR-046..FR-049 behaviour. Translation
 * mutation methods (`addTranslation`, `removeTranslation`, etc.) are not
 * exercised by the listing pipeline — they belong to write-path tests and
 * are stubbed here as no-ops/throws.
 *
 * Constructor mirrors {@see ContentEntityBase::__construct()} so storage
 * hydration (named-argument call site in `EntityInstantiator`) works.
 */
final class TranslatableArticleEntity extends ContentEntityBase implements TranslatableInterface
{
    use PublicListingFields;

    /**
     * @var array<string, string>
     */
    private const DEFAULT_KEYS = [
        'id' => 'id',
        'label' => 'title',
        'langcode' => 'langcode',
        'default_langcode' => 'default_langcode',
    ];

    public function __construct(
        array $values = [],
        string $entityTypeId = 'article',
        array $entityKeys = self::DEFAULT_KEYS,
        array $fieldDefinitions = [],
    ) {
        parent::__construct(
            $values,
            $entityTypeId !== '' ? $entityTypeId : 'article',
            $entityKeys !== [] ? $entityKeys : self::DEFAULT_KEYS,
            $fieldDefinitions,
        );
    }

    public function getEntityTypeId(): string
    {
        return 'article';
    }

    public function defaultLangcode(): string
    {
        $lc = $this->get('default_langcode');
        if (is_string($lc) && $lc !== '') {
            return $lc;
        }

        return $this->activeLangcode();
    }

    public function activeLangcode(): string
    {
        $lc = $this->get('langcode');

        return is_string($lc) ? $lc : '';
    }

    public function language(): string
    {
        return $this->activeLangcode();
    }

    public function hasTranslation(string $langcode): bool
    {
        return $langcode === $this->activeLangcode();
    }

    public function getTranslation(string $langcode): static
    {
        return $this;
    }

    public function addTranslation(string $langcode): static
    {
        return $this;
    }

    public function removeTranslation(string $langcode): void {}

    public function translations(): iterable
    {
        yield $this->activeLangcode();
    }

    public function getTranslationLanguages(): array
    {
        return [$this->activeLangcode()];
    }

    public function fieldLangcode(string $fieldName): ?string
    {
        return $this->activeLangcode();
    }
}
