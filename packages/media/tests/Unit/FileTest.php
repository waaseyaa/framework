<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\File;

/**
 * @covers \Waaseyaa\Media\File
 */
final class FileTest extends TestCase
{
    public function testConstructorWithAllParameters(): void
    {
        $file = new File(
            uri: 'public://images/photo.jpg',
            filename: 'photo.jpg',
            mimeType: 'image/jpeg',
            size: 204800,
            status: 'permanent',
            ownerId: 5,
            createdTime: 1700000000,
        );

        $this->assertSame('public://images/photo.jpg', $file->uri);
        $this->assertSame('photo.jpg', $file->filename);
        $this->assertSame('image/jpeg', $file->mimeType);
        $this->assertSame(204800, $file->size);
        $this->assertSame('permanent', $file->status);
        $this->assertSame(5, $file->ownerId);
        $this->assertSame(1700000000, $file->createdTime);
    }

    public function testRetainsOriginalNameAsMetadata(): void
    {
        // The sanitized disk filename destroys Indigenous orthography; the
        // client's original name survives as a separate metadata field.
        $file = new File(
            uri: 'public://Ozhibii_igan_ab12cd34.png',
            filename: 'Ozhibii_igan_ab12cd34.png',
            originalName: 'Ozhibiiʼigan ᐊᓂᔑᓈᐯᒧᐎᓐ.png',
        );

        $this->assertSame('Ozhibiiʼigan ᐊᓂᔑᓈᐯᒧᐎᓐ.png', $file->originalName);
    }

    public function testOriginalNameDefaultsToNull(): void
    {
        $file = new File(uri: 'public://test.txt', filename: 'test.txt');

        $this->assertNull($file->originalName);
    }

    public function testConstructorDefaults(): void
    {
        $file = new File(
            uri: 'public://test.txt',
            filename: 'test.txt',
        );

        $this->assertSame('application/octet-stream', $file->mimeType);
        $this->assertSame(0, $file->size);
        $this->assertSame('permanent', $file->status);
        $this->assertNull($file->ownerId);
        $this->assertNull($file->createdTime);
    }

    public function testGetExtension(): void
    {
        $file = new File(uri: 'public://doc.pdf', filename: 'doc.pdf');
        $this->assertSame('pdf', $file->getExtension());
    }

    public function testGetExtensionJpeg(): void
    {
        $file = new File(uri: 'public://photo.jpeg', filename: 'photo.jpeg');
        $this->assertSame('jpeg', $file->getExtension());
    }

    public function testGetExtensionMultipleDots(): void
    {
        $file = new File(uri: 'public://archive.tar.gz', filename: 'archive.tar.gz');
        $this->assertSame('gz', $file->getExtension());
    }

    public function testGetExtensionNoExtension(): void
    {
        $file = new File(uri: 'public://Makefile', filename: 'Makefile');
        $this->assertSame('', $file->getExtension());
    }

    public function testGetExtensionHiddenFile(): void
    {
        $file = new File(uri: 'public://.gitignore', filename: '.gitignore');
        // pathinfo returns 'gitignore' for dotfiles
        $this->assertSame('gitignore', $file->getExtension());
    }

    public function testIsImageJpeg(): void
    {
        $file = new File(
            uri: 'public://photo.jpg',
            filename: 'photo.jpg',
            mimeType: 'image/jpeg',
        );

        $this->assertTrue($file->isImage());
    }

    public function testIsImagePng(): void
    {
        $file = new File(
            uri: 'public://icon.png',
            filename: 'icon.png',
            mimeType: 'image/png',
        );

        $this->assertTrue($file->isImage());
    }

    public function testIsImageGif(): void
    {
        $file = new File(
            uri: 'public://animation.gif',
            filename: 'animation.gif',
            mimeType: 'image/gif',
        );

        $this->assertTrue($file->isImage());
    }

    public function testIsImageWebp(): void
    {
        $file = new File(
            uri: 'public://photo.webp',
            filename: 'photo.webp',
            mimeType: 'image/webp',
        );

        $this->assertTrue($file->isImage());
    }

    public function testIsImageSvg(): void
    {
        $file = new File(
            uri: 'public://logo.svg',
            filename: 'logo.svg',
            mimeType: 'image/svg+xml',
        );

        $this->assertTrue($file->isImage());
    }

    public function testIsNotImagePdf(): void
    {
        $file = new File(
            uri: 'public://doc.pdf',
            filename: 'doc.pdf',
            mimeType: 'application/pdf',
        );

        $this->assertFalse($file->isImage());
    }

    public function testIsNotImageText(): void
    {
        $file = new File(
            uri: 'public://readme.txt',
            filename: 'readme.txt',
            mimeType: 'text/plain',
        );

        $this->assertFalse($file->isImage());
    }

    public function testIsNotImageVideo(): void
    {
        $file = new File(
            uri: 'public://video.mp4',
            filename: 'video.mp4',
            mimeType: 'video/mp4',
        );

        $this->assertFalse($file->isImage());
    }

    public function testIsNotImageDefaultMimeType(): void
    {
        $file = new File(
            uri: 'public://unknown',
            filename: 'unknown',
        );

        $this->assertFalse($file->isImage());
    }

    public function testTemporaryStatus(): void
    {
        $file = new File(
            uri: 'temporary://upload_abc123',
            filename: 'upload.jpg',
            status: 'temporary',
        );

        $this->assertSame('temporary', $file->status);
    }

    public function testReadonlyProperties(): void
    {
        $file = new File(uri: 'public://test.txt', filename: 'test.txt');

        $reflection = new \ReflectionClass($file);
        $this->assertTrue($reflection->isReadOnly());
    }
}
