<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Http\Router;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Http\Router\WaaseyaaContext;
use Waaseyaa\Media\File;
use Waaseyaa\Media\LocalFileRepository;
use Waaseyaa\Media\UploadHandler;

final class MediaRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param array<string, mixed> $config
     * @param ?UploadHandler $uploadHandler Overrides the per-request handler
     *        (testing seam — e.g. to simulate unavailable MIME detection).
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $config,
        private readonly ?UploadHandler $uploadHandler = null,
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

        $filesRoot = $this->resolveFilesRootDir();
        $allowedMimeTypes = $this->resolveAllowedUploadMimeTypes();
        $uploadHandler = $this->uploadHandler ?? new UploadHandler($filesRoot, $allowedMimeTypes);

        // Fail closed: only the content sniff (ext-fileinfo via UploadHandler)
        // counts — the client-declared MIME is attacker-controlled and is
        // never consulted, not even as a fallback.
        $mimeType = $uploadHandler->detectMimeType($uploadedFile->getPathname());
        if ($mimeType === null) {
            return $this->jsonApiResponse(415, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '415', 'title' => 'Unsupported Media Type', 'detail' => 'File type could not be verified.']],
            ]);
        }
        if (!$this->isAllowedMimeType($mimeType, $allowedMimeTypes)) {
            return $this->jsonApiResponse(415, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '415', 'title' => 'Unsupported Media Type', 'detail' => sprintf('MIME type "%s" is not allowed.', $mimeType)]],
            ]);
        }

        $safeName = $uploadHandler->generateSafeFilename($uploadedFile->getClientOriginalName());

        if (!is_dir($filesRoot) && !@mkdir($filesRoot, 0o755, true) && !is_dir($filesRoot)) {
            return $this->uploadStorageFailureResponse();
        }
        if (!is_dir($filesRoot)) {
            return $this->uploadStorageFailureResponse();
        }

        $uri = 'public://' . $safeName;
        $destPath = $filesRoot . '/' . $safeName;

        try {
            $uploadedFile->move(dirname($destPath), basename($destPath));
        } catch (\Throwable $e) {
            return $this->uploadStorageFailureResponse();
        }

        $file = new File(
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

    private function uploadStorageFailureResponse(): Response
    {
        return $this->jsonApiResponse(500, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Failed to store uploaded file.']],
        ]);
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

        // Hardened defaults: no image/svg+xml (script-capable, served from
        // /files/ with no attachment/nosniff headers) and no
        // application/octet-stream (finfo's answer for ANY unrecognized
        // binary — allowlisting it allowlists arbitrary content). Sites can
        // opt back in explicitly via 'upload_allowed_mime_types'.
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
        ];
    }

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function isAllowedMimeType(string $mimeType, array $allowedMimeTypes): bool
    {
        return UploadHandler::mimeTypeMatches($mimeType, $allowedMimeTypes);
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
