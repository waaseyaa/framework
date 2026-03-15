<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase6;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Media\File;
use Waaseyaa\Media\InMemoryFileRepository;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaType;
use Waaseyaa\Validation\Constraint\NotEmpty;

/**
 * Integration tests for waaseyaa/media + waaseyaa/validation.
 *
 * Verifies that media entities work with file storage, that File value
 * objects report correct metadata, and that validation integrates with
 * media entity creation.
 */
#[CoversNothing]
final class MediaFileIntegrationTest extends TestCase
{
    private InMemoryFileRepository $fileRepository;
    private EntityValidator $entityValidator;

    protected function setUp(): void
    {
        $this->fileRepository = new InMemoryFileRepository();

        $validator = Validation::createValidatorBuilder()->getValidator();
        $this->entityValidator = new EntityValidator($validator);
    }

    #[Test]
    public function mediaTypeDefinesMediaKind(): void
    {
        $imageType = new MediaType([
            'id' => 'image',
            'label' => 'Image',
            'source' => 'image',
            'description' => 'An image media type.',
            'source_configuration' => ['allowed_extensions' => 'jpg png gif'],
        ]);

        $this->assertSame('image', $imageType->id());
        $this->assertSame('Image', $imageType->label());
        $this->assertSame('image', $imageType->getSource());
        $this->assertSame('An image media type.', $imageType->getDescription());
        $this->assertSame(['allowed_extensions' => 'jpg png gif'], $imageType->getSourceConfiguration());
    }

    #[Test]
    public function mediaEntityWithFileReference(): void
    {
        // Store a file in the repository.
        $file = new File(
            uri: 'public://images/photo.jpg',
            filename: 'photo.jpg',
            mimeType: 'image/jpeg',
            size: 204800,
            ownerId: 1,
        );
        $this->fileRepository->save($file);

        // Create a media entity referencing the file.
        $media = new Media([
            'mid' => 1,
            'bundle' => 'image',
            'name' => 'Beach Photo',
            'uid' => 1,
            'status' => true,
            'created' => 1700000000,
        ]);
        $media->set('file_uri', $file->uri);

        // Verify media properties.
        $this->assertSame(1, $media->id());
        $this->assertSame('image', $media->getBundle());
        $this->assertSame('Beach Photo', $media->getName());
        $this->assertSame(1, $media->getOwnerId());
        $this->assertTrue($media->isPublished());
        $this->assertSame(1700000000, $media->getCreatedTime());

        // Retrieve the file via its URI stored on the media.
        $loadedFile = $this->fileRepository->load($media->get('file_uri'));
        $this->assertNotNull($loadedFile);
        $this->assertSame('photo.jpg', $loadedFile->filename);
        $this->assertSame('image/jpeg', $loadedFile->mimeType);
        $this->assertSame(204800, $loadedFile->size);
    }

    #[Test]
    public function fileValueObjectProperties(): void
    {
        $imageFile = new File(
            uri: 'public://images/photo.jpg',
            filename: 'photo.jpg',
            mimeType: 'image/jpeg',
            size: 102400,
        );

        $this->assertSame('jpg', $imageFile->getExtension());
        $this->assertTrue($imageFile->isImage());

        $pdfFile = new File(
            uri: 'public://docs/report.pdf',
            filename: 'report.pdf',
            mimeType: 'application/pdf',
            size: 512000,
        );

        $this->assertSame('pdf', $pdfFile->getExtension());
        $this->assertFalse($pdfFile->isImage());

        $pngFile = new File(
            uri: 'public://images/logo.png',
            filename: 'logo.png',
            mimeType: 'image/png',
            size: 8192,
        );

        $this->assertSame('png', $pngFile->getExtension());
        $this->assertTrue($pngFile->isImage());
    }

    #[Test]
    public function fileRepositoryStoreAndRetrieve(): void
    {
        $file1 = new File(
            uri: 'public://images/a.jpg',
            filename: 'a.jpg',
            mimeType: 'image/jpeg',
            size: 1000,
            ownerId: 1,
        );
        $file2 = new File(
            uri: 'public://images/b.png',
            filename: 'b.png',
            mimeType: 'image/png',
            size: 2000,
            ownerId: 1,
        );
        $file3 = new File(
            uri: 'public://docs/c.pdf',
            filename: 'c.pdf',
            mimeType: 'application/pdf',
            size: 5000,
            ownerId: 2,
        );

        $this->fileRepository->save($file1);
        $this->fileRepository->save($file2);
        $this->fileRepository->save($file3);

        // Load by URI.
        $loaded = $this->fileRepository->load('public://images/a.jpg');
        $this->assertNotNull($loaded);
        $this->assertSame('a.jpg', $loaded->filename);

        // Find by owner.
        $ownerFiles = $this->fileRepository->findByOwner(1);
        $this->assertCount(2, $ownerFiles);

        $ownerFiles2 = $this->fileRepository->findByOwner(2);
        $this->assertCount(1, $ownerFiles2);
        $this->assertSame('c.pdf', $ownerFiles2[0]->filename);

        // Non-existent URI.
        $this->assertNull($this->fileRepository->load('public://not-found.txt'));
    }

    #[Test]
    public function fileRepositoryDelete(): void
    {
        $file = new File(
            uri: 'public://temp/upload.tmp',
            filename: 'upload.tmp',
            mimeType: 'application/octet-stream',
            size: 100,
        );
        $this->fileRepository->save($file);
        $this->assertNotNull($this->fileRepository->load($file->uri));

        $deleted = $this->fileRepository->delete($file->uri);
        $this->assertTrue($deleted);
        $this->assertNull($this->fileRepository->load($file->uri));

        // Deleting again returns false.
        $this->assertFalse($this->fileRepository->delete($file->uri));
    }

    #[Test]
    public function mediaValidationRequiredFields(): void
    {
        // Media entity with empty name should fail validation.
        $media = new Media([
            'mid' => 1,
            'bundle' => 'image',
            'name' => '',
            'uid' => 1,
        ]);

        $violations = $this->entityValidator->validate($media, [
            'name' => [new NotEmpty()],
        ]);

        $this->assertCount(1, $violations, 'Empty media name should produce a violation.');
        $this->assertSame('name', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function validMediaPassesValidation(): void
    {
        $media = new Media([
            'mid' => 1,
            'bundle' => 'image',
            'name' => 'Valid Photo',
            'uid' => 1,
            'status' => true,
        ]);

        $violations = $this->entityValidator->validate($media, [
            'name' => [new NotEmpty()],
        ]);

        $this->assertCount(0, $violations, 'Valid media should have no violations.');
    }

    #[Test]
    public function mediaCrudLifecycle(): void
    {
        // Create.
        $media = new Media([
            'bundle' => 'image',
            'name' => 'Sunset Photo',
            'uid' => 1,
            'status' => true,
            'created' => 1700000000,
        ]);
        $this->assertTrue($media->isNew());
        $this->assertSame('Sunset Photo', $media->getName());

        // Store associated file.
        $file = new File(
            uri: 'public://images/sunset.jpg',
            filename: 'sunset.jpg',
            mimeType: 'image/jpeg',
            size: 500000,
            ownerId: 1,
        );
        $this->fileRepository->save($file);
        $media->set('file_uri', $file->uri);

        // Update.
        $media->setName('Beautiful Sunset Photo');
        $media->setChangedTime(1700001000);
        $this->assertSame('Beautiful Sunset Photo', $media->getName());
        $this->assertSame(1700001000, $media->getChangedTime());

        // Verify file is still accessible.
        $loadedFile = $this->fileRepository->load($media->get('file_uri'));
        $this->assertNotNull($loadedFile);
        $this->assertTrue($loadedFile->isImage());

        // Unpublish.
        $media->setPublished(false);
        $this->assertFalse($media->isPublished());
    }

    #[Test]
    public function multipleMediaTypesCoexist(): void
    {
        $imageType = new MediaType([
            'id' => 'image',
            'label' => 'Image',
            'source' => 'image',
        ]);
        $documentType = new MediaType([
            'id' => 'document',
            'label' => 'Document',
            'source' => 'file',
        ]);
        $videoType = new MediaType([
            'id' => 'video',
            'label' => 'Video',
            'source' => 'oembed',
        ]);

        $this->assertSame('image', $imageType->getSource());
        $this->assertSame('file', $documentType->getSource());
        $this->assertSame('oembed', $videoType->getSource());

        // Create media of different types.
        $imageMedia = new Media(['mid' => 1, 'bundle' => 'image', 'name' => 'Photo']);
        $docMedia = new Media(['mid' => 2, 'bundle' => 'document', 'name' => 'Report']);
        $videoMedia = new Media(['mid' => 3, 'bundle' => 'video', 'name' => 'Tutorial']);

        $this->assertSame('image', $imageMedia->getBundle());
        $this->assertSame('document', $docMedia->getBundle());
        $this->assertSame('video', $videoMedia->getBundle());
    }

    #[Test]
    public function mediaTypeConfigExport(): void
    {
        $mediaType = new MediaType([
            'id' => 'image',
            'label' => 'Image',
            'source' => 'image',
            'description' => 'Upload images.',
            'source_configuration' => ['max_size' => '10MB'],
        ]);

        $config = $mediaType->toConfig();

        $this->assertSame('image', $config['id']);
        $this->assertSame('Image', $config['label']);
        $this->assertSame('image', $config['source']);
        $this->assertSame('Upload images.', $config['description']);
        $this->assertSame(['max_size' => '10MB'], $config['source_configuration']);
    }

    #[Test]
    public function fileOwnershipTracking(): void
    {
        // Store files belonging to different users.
        $user1Files = [
            new File(uri: 'public://u1/a.jpg', filename: 'a.jpg', mimeType: 'image/jpeg', size: 100, ownerId: 1),
            new File(uri: 'public://u1/b.jpg', filename: 'b.jpg', mimeType: 'image/jpeg', size: 200, ownerId: 1),
        ];
        $user2Files = [
            new File(uri: 'public://u2/c.pdf', filename: 'c.pdf', mimeType: 'application/pdf', size: 300, ownerId: 2),
        ];

        foreach ([...$user1Files, ...$user2Files] as $file) {
            $this->fileRepository->save($file);
        }

        // Create media entities referencing these files.
        $media1 = new Media(['mid' => 1, 'bundle' => 'image', 'name' => 'Photo A', 'uid' => 1]);
        $media1->set('file_uri', 'public://u1/a.jpg');

        $media2 = new Media(['mid' => 2, 'bundle' => 'image', 'name' => 'Photo B', 'uid' => 1]);
        $media2->set('file_uri', 'public://u1/b.jpg');

        // Verify ownership tracking via file repository.
        $owner1Files = $this->fileRepository->findByOwner(1);
        $this->assertCount(2, $owner1Files);

        $owner2Files = $this->fileRepository->findByOwner(2);
        $this->assertCount(1, $owner2Files);

        // Files referenced by media entities match.
        $fileFromMedia = $this->fileRepository->load($media1->get('file_uri'));
        $this->assertNotNull($fileFromMedia);
        $this->assertSame(1, $fileFromMedia->ownerId);
    }
}
