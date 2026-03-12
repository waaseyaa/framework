<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Cache\CacheConfigResolver;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\DiscoveryApiHandler;
use Waaseyaa\SSR\SsrPageHandler;

#[CoversClass(ControllerDispatcher::class)]
final class ControllerDispatcherTest extends TestCase
{
    private function createDispatcher(
        string $projectRoot = '/tmp/test-project',
        array $config = [],
    ): ControllerDispatcher {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $database = PdoDatabase::createSqlite();
        $discoveryHandler = new DiscoveryApiHandler($entityTypeManager, $database);
        $cacheConfigResolver = new CacheConfigResolver($config);
        $ssrPageHandler = new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $database,
            renderCache: null,
            cacheConfigResolver: $cacheConfigResolver,
            discoveryHandler: $discoveryHandler,
            projectRoot: $projectRoot,
            config: $config,
        );

        return new ControllerDispatcher(
            entityTypeManager: $entityTypeManager,
            database: $database,
            accessHandler: new EntityAccessHandler(),
            lifecycleManager: new EntityTypeLifecycleManager($projectRoot),
            discoveryHandler: $discoveryHandler,
            ssrPageHandler: $ssrPageHandler,
            mcpReadCache: null,
            projectRoot: $projectRoot,
            config: $config,
        );
    }

    #[Test]
    public function dispatch_method_has_never_return_type(): void
    {
        $ref = new \ReflectionMethod(ControllerDispatcher::class, 'dispatch');
        $this->assertSame('never', $ref->getReturnType()?->getName());
    }

    #[Test]
    public function is_allowed_mime_type_matches_exact(): void
    {
        $dispatcher = $this->createDispatcher();
        $this->assertTrue($dispatcher->isAllowedMimeType('image/jpeg', ['image/jpeg']));
        $this->assertFalse($dispatcher->isAllowedMimeType('image/png', ['image/jpeg']));
    }

    #[Test]
    public function is_allowed_mime_type_supports_wildcard(): void
    {
        $dispatcher = $this->createDispatcher();
        $this->assertTrue($dispatcher->isAllowedMimeType('image/jpeg', ['image/*']));
        $this->assertTrue($dispatcher->isAllowedMimeType('image/png', ['image/*']));
        $this->assertFalse($dispatcher->isAllowedMimeType('text/html', ['image/*']));
    }

    #[Test]
    public function is_allowed_mime_type_supports_mixed_list(): void
    {
        $dispatcher = $this->createDispatcher();
        $this->assertTrue($dispatcher->isAllowedMimeType('application/pdf', ['image/*', 'application/pdf']));
        $this->assertFalse($dispatcher->isAllowedMimeType('text/html', ['image/*', 'application/pdf']));
    }

    #[Test]
    public function resolve_files_root_dir_defaults_to_storage_files(): void
    {
        $dispatcher = $this->createDispatcher(projectRoot: '/var/www/myapp');
        $this->assertSame('/var/www/myapp/storage/files', $dispatcher->resolveFilesRootDir());
    }

    #[Test]
    public function resolve_files_root_dir_uses_configured_path(): void
    {
        $dispatcher = $this->createDispatcher(
            projectRoot: '/var/www/myapp',
            config: ['files_dir' => '/mnt/uploads'],
        );
        $this->assertSame('/mnt/uploads', $dispatcher->resolveFilesRootDir());
    }

    #[Test]
    public function resolve_upload_max_bytes_defaults_to_ten_megabytes(): void
    {
        $dispatcher = $this->createDispatcher();
        $this->assertSame(10 * 1024 * 1024, $dispatcher->resolveUploadMaxBytes());
    }

    #[Test]
    public function resolve_upload_max_bytes_uses_configured_value(): void
    {
        $dispatcher = $this->createDispatcher(config: ['upload_max_bytes' => 5_000_000]);
        $this->assertSame(5_000_000, $dispatcher->resolveUploadMaxBytes());
    }

    #[Test]
    public function resolve_allowed_upload_mime_types_has_sensible_defaults(): void
    {
        $dispatcher = $this->createDispatcher();
        $types = $dispatcher->resolveAllowedUploadMimeTypes();

        $this->assertContains('image/jpeg', $types);
        $this->assertContains('image/png', $types);
        $this->assertContains('application/pdf', $types);
    }

    #[Test]
    public function resolve_allowed_upload_mime_types_uses_configured_list(): void
    {
        $dispatcher = $this->createDispatcher(config: [
            'upload_allowed_mime_types' => ['text/csv', 'application/json'],
        ]);
        $types = $dispatcher->resolveAllowedUploadMimeTypes();

        $this->assertSame(['text/csv', 'application/json'], $types);
    }

    #[Test]
    public function sanitize_upload_filename_replaces_special_characters(): void
    {
        $dispatcher = $this->createDispatcher();
        $this->assertSame('my_photo_.jpg', $dispatcher->sanitizeUploadFilename('my photo?.jpg'));
    }

    #[Test]
    public function sanitize_upload_filename_returns_fallback_for_dangerous_names(): void
    {
        $dispatcher = $this->createDispatcher();
        $this->assertSame('upload.bin', $dispatcher->sanitizeUploadFilename('../../'));
        $this->assertSame('upload.bin', $dispatcher->sanitizeUploadFilename('..'));
    }

    #[Test]
    public function build_public_file_url_from_public_uri(): void
    {
        $dispatcher = $this->createDispatcher();
        $this->assertSame('/files/images/photo.jpg', $dispatcher->buildPublicFileUrl('public://images/photo.jpg'));
    }

    #[Test]
    public function build_public_file_url_from_relative_path(): void
    {
        $dispatcher = $this->createDispatcher();
        $this->assertSame('/files/tmp/doc.pdf', $dispatcher->buildPublicFileUrl('tmp/doc.pdf'));
    }
}
