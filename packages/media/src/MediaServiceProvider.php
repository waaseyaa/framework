<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Media\Http\Router\MediaRouter;
use Waaseyaa\Media\Version\MediaVersion;
use Waaseyaa\Media\Version\MediaVersionType;

final class MediaServiceProvider extends ServiceProvider implements HasHttpDomainRoutersInterface
{
    public function httpDomainRouters(HttpKernel $httpKernel): iterable
    {
        return [
            new MediaRouter($httpKernel->getProjectRoot(), $httpKernel->getConfig()),
        ];
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
        ));

        $this->entityType(new EntityType(
            id: 'media_type',
            label: 'Media Type',
            description: 'Media type definitions and allowed file formats',
            class: MediaType::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'media',
        ));

        // WP01 (versioned-blob-media-abstraction-01KSEFTJ): content-addressed version entity.
        $this->entityType(MediaVersionType::create());
    }
}
