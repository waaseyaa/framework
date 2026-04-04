<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\MediaRouter;

#[CoversClass(MediaRouter::class)]
final class MediaRouterTest extends TestCase
{
    private function createRouter(
        string $projectRoot = '/tmp/test-project',
        array $config = [],
    ): MediaRouter {
        $etm = new EntityTypeManager(new EventDispatcher());
        return new MediaRouter($projectRoot, $config, $etm);
    }

    #[Test]
    public function supports_media_upload(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/media/upload', 'POST');
        $request->attributes->set('_controller', 'media.upload');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function resolve_files_root_dir_defaults_to_storage_files(): void
    {
        $router = $this->createRouter(projectRoot: '/my/project');
        self::assertSame('/my/project/storage/files', $router->resolveFilesRootDir());
    }

    #[Test]
    public function resolve_files_root_dir_uses_configured_path(): void
    {
        $router = $this->createRouter(config: ['files_root' => '/custom/path']);
        self::assertSame('/custom/path', $router->resolveFilesRootDir());
    }

    #[Test]
    public function resolve_upload_max_bytes_defaults_to_ten_megabytes(): void
    {
        $router = $this->createRouter();
        self::assertSame(10 * 1024 * 1024, $router->resolveUploadMaxBytes());
    }

    #[Test]
    public function resolve_upload_max_bytes_uses_configured_value(): void
    {
        $router = $this->createRouter(config: ['upload_max_bytes' => 5_000_000]);
        self::assertSame(5_000_000, $router->resolveUploadMaxBytes());
    }

    #[Test]
    public function resolve_allowed_upload_mime_types_has_sensible_defaults(): void
    {
        $router = $this->createRouter();
        $types = $router->resolveAllowedUploadMimeTypes();
        self::assertContains('image/jpeg', $types);
        self::assertContains('image/png', $types);
        self::assertContains('application/pdf', $types);
    }

    #[Test]
    public function resolve_allowed_upload_mime_types_uses_configured_list(): void
    {
        $router = $this->createRouter(config: ['upload_allowed_mime_types' => ['text/csv']]);
        self::assertSame(['text/csv'], $router->resolveAllowedUploadMimeTypes());
    }

    #[Test]
    public function is_allowed_mime_type_matches_exact(): void
    {
        $router = $this->createRouter();
        self::assertTrue($router->isAllowedMimeType('image/png', ['image/png', 'image/jpeg']));
    }

    #[Test]
    public function is_allowed_mime_type_supports_wildcard(): void
    {
        $router = $this->createRouter();
        self::assertTrue($router->isAllowedMimeType('image/webp', ['image/*']));
    }

    #[Test]
    public function is_allowed_mime_type_supports_mixed_list(): void
    {
        $router = $this->createRouter();
        self::assertTrue($router->isAllowedMimeType('application/pdf', ['image/*', 'application/pdf']));
        self::assertFalse($router->isAllowedMimeType('text/html', ['image/*', 'application/pdf']));
    }

    #[Test]
    public function sanitize_upload_filename_replaces_special_characters(): void
    {
        $router = $this->createRouter();
        self::assertSame('hello_world.jpg', $router->sanitizeUploadFilename('hello world.jpg'));
    }

    #[Test]
    public function sanitize_upload_filename_returns_fallback_for_dangerous_names(): void
    {
        $router = $this->createRouter();
        self::assertSame('upload.bin', $router->sanitizeUploadFilename('..'));
    }

    #[Test]
    public function build_public_file_url_from_public_uri(): void
    {
        $router = $this->createRouter();
        self::assertSame('/files/images/photo.jpg', $router->buildPublicFileUrl('public://images/photo.jpg'));
    }

    #[Test]
    public function build_public_file_url_from_relative_path(): void
    {
        $router = $this->createRouter();
        self::assertSame('/files/uploads/doc.pdf', $router->buildPublicFileUrl('uploads/doc.pdf'));
    }
}
