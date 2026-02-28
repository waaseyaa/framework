<?php

declare(strict_types=1);

namespace Aurora\I18n;

/**
 * Resolves the language fallback order for a given langcode.
 *
 * Given a starting language (e.g. "fr-CA"), produces an ordered list
 * of language codes to try when loading translations, ending with the
 * default language.
 *
 * Supports custom fallback mappings (e.g. "pt-BR" -> "pt" -> "es" -> "en")
 * and automatic derivation from regional variants (e.g. "fr-CA" -> "fr").
 */
final readonly class FallbackChain
{
    /** @var string[] The ordered list of language codes. */
    private array $chain;

    /**
     * @param string[] $chain Ordered list of language codes.
     */
    public function __construct(array $chain)
    {
        if ($chain === []) {
            throw new \InvalidArgumentException('Fallback chain must contain at least one language.');
        }
        $this->chain = array_values($chain);
    }

    /**
     * Build a FallbackChain from a LanguageManagerInterface for the given langcode.
     */
    public static function fromManager(LanguageManagerInterface $manager, string $langcode): self
    {
        return new self($manager->getFallbackChain($langcode));
    }

    /**
     * Returns the primary (first) language code in the chain.
     */
    public function primary(): string
    {
        return $this->chain[0];
    }

    /**
     * Returns all language codes in fallback order.
     *
     * @return string[]
     */
    public function all(): array
    {
        return $this->chain;
    }

    /**
     * Returns the number of languages in the chain.
     */
    public function count(): int
    {
        return \count($this->chain);
    }

    /**
     * Returns true if the given langcode is in this fallback chain.
     */
    public function contains(string $langcode): bool
    {
        return \in_array($langcode, $this->chain, true);
    }
}
