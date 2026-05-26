<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\Entity\ClassificationLabelDefinition;

/**
 * Default {@see ClassificationLabelRegistryInterface} backed by the
 * `classification_label_definition` entity storage.
 *
 * Caches resolved definitions per-process to avoid repeated lookups when
 * the access policy evaluates many entities in a single request. The
 * {@see EntityLifecycleSubscriber} (or a host application) calls
 * {@see invalidate()} after a definition save/delete so the next lookup
 * reloads from storage.
 *
 * @api
 */
final class ClassificationLabelRegistry implements ClassificationLabelRegistryInterface
{
    private const string ENTITY_TYPE_ID = 'classification_label_definition';

    /** @var array<string, ClassificationLabelDefinition|null> */
    private array $cache = [];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function definition(string $labelId): ?ClassificationLabelDefinition
    {
        if ($labelId === '') {
            return null;
        }

        if (array_key_exists($labelId, $this->cache)) {
            return $this->cache[$labelId];
        }

        $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE_ID);
        $loaded = $storage->loadByKey('label_id', $labelId);

        $definition = $loaded instanceof ClassificationLabelDefinition ? $loaded : null;
        $this->cache[$labelId] = $definition;

        return $definition;
    }

    public function invalidate(): void
    {
        $this->cache = [];
    }
}
