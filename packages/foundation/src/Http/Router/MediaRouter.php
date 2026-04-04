<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Media\LocalFileRepository;

final class MediaRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $config,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'media.upload';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);

        return $this->handleMediaUpload($request, $ctx);
    }

    private function handleMediaUpload(
        Request $httpRequest,
        WaaseyaaContext $ctx,
    ): Response {
        $contentType = strtolower((string) $httpRequest->headers->get('Content-Type', ''));
        if (!str_starts_with($contentType, 'multipart/form-data')) {
            return $this->jsonApiResponse(415, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '415', 'title' => 'Unsupported Media Type', 'detail' => 'Expected multipart/form-data upload.']],
            ]);
        }

        $uploadedFile = $httpRequest->files->get('file');
        if (!$uploadedFile instanceof UploadedFile) {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Missing "file" in upload.']],
            ]);
        }

        if (!$uploadedFile->isValid()) {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => sprintf('Upload error: %s', $uploadedFile->getErrorMessage())]],
            ]);
        }

        $maxBytes = $this->resolveUploadMaxBytes();
        if ($uploadedFile->getSize() > $maxBytes) {
            return $this->jsonApiResponse(413, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '413', 'title' => 'Payload Too Large', 'detail' => sprintf('File exceeds maximum upload size of %d bytes.', $maxBytes)]],
            ]);
        }

        $mimeType = $uploadedFile->getMimeType() ?? $uploadedFile->getClientMimeType();
        $allowedMimeTypes = $this->resolveAllowedUploadMimeTypes();
        if (!$this->isAllowedMimeType($mimeType, $allowedMimeTypes)) {
            return $this->jsonApiResponse(415, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '415', 'title' => 'Unsupported Media Type', 'detail' => sprintf('MIME type "%s" is not allowed.', $mimeType)]],
            ]);
        }

        $safeName = $this->sanitizeUploadFilename($uploadedFile->getClientOriginalName());
        $filesRoot = $this->resolveFilesRootDir();

        if (!is_dir($filesRoot)) {
            mkdir($filesRoot, 0o755, true);
        }

        $uri = 'public://' . $safeName;
        $destPath = $filesRoot . '/' . $safeName;
        $uploadedFile->move(dirname($destPath), basename($destPath));

        $file = new \Waaseyaa\Media\File(
            uri: $uri,
            filename: $safeName,
            mimeType: $mimeType,
            size: (int) filesize($destPath),
            ownerId: $ctx->account->isAuthenticated() ? (int) $ctx->account->id() : null,
            createdTime: time(),
        );

        $repo = new LocalFileRepository($filesRoot);
        $repo->save($file);

        $fileUrl = $this->buildPublicFileUrl($file->uri);
        $fileData = [
            'id' => $safeName,
            'type' => 'file',
            'attributes' => [
                'filename' => $file->filename,
                'uri' => $file->uri,
                'url' => $fileUrl,
                'mime_type' => $file->mimeType,
                'size' => $file->size,
                'created' => $file->createdTime,
            ],
        ];

        return $this->jsonApiResponse(201, ['jsonapi' => ['version' => '1.1'], 'data' => $fileData]);
    }

    public function resolveFilesRootDir(): string
    {
        $configured = $this->config['files_root'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->projectRoot . '/storage/files';
    }

    public function resolveUploadMaxBytes(): int
    {
        $configured = $this->config['upload_max_bytes'] ?? null;
        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return 10 * 1024 * 1024;
    }

    /**
     * @return list<string>
     */
    public function resolveAllowedUploadMimeTypes(): array
    {
        $configured = $this->config['upload_allowed_mime_types'] ?? null;
        if (is_array($configured) && $configured !== []) {
            $values = [];
            foreach ($configured as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $values[] = trim($value);
                }
            }
            if ($values !== []) {
                return $values;
            }
        }

        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'application/pdf',
            'text/plain',
            'application/octet-stream',
        ];
    }

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function isAllowedMimeType(string $mimeType, array $allowedMimeTypes): bool
    {
        foreach ($allowedMimeTypes as $allowed) {
            if ($allowed === $mimeType) {
                return true;
            }
            if (str_ends_with($allowed, '/*')) {
                $prefix = substr($allowed, 0, -1);
                if (str_starts_with($mimeType, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function sanitizeUploadFilename(string $name): string
    {
        $basename = basename($name);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);
        if (!is_string($clean) || $clean === '' || $clean === '.' || $clean === '..') {
            return 'upload.bin';
        }

        return $clean;
    }

    public function buildPublicFileUrl(string $uri): string
    {
        $prefix = 'public://';
        if (!str_starts_with($uri, $prefix)) {
            return '/files/' . ltrim($uri, '/');
        }

        $path = substr($uri, strlen($prefix));
        if (!is_string($path)) {
            return '/files/';
        }

        return '/files/' . ltrim($path, '/');
    }
}
