<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MediaServiceProvider extends ServiceProvider
{
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
    }
}
