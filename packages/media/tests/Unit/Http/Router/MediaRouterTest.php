<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Media\Http\Router\MediaRouter;
use Waaseyaa\Media\MediaAccessPolicy;

#[CoversClass(MediaRouter::class)]
final class MediaRouterTest extends TestCase
{
    private function createRouter(
        string $projectRoot = '/tmp/test-project',
        array $config = [],
    ): MediaRouter {
        return new MediaRouter(
            $projectRoot,
            $config,
            accessHandler: new EntityAccessHandler([new MediaAccessPolicy()]),
            bundleExists: static fn(string $bundle): bool => in_array($bundle, ['image', 'private_document'], true),
        );
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

    private function makeUploadRequest(
        string $tmpFile,
        string $clientName,
        string $clientType,
        mixed $bundle = 'image',
        ?AccountInterface $account = null,
    ): Request {
        // Plain UploadedFile in test mode — the router must sniff the real
        // file contents (ext-fileinfo), never Symfony's getMimeType() (which
        // needs symfony/mime, not installed) nor the client-declared type.
        $uploadedFile = new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $tmpFile,
            $clientName,
            $clientType,
            null,
            true,
        );

        $parameters = $bundle === null ? [] : ['bundle' => $bundle];
        $request = Request::create('/api/media/upload', 'POST', $parameters, server: ['CONTENT_TYPE' => 'multipart/form-data']);
        $request->attributes->set('_controller', 'media.upload');
        $request->attributes->set('_account', $account ?? new AuthorizationPrincipal(1, true, ['administrator'], [], 'test'));
        $request->attributes->set('_broadcast_storage', new BroadcastStorage(DBALDatabase::createSqlite()));
        $request->files->set('file', $uploadedFile);

        return $request;
    }

    private function accountWithPermissions(array $permissions): AccountInterface
    {
        return new AuthorizationPrincipal(7, true, [], $permissions, 'test');
    }

    private function makeUploadWorkspace(): array
    {
        $tmpDir = sys_get_temp_dir() . '/waaseyaa_media_test_' . uniqid();
        mkdir($tmpDir, 0o755, true);
        $filesRoot = $tmpDir . '/files';
        mkdir($filesRoot, 0o755, true);

        return [$tmpDir, $filesRoot];
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    private function svgBytes(): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1\" height=\"1\"><rect/></svg>\n";
    }

    #[Test]
    public function get_exposes_only_safe_upload_constraints(): void
    {
        $privateRoot = '/srv/private/customer-storage';
        $router = $this->createRouter(config: [
            'files_root' => $privateRoot,
            'upload_max_bytes' => 2_500_000,
            'upload_allowed_mime_types' => ['image/png', 'application/pdf'],
        ]);
        $request = Request::create('/api/media/upload', 'GET');
        $request->attributes->set('_controller', 'media.upload');

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2_500_000, $decoded['meta']['constraints']['max_bytes']);
        self::assertSame(['image/png', 'application/pdf'], $decoded['meta']['constraints']['allowed_mime_types']);
        self::assertStringNotContainsString($privateRoot, $response->getContent());
        self::assertArrayNotHasKey('files_root', $decoded['meta']['constraints']);
    }

    #[Test]
    public function upload_requires_a_canonical_bundle_before_persisting_bytes(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();
        $tmpFile = $tmpDir . '/source.png';
        file_put_contents($tmpFile, $this->pngBytes());
        $router = $this->createRouter(config: ['files_root' => $filesRoot]);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'photo.png', 'image/png', null));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], array_values(array_diff(scandir($filesRoot) ?: [], ['.', '..'])));
        self::assertStringNotContainsString($filesRoot, $response->getContent());
        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_rejects_malformed_bundle_before_persisting_bytes(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();
        $tmpFile = $tmpDir . '/source.png';
        file_put_contents($tmpFile, $this->pngBytes());
        $router = $this->createRouter(config: ['files_root' => $filesRoot]);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'photo.png', 'image/png', '../secret'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], array_values(array_diff(scandir($filesRoot) ?: [], ['.', '..'])));
        self::assertStringNotContainsString('../secret', $response->getContent());
        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_rejects_non_scalar_bundle_without_throwing_or_persisting_bytes(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();
        $tmpFile = $tmpDir . '/source.png';
        file_put_contents($tmpFile, $this->pngBytes());
        $router = $this->createRouter(config: ['files_root' => $filesRoot]);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'photo.png', 'image/png', ['image']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], array_values(array_diff(scandir($filesRoot) ?: [], ['.', '..'])));
        self::assertStringNotContainsString($filesRoot, $response->getContent());
        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_enforces_bundle_create_access_before_persisting_bytes(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();
        $tmpFile = $tmpDir . '/source.png';
        file_put_contents($tmpFile, $this->pngBytes());
        $router = $this->createRouter(config: ['files_root' => $filesRoot]);
        $viewer = $this->accountWithPermissions(['access media']);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'photo.png', 'image/png', 'private_document', $viewer));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], array_values(array_diff(scandir($filesRoot) ?: [], ['.', '..'])));
        self::assertStringNotContainsString('private_document', $response->getContent());
        self::assertStringNotContainsString('create private_document media', $response->getContent());
        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_rejects_an_unregistered_bundle_even_for_a_media_administrator(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();
        $tmpFile = $tmpDir . '/source.png';
        file_put_contents($tmpFile, $this->pngBytes());
        $router = $this->createRouter(config: ['files_root' => $filesRoot]);
        $administrator = $this->accountWithPermissions(['access media', 'administer media']);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'photo.png', 'image/png', 'unregistered_bundle', $administrator));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], array_values(array_diff(scandir($filesRoot) ?: [], ['.', '..'])));
        self::assertStringNotContainsString('unregistered_bundle', $response->getContent());
        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_allows_only_the_authorized_canonical_bundle_and_returns_the_canonical_shape(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();
        $tmpFile = $tmpDir . '/source.png';
        file_put_contents($tmpFile, $this->pngBytes());
        $router = $this->createRouter(config: ['files_root' => $filesRoot]);
        $creator = $this->accountWithPermissions(['access media', 'create private_document media']);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'photo.png', 'image/png', 'private_document', $creator));

        self::assertSame(201, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('file', $decoded['data']['type']);
        self::assertStringStartsWith('public://', $decoded['data']['attributes']['uri']);
        self::assertStringStartsWith('/files/', $decoded['data']['attributes']['url']);
        self::assertSame('image/png', $decoded['data']['attributes']['mime_type']);
        self::assertArrayNotHasKey('path', $decoded['data']['attributes']);
        self::assertStringNotContainsString($filesRoot, $response->getContent());
        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_stores_files_with_distinct_random_suffixed_names(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();

        $router = $this->createRouter(config: ['files_root' => $filesRoot]);

        $storedNames = [];

        for ($i = 1; $i <= 2; $i++) {
            // Each call needs a fresh temp file because move() consumes it.
            $tmpFile = $tmpDir . '/source_' . $i . '.png';
            file_put_contents($tmpFile, $this->pngBytes());

            // Client-supplied name is always the same "logo.png" — the collision trigger.
            $response = $router->handle($this->makeUploadRequest($tmpFile, 'logo.png', 'image/png'));

            self::assertSame(201, $response->getStatusCode(), 'Upload ' . $i . ' should succeed');

            $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $storedName = $decoded['data']['id'];
            $storedNames[] = $storedName;

            // The recorded MIME must be the sniffed one.
            self::assertSame('image/png', $decoded['data']['attributes']['mime_type']);

            // Each stored name must carry a random suffix: logo_<8hex>.png
            self::assertMatchesRegularExpression(
                '/^logo_[0-9a-f]{8}\.png$/',
                $storedName,
                'Upload ' . $i . ': stored name must be logo_<8hex>.png, got: ' . $storedName,
            );
        }

        // The two uploads of the same "logo.png" must produce DISTINCT stored names.
        self::assertNotSame(
            $storedNames[0],
            $storedNames[1],
            'Two uploads of the same client filename must produce distinct stored names (no clobber).',
        );

        // Cleanup — use a recursive helper to handle any sub-directories created by the repo.
        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_rejects_spoofed_client_mime_type(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();

        // Plain-text contents; the client LIES that this is image/png.
        $tmpFile = $tmpDir . '/spoof.png';
        file_put_contents($tmpFile, 'just plain text pretending to be an image');

        $router = $this->createRouter(config: [
            'files_root' => $filesRoot,
            'upload_allowed_mime_types' => ['image/*'],
        ]);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'photo.png', 'image/png'));

        self::assertSame(415, $response->getStatusCode(), 'Sniffed type must win over the client-declared type');
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('text/plain', $decoded['errors'][0]['detail']);

        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_rejects_application_octet_stream_by_default(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();

        $tmpFile = $tmpDir . '/blob.bin';
        file_put_contents($tmpFile, "\x00\x01\x02\x03\xDE\xAD\xBE\xEF\x10\x92\x33\x44" . str_repeat("\xAB\xCD", 32));

        $router = $this->createRouter(config: ['files_root' => $filesRoot]);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'blob.bin', 'application/octet-stream'));

        self::assertSame(415, $response->getStatusCode(), 'octet-stream must not be in the default allowlist');

        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_rejects_svg_by_default(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();

        $tmpFile = $tmpDir . '/image.svg';
        file_put_contents($tmpFile, $this->svgBytes());

        $router = $this->createRouter(config: ['files_root' => $filesRoot]);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'image.svg', 'image/svg+xml'));

        self::assertSame(415, $response->getStatusCode(), 'SVG (script-capable) must not be in the default allowlist');

        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_allows_svg_with_explicit_config_opt_in(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();

        $tmpFile = $tmpDir . '/image.svg';
        file_put_contents($tmpFile, $this->svgBytes());

        $router = $this->createRouter(config: [
            'files_root' => $filesRoot,
            'upload_allowed_mime_types' => ['image/svg+xml'],
        ]);

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'image.svg', 'image/svg+xml'));

        self::assertSame(201, $response->getStatusCode(), 'Explicit config opt-in must re-enable SVG');
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('image/svg+xml', $decoded['data']['attributes']['mime_type']);

        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_fails_closed_when_mime_detection_unavailable(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();

        $tmpFile = $tmpDir . '/photo.png';
        file_put_contents($tmpFile, $this->pngBytes());

        // Simulates finfo being unavailable: detection yields null. The router
        // must REJECT rather than fall back to the client-declared type.
        $uploadHandler = new \Waaseyaa\Media\UploadHandler(
            $filesRoot,
            mimeDetector: static fn(string $filePath): ?string => null,
        );
        $router = new MediaRouter(
            '/tmp/test-project',
            ['files_root' => $filesRoot],
            $uploadHandler,
            new EntityAccessHandler([new MediaAccessPolicy()]),
            static fn(string $bundle): bool => $bundle === 'image',
        );

        $response = $router->handle($this->makeUploadRequest($tmpFile, 'photo.png', 'image/png'));

        self::assertSame(415, $response->getStatusCode(), 'Unverifiable MIME must fail closed');
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('could not be verified', $decoded['errors'][0]['detail']);

        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function upload_retains_non_ascii_original_filename_in_metadata(): void
    {
        [$tmpDir, $filesRoot] = $this->makeUploadWorkspace();

        $tmpFile = $tmpDir . '/source.png';
        file_put_contents($tmpFile, $this->pngBytes());

        // Anishinaabemowin filename: the glottal ʼ (U+02BC) and Canadian
        // syllabics are destroyed by generateSafeFilename() for the DISK name,
        // so the original must be preserved as stored metadata.
        $originalName = 'Ozhibiiʼigan ᐊᓂᔑᓈᐯᒧᐎᓐ.png';

        $router = $this->createRouter(config: ['files_root' => $filesRoot]);
        $response = $router->handle($this->makeUploadRequest($tmpFile, $originalName, 'image/png'));

        self::assertSame(201, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Disk name stays sanitized (ASCII-safe, random-suffixed; the 2-byte
        // glottal ʼ becomes two underscores under the byte-wise sanitizer) …
        $storedName = $decoded['data']['id'];
        self::assertMatchesRegularExpression('/^Ozhibii__igan_[0-9a-f]{8}\.png$/', $storedName);
        self::assertSame($storedName, $decoded['data']['attributes']['filename']);

        // … while the original name survives in the response and the sidecar.
        self::assertSame($originalName, $decoded['data']['attributes']['original_filename']);

        $sidecar = new \Waaseyaa\Media\LocalFileRepository($filesRoot)->load('public://' . $storedName);
        self::assertNotNull($sidecar);
        self::assertSame($originalName, $sidecar->originalName);

        $this->removeDirectory($tmpDir);
    }

    #[Test]
    public function resolve_allowed_upload_mime_types_excludes_svg_and_octet_stream_by_default(): void
    {
        $router = $this->createRouter();
        $types = $router->resolveAllowedUploadMimeTypes();
        self::assertNotContains('image/svg+xml', $types);
        self::assertNotContains('application/octet-stream', $types);
    }

    #[Test]
    public function handle_returns_500_when_file_move_fails(): void
    {
        $tmpDir = sys_get_temp_dir() . '/waaseyaa_media_test_' . uniqid();
        mkdir($tmpDir, 0o755, true);

        // Create a small temp file to simulate an upload (text/plain is in
        // the default allowlist, so validation passes and move() is reached).
        $tmpFile = $tmpDir . '/upload.txt';
        file_put_contents($tmpFile, 'test content');

        $invalidRoot = $tmpDir . '/not-a-directory';
        file_put_contents($invalidRoot, 'regular file, not a directory');

        $router = $this->createRouter(config: ['files_root' => $invalidRoot]);
        $response = $router->handle($this->makeUploadRequest($tmpFile, 'test.txt', 'text/plain'));

        self::assertSame(500, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('500', $decoded['errors'][0]['status']);
        self::assertSame('Failed to store uploaded file.', $decoded['errors'][0]['detail']);

        @unlink($tmpFile);
        @unlink($invalidRoot);
        @rmdir($tmpDir);
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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
