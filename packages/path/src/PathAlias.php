<?php

declare(strict_types=1);

namespace Aurora\Path;

use Aurora\Entity\ContentEntityBase;

/**
 * Represents a URL path alias entity.
 *
 * Maps an internal system path (e.g. '/node/42') to a clean URL alias
 * (e.g. '/about-us'). Supports language-specific aliases and a published
 * status to control alias visibility.
 */
final class PathAlias extends ContentEntityBase
{
    /**
     * @param array<string, mixed> $values Initial entity values.
     */
    public function __construct(array $values = [])
    {
        // Set defaults before passing to parent.
        $values += [
            'langcode' => 'en',
            'status' => true,
        ];

        parent::__construct(
            values: $values,
            entityTypeId: 'path_alias',
            entityKeys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'alias',
                'langcode' => 'langcode',
            ],
        );
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

        $this->set('alias', $alias);

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
        $this->set('langcode', $langcode);

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
