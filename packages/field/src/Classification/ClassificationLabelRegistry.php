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

        // C-22 WP3: loadByKey() has no repository equivalent, so this is a
        // bounded query + find() against the canonical repository.
        $repository = $this->entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->condition('label_id', $labelId)
            ->range(0, 1)
            ->execute();
        $loaded = $ids === [] ? null : $repository->find((string) $ids[0]);

        $definition = $loaded instanceof ClassificationLabelDefinition ? $loaded : null;
        $this->cache[$labelId] = $definition;

        return $definition;
    }

    public function invalidate(): void
    {
        $this->cache = [];
    }
}
