<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Content;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
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
 * ## The catalog row is the authority (#2517)
 *
 * Both halves of this store are bound to the `media` row it writes:
 *
 * - `upload()` records a scheme-qualified `public://` `source_uri`, which is
 *   what {@see \Waaseyaa\Media\Http\Router\MediaDownloadRouter} resolves. A
 *   scheme-less value made the framework write rows its own authorized
 *   download route could not serve, leaving a consumer that needs gated
 *   retrieval with no supported path.
 * - `get()` resolves that row and refuses unless the passed principal may
 *   `view` it. It previously accepted a principal and ignored it, which read
 *   at every call site as though a decision were being made.
 *
 * **Retraction.** The row is the authority, so unpublishing or deleting it
 * withdraws the asset from `get()` and from the authorized download route
 * immediately. The bytes themselves stay on disk: they are content-addressed
 * and may be shared by other rows, and {@see AssetStoreInterface} has no
 * retraction primitive with which to express byte deletion. Bytes present with
 * no catalog row are not an asset and are refused.
 *
 * @api
 */
final readonly class MediaAssetStore implements AssetStoreInterface
{
    private const array APPROVED_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    private const array EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** Relative path of `$uploadsDir` under `$filesRoot`, '' when they are the same directory. */
    private string $publicUriPrefix;

    /**
     * @param EntityRepository $mediaRepository The `media` entity repository.
     * @param string $uploadsDir Absolute directory for stored assets (created if missing).
     *        Must sit inside `$filesRoot`.
     * @param string $publicUrlBase URL prefix the app serves `$uploadsDir` under (e.g. '/media/uploads').
     * @param EntityAccessHandler $accessHandler Enforces bundle-scoped create
     *        access before any bytes or entity rows are written, and entity
     *        `view` access on every read.
     * @param string $filesRoot The media files root the `public://` scheme
     *        resolves against — the same directory
     *        {@see \Waaseyaa\Media\MediaServiceProvider} hands
     *        `MediaDownloadRouter` (config `files_root`, default
     *        `<project>/storage/files`).
     * @param string $bundle Media bundle recorded on rows (approved type family).
     * @param int $maxSizeBytes Upload cap.
     *
     * @throws \InvalidArgumentException When `$uploadsDir` is not inside `$filesRoot`.
     */
    public function __construct(
        private EntityRepository $mediaRepository,
        private string $uploadsDir,
        private string $publicUrlBase,
        private EntityAccessHandler $accessHandler,
        string $filesRoot,
        private string $bundle = 'image',
        private int $maxSizeBytes = 5_242_880,
    ) {
        $this->publicUriPrefix = self::relativeToFilesRoot($uploadsDir, $filesRoot);
    }

    /** @return array{asset_id: string, media_id: string, url: string, mime: string, width: int, height: int, size: int} */
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

        // Catalog row (revisioned, audited, lifecycle events). The asset id
        // stays the content hash — natural dedup, no name injection — but the
        // row is what governs reachability: `source_uri` is scheme-qualified
        // so the authorized download route can resolve it, and get() gates on
        // this row's view access (#2517).
        $uid = $actor->id();
        $owner = \is_int($uid) || ctype_digit($uid) ? (int) $uid : null;
        $values = [
            'name' => $this->safeDisplayName($filename),
            'bundle' => $this->bundle,
            'source_uri' => $this->sourceUri($sha, $mime),
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
            'media_id' => (string) $entity->id(),
            'url' => $this->publicUrl($sha, $mime),
            'mime' => $mime,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'size' => \strlen($bytes),
        ];
    }

    /** @return ?array{asset_id: string, media_id: string, url: string, mime: string, width: int, height: int, size: int} */
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

            // Fail closed on the catalog row, not on the bytes: an asset whose
            // rows were all retracted — or bytes that never had one — is not
            // readable. Content-addressed re-uploads share a source_uri, so
            // the first matching row may be unpublished while a later one is
            // still viewable (#2517).
            $media = $this->viewableCatalogRow($assetId, $mime, $actor);
            if ($media === null) {
                return null;
            }

            $dimensions = @getimagesize($stored);

            return [
                'asset_id' => $assetId,
                'media_id' => (string) $media->id(),
                'url' => rtrim($this->publicUrlBase, '/') . '/' . $assetId . '.' . $ext,
                'mime' => $mime,
                'width' => $dimensions === false ? 0 : $dimensions[0],
                'height' => $dimensions === false ? 0 : $dimensions[1],
                'size' => (int) filesize($stored),
            ];
        }

        return null;
    }

    /**
     * The first `media` row cataloguing this asset that `$actor` may view.
     *
     * Rows written before #2517 carry the scheme-less `source_uri` this store
     * used to record. They are matched too, so an existing catalogue keeps
     * reading — under the access check, which they never had — even though the
     * authorized download route still cannot serve them until re-uploaded.
     *
     * Re-uploading the same bytes writes another row with the same URI (the
     * file is content-addressed). Returning the first match without a view
     * check would hide a later published row behind an unpublished duplicate.
     */
    private function viewableCatalogRow(
        string $sha,
        string $mime,
        AuthorizationPrincipalInterface $actor,
    ): ?EntityInterface {
        $seen = [];
        foreach ([$this->sourceUri($sha, $mime), $this->publicUrl($sha, $mime)] as $uri) {
            foreach ($this->mediaRepository->findBy(['source_uri' => $uri]) as $row) {
                $id = (string) $row->id();
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                if ($this->accessHandler->check($row, 'view', $actor)->isAllowed()) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * The stream-wrapper URI the authorized download route resolves.
     *
     * {@see \Waaseyaa\Media\Http\Router\MediaDownloadRouter::resolvePublicPath()}
     * accepts `public://` only, and joins the remainder onto the media files
     * root — so the relative half must be this store's uploads directory as
     * seen from that root.
     */
    private function sourceUri(string $sha, string $mime): string
    {
        $directory = $this->publicUriPrefix === '' ? '' : $this->publicUriPrefix . '/';

        return 'public://' . $directory . $sha . '.' . self::EXTENSIONS[$mime];
    }

    private function publicUrl(string $sha, string $mime): string
    {
        return rtrim($this->publicUrlBase, '/') . '/' . $sha . '.' . self::EXTENSIONS[$mime];
    }

    /**
     * Resolve `$uploadsDir` as a path relative to `$filesRoot`.
     *
     * Purely textual — the directories need not exist yet — and `..` is
     * resolved before containment is tested, so a traversing configuration is
     * refused rather than silently producing a `public://` URI that escapes
     * the root.
     */
    private static function relativeToFilesRoot(string $uploadsDir, string $filesRoot): string
    {
        $root = self::normalizePath($filesRoot);
        $directory = self::normalizePath($uploadsDir);

        if ($directory === $root) {
            return '';
        }
        if ($root === '' || !str_starts_with($directory, $root . '/')) {
            throw new \InvalidArgumentException(sprintf(
                'MediaAssetStore uploads directory "%s" must sit inside the media files root "%s"; '
                . 'otherwise the rows it writes cannot be served by the authorized media download route.',
                $uploadsDir,
                $filesRoot,
            ));
        }

        return substr($directory, \strlen($root) + 1);
    }

    private static function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return (str_starts_with($normalized, '/') ? '/' : '') . implode('/', $segments);
    }

    private function safeDisplayName(string $filename): string
    {
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '', basename($filename)) ?? '';

        return $name !== '' ? mb_substr($name, 0, 120) : 'upload';
    }
}
