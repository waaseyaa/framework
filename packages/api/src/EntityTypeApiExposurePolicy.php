<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Entity\ApiExposableEntityTypeInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Immutable, boot-scoped effective generic JSON:API exposure policy.
 *
 * Package/app entity metadata is the capability ceiling. When the application
 * supplies api.entity_type_allowlist, the list may only narrow that ceiling.
 *
 * @api
 */
final readonly class EntityTypeApiExposurePolicy
{
    /** @param array<string, bool> $effectiveMap */
    private function __construct(private array $effectiveMap) {}

    /** @param array<string, mixed> $config */
    public static function fromConfig(EntityTypeManagerInterface $manager, array $config): self
    {
        $definitions = $manager->getDefinitions();
        $declared = [];
        foreach ($definitions as $id => $definition) {
            $declared[$id] = $definition instanceof ApiExposableEntityTypeInterface
                && $definition->isApiExposed();
        }
        ksort($declared);

        $api = $config['api'] ?? null;
        $present = is_array($api) && array_key_exists('entity_type_allowlist', $api);
        if (!$present) {
            return new self($declared);
        }

        $allowlist = $api['entity_type_allowlist'];
        if (!is_array($allowlist) || !array_is_list($allowlist)) {
            throw new \InvalidArgumentException(
                'api.entity_type_allowlist must be a list of exact entity-type ids.',
            );
        }

        $seen = [];
        foreach ($allowlist as $id) {
            if (!is_string($id) || $id === '') {
                throw new \InvalidArgumentException(
                    'api.entity_type_allowlist contains an empty or non-string entity-type id.',
                );
            }
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException(sprintf(
                    'api.entity_type_allowlist contains duplicate entity-type id "%s".',
                    $id,
                ));
            }
            $seen[$id] = true;
            if (!array_key_exists($id, $definitions)) {
                throw new \InvalidArgumentException(sprintf(
                    'api.entity_type_allowlist names unregistered entity type "%s".',
                    $id,
                ));
            }
            if (!$declared[$id]) {
                throw new \InvalidArgumentException(sprintf(
                    'api.entity_type_allowlist cannot expose "%s": its canonical api capability is false.',
                    $id,
                ));
            }
        }

        $effective = [];
        foreach ($declared as $id => $isDeclared) {
            $effective[$id] = $isDeclared && isset($seen[$id]);
        }

        return new self($effective);
    }

    public function isExposed(string|EntityTypeInterface $entityType): bool
    {
        $id = $entityType instanceof EntityTypeInterface ? $entityType->id() : $entityType;

        return $this->effectiveMap[$id] ?? false;
    }

    /** @return array<string, bool> */
    public function effectiveMap(): array
    {
        return $this->effectiveMap;
    }
}
