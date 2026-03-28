<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\UploadHandler;

#[CoversClass(UploadHandler::class)]
final class UploadHandlerTest extends TestCase
{
    #[Test]
    public function validates_successful_upload(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $file = ['error' => UPLOAD_ERR_OK, 'size' => 1024, 'type' => 'image/jpeg'];
        $this->assertSame([], $handler->validate($file));
    }

    #[Test]
    public function rejects_upload_error(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $file = ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'type' => ''];
        $errors = $handler->validate($file);
        $this->assertContains('Upload failed.', $errors);
    }

    #[Test]
    public function rejects_oversized_file(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $file = ['error' => UPLOAD_ERR_OK, 'size' => 6_000_000, 'type' => 'image/jpeg'];
        $errors = $handler->validate($file);
        $this->assertNotEmpty($errors);
    }

    #[Test]
    public function rejects_disallowed_mime_type(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $file = ['error' => UPLOAD_ERR_OK, 'size' => 1024, 'type' => 'application/pdf'];
        $errors = $handler->validate($file);
        $this->assertNotEmpty($errors);
    }

    #[Test]
    public function custom_allowed_types(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir(), allowedMimeTypes: ['application/pdf']);
        $file = ['error' => UPLOAD_ERR_OK, 'size' => 1024, 'type' => 'application/pdf'];
        $this->assertSame([], $handler->validate($file));
    }

    #[Test]
    public function custom_max_size(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir(), maxSizeBytes: 500);
        $file = ['error' => UPLOAD_ERR_OK, 'size' => 1024, 'type' => 'image/jpeg'];
        $errors = $handler->validate($file);
        $this->assertNotEmpty($errors);
    }

    #[Test]
    public function generates_safe_filename(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $filename = $handler->generateSafeFilename('My Photo (1).jpeg');
        $this->assertMatchesRegularExpression('/^My_Photo__1_[a-f0-9]{8}\.jpeg$/', $filename);
    }

    #[Test]
    public function generates_fallback_for_empty_name(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $filename = $handler->generateSafeFilename('!!!.png');
        $this->assertMatchesRegularExpression('/^upload_[a-f0-9]{8}\.png$/', $filename);
    }
}
