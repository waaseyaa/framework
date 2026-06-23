<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use Waaseyaa\Entity\ConfigEntityBase;

/**
 * Defines a media type configuration entity.
 *
 * Media types define the different kinds of media (image, video, file, etc.)
 * that can be created.
 *
 * NOTE (claim-vs-code): `$source`/`$sourceConfiguration` are stored as METADATA
 * only. The media source-plugin system they describe — a `MediaSourceInterface`,
 * a plugin registry, and a resolver that maps a media entity to its file
 * location — is NOT implemented in this package today: nothing consults `$source`
 * to handle media, resolve a file URI, or generate thumbnails/derivatives. These
 * fields exist for forward compatibility; building the substrate (and an
 * authorized media download, reusing the attachment `PrivateFileStore` from
 * #1761) is tracked as a future feature in #1762. Do not rely on source-plugin
 * resolution until then.
 */
final class MediaType extends ConfigEntityBase
{
    /**
     * The intended media source plugin ID (e.g. 'file', 'image', 'oembed').
     *
     * Metadata only — no source-plugin system consumes this yet (see the class
     * docblock + #1762).
     */
    protected string $source = '';

    /**
     * A description of this media type.
     */
    protected string $description = '';

    /**
     * Source plugin configuration.
     *
     * @var array<string, mixed>
     */
    protected array $sourceConfiguration = [];

    /**
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see EntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
    ) {
        // Extract media-type-specific values before passing to parent.
        if (isset($values['source']) && \is_string($values['source'])) {
            $this->source = $values['source'];
        }

        if (isset($values['description']) && \is_string($values['description'])) {
            $this->description = $values['description'];
        }

        if (isset($values['source_configuration']) && \is_array($values['source_configuration'])) {
            $this->sourceConfiguration = $values['source_configuration'];
        }

        $entityTypeId = $entityTypeId !== '' ? $entityTypeId : 'media_type';
        $entityKeys = $entityKeys !== [] ? $entityKeys : ['id' => 'id', 'label' => 'label'];

        parent::__construct($values, $entityTypeId, $entityKeys);
    }

    /**
     * Gets the media source plugin ID.
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Sets the media source plugin ID.
     */
    public function setSource(string $source): static
    {
        $this->source = $source;
        $this->values['source'] = $source;

        return $this;
    }

    /**
     * Gets the description of this media type.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Sets the description of this media type.
     */
    public function setDescription(string $description): static
    {
        $this->description = $description;
        $this->values['description'] = $description;

        return $this;
    }

    /**
     * Gets the source plugin configuration.
     *
     * @return array<string, mixed>
     */
    public function getSourceConfiguration(): array
    {
        return $this->sourceConfiguration;
    }

    /**
     * Sets the source plugin configuration.
     *
     * @param array<string, mixed> $configuration
     */
    public function setSourceConfiguration(array $configuration): static
    {
        $this->sourceConfiguration = $configuration;
        $this->values['source_configuration'] = $configuration;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function toConfig(): array
    {
        $config = parent::toConfig();

        $config['source'] = $this->source;
        $config['description'] = $this->description;

        if ($this->sourceConfiguration !== []) {
            $config['source_configuration'] = $this->sourceConfiguration;
        }

        return $config;
    }
}
