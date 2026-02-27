<?php

declare(strict_types=1);

namespace Aurora\Media;

use Aurora\Entity\ConfigEntityBase;

/**
 * Defines a media type configuration entity.
 *
 * Media types define the different kinds of media (image, video, file, etc.)
 * that can be created. Each media type is associated with a media source
 * plugin that handles the specific media handling logic.
 */
final class MediaType extends ConfigEntityBase
{
    /**
     * The media source plugin ID (e.g. 'file', 'image', 'oembed').
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

    public function __construct(array $values = [])
    {
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

        parent::__construct(
            values: $values,
            entityTypeId: 'media_type',
            entityKeys: ['id' => 'id', 'label' => 'label'],
        );
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
