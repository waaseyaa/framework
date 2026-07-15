<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Media\Http\Router\MediaDownloadRouter;
use Waaseyaa\Media\Http\Router\MediaRouter;
use Waaseyaa\Media\Version\MediaVersionType;

final class MediaServiceProvider extends ServiceProvider implements HasHttpDomainRoutersInterface
{
    public function httpDomainRouters(HttpKernel $httpKernel): iterable
    {
        return [
            new MediaRouter($httpKernel->getProjectRoot(), $httpKernel->getConfig()),
            new MediaDownloadRouter(
                $httpKernel->getEntityTypeManager(),
                $httpKernel->getAccessHandler(),
                $this->resolveFilesRoot($httpKernel),
            ),
        ];
    }

    private function resolveFilesRoot(HttpKernel $httpKernel): string
    {
        $configured = $httpKernel->getConfig()['files_root'] ?? null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : $httpKernel->getProjectRoot() . '/storage/files';
    }

    public function register(): void
    {
        $this->singleton(UploadHandler::class, fn() => new UploadHandler(
            basePath: $this->config['media']['upload_path'] ?? 'public/uploads',
            allowedMimeTypes: $this->config['media']['allowed_types'] ?? UploadHandler::DEFAULT_ALLOWED_TYPES,
            maxSizeBytes: $this->config['media']['max_size'] ?? UploadHandler::DEFAULT_MAX_SIZE,
        ));

        $this->entityType(new EntityType(
            id: 'media',
            label: 'Media',
            description: 'Uploaded files, images, and embedded media',
            class: Media::class,
            keys: ['id' => 'mid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'bundle'],
            group: 'media',
            api: true,
        ));

        $this->entityType(new EntityType(
            id: 'media_type',
            label: 'Media Type',
            description: 'Media type definitions and allowed file formats',
            class: MediaType::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'media',
            api: true,
        ));

        // WP01 (versioned-blob-media-abstraction-01KSEFTJ): content-addressed version entity.
        $this->entityType(MediaVersionType::create());
    }

    public function boot(): void
    {
        // WP02 (versioned-blob-media-abstraction-01KSEFTJ): wire versioning subscribers.
        //
        // RE-PARKED (GitHub #1946, shipped-regression fix): this used to be
        // KNOWINGLY LEFT DEAD by resolving the foundation
        // `Waaseyaa\Foundation\Event\EventDispatcherInterface` FQCN, which
        // `ProviderRegistryKernelServices::get()` did not serve at the time
        // (WP4 framework-wide dead-subscriber sweep, audit-remediation batch
        // 2026-07-01/02). GitHub #1942 (G-025, shipped alpha.259) widened
        // the bus to also serve the dispatcher under the foundation FQCN, so
        // `resolveOptional()` below started returning a real dispatcher and
        // `addSubscriber()` LIVE-ACTIVATED `MediaVersionStorageDriver` and
        // `MediaCascadeDeleteSubscriber` in every production kernel boot —
        // an unintended side effect of #1942, not a deliberate decision.
        //
        // This is still unsafe: activating the versioned-blob-media
        // subsystem's cascade-delete subscriber ahead of the finish-or-park
        // decision tracked in #1742 risks data-lossy cascade deletes of
        // media version blobs. This early return re-parks it explicitly and
        // unconditionally — it does not depend on which FQCN the bus does
        // or does not serve, so a future dispatcher-key-serving change
        // cannot silently re-activate this again.
        //
        // Do NOT remove this early return without first reading #1742 and
        // the versioned-blob-media-abstraction-01KSEFTJ spec.
        //
        // The wiring this used to perform, kept here for reference so a
        // future finish-or-park decision on #1742 does not have to
        // reconstruct it from scratch:
        //
        //   $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);
        //   if (!$dispatcher instanceof EventDispatcherInterface) {
        //       return;
        //   }
        //   $db = $this->resolveOptional(DatabaseInterface::class);
        //   $auditWriter = $this->resolveOptional(AuditWriterInterface::class);
        //   $logger = $this->resolveOptional(LoggerInterface::class);
        //   $resolvedLogger = $logger instanceof LoggerInterface ? $logger : null;
        //   if ($db instanceof DatabaseInterface) {
        //       $etm = $this->resolveOptional(EntityTypeManager::class);
        //       $entityRepo = $etm instanceof EntityTypeManager
        //           ? $etm->getRepository('media_version')
        //           : null;
        //       if ($entityRepo instanceof EntityRepositoryInterface) {
        //           $cas = new ContentAddressedFileRepositoryDecorator(new InMemoryFileRepository());
        //           $versionRepo = new MediaVersionRepository($entityRepo, $db, $resolvedLogger);
        //           if ($auditWriter instanceof AuditWriterInterface) {
        //               $driver = new MediaVersionStorageDriver($versionRepo, $cas, $auditWriter, $resolvedLogger);
        //               $dispatcher->addSubscriber($driver);
        //           }
        //           $dispatcher->addSubscriber(new MediaCascadeDeleteSubscriber($versionRepo, $resolvedLogger));
        //       }
        //   }
        return;
    }
}
