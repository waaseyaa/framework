<?php

declare(strict_types=1);

namespace Aurora\I18n;

/**
 * Holds the two-axis language context: content language and interface language.
 *
 * Content language controls which entity translations load.
 * Interface language controls admin SPA strings and system messages.
 * They can differ -- e.g. a French editor managing English content.
 *
 * Immutable: with* methods return new instances.
 */
final readonly class LanguageContext
{
    public function __construct(
        private Language $contentLanguage,
        private Language $interfaceLanguage,
        private ?string $tenantId = null,
    ) {}

    public function getContentLanguage(): Language
    {
        return $this->contentLanguage;
    }

    public function getInterfaceLanguage(): Language
    {
        return $this->interfaceLanguage;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Returns a new context with a different content language.
     */
    public function withContentLanguage(Language $language): self
    {
        return new self(
            contentLanguage: $language,
            interfaceLanguage: $this->interfaceLanguage,
            tenantId: $this->tenantId,
        );
    }

    /**
     * Returns a new context with a different interface language.
     */
    public function withInterfaceLanguage(Language $language): self
    {
        return new self(
            contentLanguage: $this->contentLanguage,
            interfaceLanguage: $language,
            tenantId: $this->tenantId,
        );
    }

    /**
     * Returns a new context with a different tenant ID.
     */
    public function withTenantId(?string $tenantId): self
    {
        return new self(
            contentLanguage: $this->contentLanguage,
            interfaceLanguage: $this->interfaceLanguage,
            tenantId: $tenantId,
        );
    }
}
