<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\MediaType;

/**
 * @covers \Waaseyaa\Media\MediaType
 */
final class MediaTypeTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $type = new MediaType(['id' => 'image', 'label' => 'Image']);

        $this->assertSame('media_type', $type->getEntityTypeId());
    }

    public function testIdAndLabel(): void
    {
        $type = new MediaType(['id' => 'document', 'label' => 'Document']);

        $this->assertSame('document', $type->id());
        $this->assertSame('Document', $type->label());
    }

    public function testDefaultValues(): void
    {
        $type = new MediaType();

        $this->assertSame('', $type->getSource());
        $this->assertSame('', $type->getDescription());
        $this->assertSame([], $type->getSourceConfiguration());
    }

    public function testGetAndSetSource(): void
    {
        $type = new MediaType(['id' => 'image', 'label' => 'Image']);

        $this->assertSame('', $type->getSource());

        $result = $type->setSource('image');

        $this->assertSame($type, $result, 'setSource() should return $this for fluent API');
        $this->assertSame('image', $type->getSource());
    }

    public function testSourceFromConstructor(): void
    {
        $type = new MediaType([
            'id' => 'video',
            'label' => 'Video',
            'source' => 'oembed',
        ]);

        $this->assertSame('oembed', $type->getSource());
    }

    public function testGetAndSetDescription(): void
    {
        $type = new MediaType(['id' => 'image', 'label' => 'Image']);

        $result = $type->setDescription('Upload and manage images');

        $this->assertSame($type, $result);
        $this->assertSame('Upload and manage images', $type->getDescription());
    }

    public function testDescriptionFromConstructor(): void
    {
        $type = new MediaType([
            'id' => 'image',
            'label' => 'Image',
            'description' => 'Media type for images',
        ]);

        $this->assertSame('Media type for images', $type->getDescription());
    }

    public function testGetAndSetSourceConfiguration(): void
    {
        $type = new MediaType(['id' => 'image', 'label' => 'Image']);

        $config = ['max_resolution' => '1920x1080', 'allowed_extensions' => 'png jpg gif'];
        $result = $type->setSourceConfiguration($config);

        $this->assertSame($type, $result);
        $this->assertSame($config, $type->getSourceConfiguration());
    }

    public function testSourceConfigurationFromConstructor(): void
    {
        $config = ['providers' => ['YouTube', 'Vimeo']];
        $type = new MediaType([
            'id' => 'video',
            'label' => 'Video',
            'source_configuration' => $config,
        ]);

        $this->assertSame($config, $type->getSourceConfiguration());
    }

    public function testToConfigIncludesAllFields(): void
    {
        $type = new MediaType([
            'id' => 'image',
            'label' => 'Image',
            'source' => 'image',
            'description' => 'Image media type',
            'source_configuration' => ['max_size' => '10MB'],
        ]);

        $config = $type->toConfig();

        $this->assertSame('image', $config['id']);
        $this->assertSame('Image', $config['label']);
        $this->assertSame('image', $config['source']);
        $this->assertSame('Image media type', $config['description']);
        $this->assertSame(['max_size' => '10MB'], $config['source_configuration']);
        $this->assertTrue($config['status']);
    }

    public function testToConfigWithoutSourceConfiguration(): void
    {
        $type = new MediaType([
            'id' => 'file',
            'label' => 'File',
            'source' => 'file',
        ]);

        $config = $type->toConfig();

        $this->assertArrayNotHasKey('source_configuration', $config);
        $this->assertSame('file', $config['source']);
        $this->assertSame('', $config['description']);
    }

    public function testStatusInheritedFromConfigEntityBase(): void
    {
        $type = new MediaType(['id' => 'image', 'label' => 'Image']);

        $this->assertTrue($type->status());

        $type->disable();
        $this->assertFalse($type->status());

        $type->enable();
        $this->assertTrue($type->status());
    }

    public function testStatusFromConstructor(): void
    {
        $type = new MediaType(['id' => 'image', 'label' => 'Image', 'status' => false]);

        $this->assertFalse($type->status());
    }

    public function testIsNew(): void
    {
        $type = new MediaType();
        $this->assertTrue($type->isNew());

        $type = new MediaType(['id' => 'image', 'label' => 'Image']);
        $this->assertFalse($type->isNew());
    }

    public function testConfigEntityHasNoUuid(): void
    {
        $type = new MediaType(['id' => 'image', 'label' => 'Image']);

        // Config entities do not have UUIDs.
        $this->assertSame('', $type->uuid());
    }

    public function testToArray(): void
    {
        $type = new MediaType(['id' => 'image', 'label' => 'Image']);

        $array = $type->toArray();

        $this->assertSame('image', $array['id']);
        $this->assertSame('Image', $array['label']);
    }

    public function testFluentApi(): void
    {
        $type = new MediaType(['id' => 'remote_video', 'label' => 'Remote Video']);

        $type->setSource('oembed')
            ->setDescription('Embed videos from external providers')
            ->setSourceConfiguration(['providers' => ['YouTube']]);

        $this->assertSame('oembed', $type->getSource());
        $this->assertSame('Embed videos from external providers', $type->getDescription());
        $this->assertSame(['providers' => ['YouTube']], $type->getSourceConfiguration());
    }
}
