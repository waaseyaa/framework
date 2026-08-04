<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Content;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\Media\UploadHandler;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;

/**
 * Media-entity-backed {@see AssetStoreInterface} for editorial images.
 *
 * Validation reuses the media package's fail-closed contract verbatim
 * ({@see UploadHandler::validate()}: content sniffing via ext-fileinfo, the
 * client-declared type is never consulted, size caps, approved types only).
 * Accepted bytes are stored content-addressed (`<sha256>.<ext>` — natural
 * dedup, no name injection) under one uploads directory, and a `media` entity
 * row records the asset (repository save → revisioned, audited, lifecycle
 * events). Only DECLARED media fields are written — content operations must
 * never widen the field-access inventory.
 *
 * Dimensions/MIME/size are derived from the stored bytes on read rather than
 * persisted as extra fields, for the same inventory reason.
 *
 * @api
 */
final readonly class MediaAssetStore implements AssetStoreInterface
{
    private const array APPROVED_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    private const array EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * @param EntityRepository $mediaRepository The `media` entity repository.
     * @param string $uploadsDir Absolute directory for stored assets (created if missing).
     * @param string $publicUrlBase URL prefix the app serves `$uploadsDir` under (e.g. '/media/uploads').
     * @param EntityAccessHandler $accessHandler Enforces bundle-scoped create
     *        access before any bytes or entity rows are written.
     * @param string $bundle Media bundle recorded on rows (approved type family).
     * @param int $maxSizeBytes Upload cap.
     */
    public function __construct(
        private EntityRepository $mediaRepository,
        private string $uploadsDir,
        private string $publicUrlBase,
        private EntityAccessHandler $accessHandler,
        private string $bundle = 'image',
        private int $maxSizeBytes = 5_242_880,
    ) {}

    /** @return array{asset_id: string, url: string, mime: string, width: int, height: int, size: int} */
    public function upload(string $filename, string $bytes, AuthorizationPrincipalInterface $actor): array
    {
        if (!$this->accessHandler->checkCreateAccess('media', $this->bundle, $actor)->isAllowed()) {
            throw new ContentAuthorizationException('Media create access denied.');
        }

        if ($bytes === '') {
            throw new AssetRejectedException(['Empty upload.']);
        }
        if (\strlen($bytes) > $this->maxSizeBytes) {
            throw new AssetRejectedException([sprintf('File must be under %dMB.', (int) round($this->maxSizeBytes / 1_048_576))]);
        }

        if (!is_dir($this->uploadsDir) && !mkdir($this->uploadsDir, 0o755, true) && !is_dir($this->uploadsDir)) {
            throw new \RuntimeException('Uploads directory could not be created.');
        }

        // Stage to a temp file so the media package's fail-closed validation
        // runs against real bytes on disk, exactly as for an HTTP upload.
        $tmp = tempnam($this->uploadsDir, '.staging-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not stage upload.');
        }
        try {
            file_put_contents($tmp, $bytes);
            $handler = new UploadHandler($this->uploadsDir, self::APPROVED_TYPES, $this->maxSizeBytes);
            $errors = $handler->validate([
                'error' => UPLOAD_ERR_OK,
                'size' => \strlen($bytes),
                'tmp_name' => $tmp,
                'name' => $filename,
            ]);
            if ($errors !== []) {
                throw new AssetRejectedException(array_values($errors));
            }

            $mime = (string) $handler->detectMimeType($tmp);
            $dimensions = @getimagesize($tmp);
            if ($dimensions === false) {
                throw new AssetRejectedException(['Image could not be decoded.']);
            }

            $sha = hash('sha256', $bytes);
            $stored = $this->uploadsDir . '/' . $sha . '.' . self::EXTENSIONS[$mime];
            if (!is_file($stored) && !rename($tmp, $stored)) {
                throw new \RuntimeException('Could not persist upload.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        // Catalog row (revisioned, audited, lifecycle events). Reads never
        // come back through the entity — sealed-field classifications must
        // not gate asset metadata — so the asset id is the content hash and
        // get() resolves from the store's own filesystem.
        $uid = $actor->id();
        $owner = \is_int($uid) || ctype_digit($uid) ? (int) $uid : null;
        $values = [
            'name' => $this->safeDisplayName($filename),
            'bundle' => $this->bundle,
            'source_uri' => $this->publicUrl($sha, $mime),
            'status' => 1,
        ];
        if ($owner !== null) {
            // Core media is not revisionable, so SaveContext alone cannot
            // preserve authorship. The owner field is its durable attribution.
            $values['uid'] = $owner;
        }
        $entity = $this->mediaRepository->create($values);
        $context = SaveContext::default();
        if ($owner !== null) {
            $context = $context->withActorUid($owner);
        }
        $this->mediaRepository->save($entity, false, $context);

        return [
            'asset_id' => $sha,
            'url' => $this->publicUrl($sha, $mime),
            'mime' => $mime,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'size' => \strlen($bytes),
        ];
    }

    /** @return ?array{asset_id: string, url: string, mime: string, width: int, height: int, size: int} */
    public function get(string $assetId, AuthorizationPrincipalInterface $actor): ?array
    {
        // Content-addressed ids only — never a filesystem path.
        if (preg_match('/^[a-f0-9]{64}$/', $assetId) !== 1) {
            return null;
        }
        foreach (self::EXTENSIONS as $mime => $ext) {
            $stored = $this->uploadsDir . '/' . $assetId . '.' . $ext;
            if (!is_file($stored)) {
                continue;
            }
            $dimensions = @getimagesize($stored);

            return [
                'asset_id' => $assetId,
                'url' => rtrim($this->publicUrlBase, '/') . '/' . $assetId . '.' . $ext,
                'mime' => $mime,
                'width' => $dimensions === false ? 0 : $dimensions[0],
                'height' => $dimensions === false ? 0 : $dimensions[1],
                'size' => (int) filesize($stored),
            ];
        }

        return null;
    }

    private function publicUrl(string $sha, string $mime): string
    {
        return rtrim($this->publicUrlBase, '/') . '/' . $sha . '.' . self::EXTENSIONS[$mime];
    }

    private function safeDisplayName(string $filename): string
    {
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '', basename($filename)) ?? '';

        return $name !== '' ? mb_substr($name, 0, 120) : 'upload';
    }
}
