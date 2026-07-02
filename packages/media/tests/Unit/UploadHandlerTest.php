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
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            @unlink($tempFile);
        }
        $this->tempFiles = [];
    }

    private function makeTempFile(string $contents): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'waaseyaa_upload_');
        file_put_contents($tempFile, $contents);
        $this->tempFiles[] = $tempFile;

        return $tempFile;
    }

    private function jpegBytes(): string
    {
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\x00", 64) . "\xFF\xD9";
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    #[Test]
    public function validates_successful_upload_using_sniffed_mime(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        // The client-declared type is hostile — only the finfo sniff counts.
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'tmp_name' => $this->makeTempFile($this->jpegBytes()),
            'type' => 'application/x-evil',
        ];
        $this->assertSame([], $handler->validate($file));
    }

    #[Test]
    public function rejects_client_declared_type_without_verifiable_file(): void
    {
        // Historically this input validated via the client-declared 'type'
        // fallback (fail-open). Without a sniffable file the handler must
        // fail CLOSED — the client type is attacker-controlled.
        $handler = new UploadHandler(sys_get_temp_dir());
        $file = ['error' => UPLOAD_ERR_OK, 'size' => 1024, 'type' => 'image/jpeg'];
        $errors = $handler->validate($file);
        $this->assertContains('File type could not be verified.', $errors);
    }

    #[Test]
    public function fails_closed_when_mime_detection_returns_null(): void
    {
        // Simulates finfo being unavailable or unable to identify the file.
        $handler = new UploadHandler(
            sys_get_temp_dir(),
            mimeDetector: static fn(string $filePath): ?string => null,
        );
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'tmp_name' => $this->makeTempFile($this->jpegBytes()),
            'type' => 'image/jpeg',
        ];
        $errors = $handler->validate($file);
        $this->assertContains('File type could not be verified.', $errors);
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
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => 6_000_000,
            'tmp_name' => $this->makeTempFile($this->jpegBytes()),
            'type' => 'image/jpeg',
        ];
        $errors = $handler->validate($file);
        $this->assertNotEmpty($errors);
    }

    #[Test]
    public function rejects_disallowed_mime_type(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'tmp_name' => $this->makeTempFile($this->pdfBytes()),
            'type' => 'application/pdf',
        ];
        $errors = $handler->validate($file);
        $this->assertContains('File type not allowed.', $errors);
    }

    #[Test]
    public function custom_allowed_types(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir(), allowedMimeTypes: ['application/pdf']);
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'tmp_name' => $this->makeTempFile($this->pdfBytes()),
            'type' => 'application/pdf',
        ];
        $this->assertSame([], $handler->validate($file));
    }

    #[Test]
    public function allows_wildcard_allowed_types(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir(), allowedMimeTypes: ['image/*']);
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'tmp_name' => $this->makeTempFile($this->jpegBytes()),
            'type' => 'image/jpeg',
        ];
        $this->assertSame([], $handler->validate($file));
    }

    #[Test]
    public function custom_max_size(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir(), maxSizeBytes: 500);
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'tmp_name' => $this->makeTempFile($this->jpegBytes()),
            'type' => 'image/jpeg',
        ];
        $errors = $handler->validate($file);
        $this->assertNotEmpty($errors);
    }

    #[Test]
    public function detect_mime_type_sniffs_file_contents(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $this->assertSame('image/jpeg', $handler->detectMimeType($this->makeTempFile($this->jpegBytes())));
        $this->assertSame('application/pdf', $handler->detectMimeType($this->makeTempFile($this->pdfBytes())));
    }

    #[Test]
    public function detect_mime_type_returns_null_for_missing_file(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $this->assertNull($handler->detectMimeType('/nonexistent/path/file.bin'));
    }

    #[Test]
    public function mime_type_matches_exact_and_wildcard(): void
    {
        $this->assertTrue(UploadHandler::mimeTypeMatches('image/png', ['image/png', 'image/jpeg']));
        $this->assertTrue(UploadHandler::mimeTypeMatches('image/webp', ['image/*']));
        $this->assertTrue(UploadHandler::mimeTypeMatches('application/pdf', ['image/*', 'application/pdf']));
        $this->assertFalse(UploadHandler::mimeTypeMatches('text/html', ['image/*', 'application/pdf']));
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

    #[Test]
    public function rejects_path_traversal_in_move_upload(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path traversal');
        $handler->moveUpload(
            ['error' => UPLOAD_ERR_OK, 'size' => 100, 'type' => 'image/jpeg', 'tmp_name' => '/tmp/x', 'name' => 'a.jpg'],
            '../../etc',
        );
    }

    #[Test]
    public function rejects_path_traversal_in_delete_directory(): void
    {
        $handler = new UploadHandler(sys_get_temp_dir());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path traversal');
        $handler->deleteDirectory('../../../etc');
    }
}
