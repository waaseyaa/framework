<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\SchemaOrg;

use Waaseyaa\Entity\EntityInterface;

/**
 * Maps an entity to a schema.org JSON-LD node for injection into a page `<head>`.
 *
 * The schema.org `@type` is resolved per entity type / bundle from a mapping
 * (overridable), falling back to `WebPage`. Only non-sensitive, presentation
 * data is included — callers pass the canonical URL and may pass an
 * already-access-filtered description; this mapper never reads raw entity
 * storage beyond the public label.
 *
 * @api
 */
final class EntitySchemaOrgMapper
{
    public const string CONTEXT = 'https://schema.org';
    private const string FALLBACK_TYPE = 'WebPage';

    /**
     * Default entity-type / bundle -> schema.org @type mapping. Keys are matched
     * as `entityTypeId.bundle` first, then `entityTypeId`.
     *
     * @var array<string, string>
     */
    private const DEFAULT_TYPE_MAP = [
        'node.article' => 'Article',
        'node.page' => 'WebPage',
        'node' => 'Article',
        'taxonomy_term' => 'DefinedTerm',
        'user' => 'Person',
        'media' => 'MediaObject',
        'genealogy_person' => 'Person',
    ];

    /** @var array<string, string> */
    private array $typeMap;

    /**
     * @param array<string, string> $typeMapOverrides Merged over the defaults.
     */
    public function __construct(array $typeMapOverrides = [])
    {
        $this->typeMap = array_merge(self::DEFAULT_TYPE_MAP, $typeMapOverrides);
    }

    /**
     * Build the JSON-LD node for an entity.
     *
     * @param ?string $description Optional, already-access-filtered summary text.
     * @param ?string $dateModified Optional ISO-8601 last-modified timestamp.
     *
     * @return array<string, mixed>
     */
    public function map(
        EntityInterface $entity,
        string $url,
        ?string $description = null,
        ?string $dateModified = null,
    ): array {
        $node = [
            '@context' => self::CONTEXT,
            '@type' => $this->resolveType($entity),
            'name' => $entity->label(),
        ];

        if ($url !== '') {
            $node['url'] = $url;
        }
        if ($description !== null && $description !== '') {
            $node['description'] = $description;
        }
        if ($dateModified !== null && $dateModified !== '') {
            $node['dateModified'] = $dateModified;
        }

        return $node;
    }

    /**
     * Render the JSON-LD node as a `<script type="application/ld+json">` block.
     *
     * @param array<string, mixed> $node
     */
    public function toScriptTag(array $node): string
    {
        $json = json_encode($node, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    private function resolveType(EntityInterface $entity): string
    {
        $entityTypeId = $entity->getEntityTypeId();
        $bundle = $entity->bundle();

        if ($bundle !== '' && isset($this->typeMap[$entityTypeId . '.' . $bundle])) {
            return $this->typeMap[$entityTypeId . '.' . $bundle];
        }

        return $this->typeMap[$entityTypeId] ?? self::FALLBACK_TYPE;
    }
}
