<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaServiceProvider;
use Waaseyaa\Media\MediaType;
use Waaseyaa\Media\Version\MediaVersion;

#[CoversClass(MediaServiceProvider::class)]
final class MediaServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_media_and_media_type(): void
    {
        $provider = new MediaServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        // WP01 (versioned-blob-media-abstraction-01KSEFTJ) added media_version as a third entity type.
        $this->assertCount(3, $entityTypes);
        $this->assertSame('media', $entityTypes[0]->id());
        $this->assertSame(Media::class, $entityTypes[0]->getClass());
        $this->assertSame('media_type', $entityTypes[1]->id());
        $this->assertSame(MediaType::class, $entityTypes[1]->getClass());
        $this->assertSame('media_version', $entityTypes[2]->id());
        $this->assertSame(MediaVersion::class, $entityTypes[2]->getClass());
    }

    #[Test]
    public function media_registration_exposes_its_bundle_provider_and_generic_upload_field(): void
    {
        $provider = new MediaServiceProvider();
        $provider->register();
        $mediaType = $provider->getEntityTypes()[0];

        $this->assertSame('media_type', $mediaType->getBundleEntityType());

        $definitions = $mediaType->getFieldDefinitions();
        $this->assertArrayHasKey('name', $definitions);
        $this->assertTrue($definitions['name']->isRequired());
        $this->assertArrayHasKey('bundle', $definitions);
        $this->assertTrue($definitions['bundle']->isRequired());
        $this->assertArrayHasKey('source_uri', $definitions);
        $this->assertSame('string', $definitions['source_uri']->getType());
        $this->assertSame('file', $definitions['source_uri']->getSetting('widget'));
        $this->assertSame(FieldStorage::Data, $definitions['source_uri']->getStored());
        $this->assertSame(FieldReadLevel::Protected, $definitions['uid']->getReadLevel());
        $this->assertTrue($definitions['uid']->getSetting('authorizationInput'));

        $schema = new SchemaPresenter()->present($mediaType, $definitions);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('bundle', $schema['properties']);
        $this->assertSame('file', $schema['properties']['source_uri']['x-widget']);
    }
}
