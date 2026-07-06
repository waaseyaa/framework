<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\SchemaOrg;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Maps an entity to a schema.org JSON-LD node for injection into a page `<head>`.
 *
 * The schema.org `@type` is resolved per entity type / bundle from a mapping
 * (overridable), falling back to `WebPage`. Only non-sensitive, presentation
 * data is included — callers pass the canonical URL and may pass an
 * already-access-filtered description and label override (see `$labelOverride`
 * on {@see map()}); with no override this mapper reads the entity's raw
 * `label()`, so callers rendering to an untrusted audience MUST pass an
 * access-checked label (R7 WP1).
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

    private readonly LoggerInterface $logger;

    /**
     * @param array<string, string> $typeMapOverrides Merged over the defaults.
     */
    public function __construct(array $typeMapOverrides = [], ?LoggerInterface $logger = null)
    {
        $this->typeMap = array_merge(self::DEFAULT_TYPE_MAP, $typeMapOverrides);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build the JSON-LD node for an entity.
     *
     * @param ?string $description Optional, already-access-filtered summary text.
     * @param ?string $dateModified Optional ISO-8601 last-modified timestamp.
     * @param ?string $labelOverride Optional access-checked replacement for
     *                               `$entity->label()`. Callers that have already
     *                               resolved the label through
     *                               {@see \Waaseyaa\Access\EntityAccessHandler::viewableLabel()}
     *                               (e.g. `SsrPageHandler`) pass the checked value
     *                               here so the JSON-LD `name` never leaks a
     *                               field-access-restricted label (R7 WP1).
     *                               Defaults to the raw `$entity->label()` for
     *                               backward compatibility with callers that have
     *                               no access context.
     *
     * @return array<string, mixed>
     */
    public function map(
        EntityInterface $entity,
        string $url,
        ?string $description = null,
        ?string $dateModified = null,
        ?string $labelOverride = null,
    ): array {
        $node = [
            '@context' => self::CONTEXT,
            '@type' => $this->resolveType($entity),
            'name' => $this->sanitizeUtf8($labelOverride ?? $entity->label()),
        ];

        if ($url !== '') {
            $node['url'] = $url;
        }
        if ($description !== null && $description !== '') {
            $node['description'] = $this->sanitizeUtf8($description);
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
        // JSON_HEX_TAG|JSON_HEX_AMP hex-escape `<`, `>`, `&` so hostile entity
        // data (e.g. a `</script>` in a label) cannot break out of the
        // `<script>` element and inject markup.
        //
        // `name`/`description` are already scrubbed by sanitizeUtf8() in
        // map(), but toScriptTag() also accepts a caller-built $node
        // directly, so this is a second line of defense: an invalid-UTF-8
        // entity label must degrade the JSON-LD block, never 500 the page
        // (audit L3-seo.md, MAJOR finding).
        try {
            $json = json_encode(
                $node,
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            // Best-effort side effect: degrade to no JSON-LD block, but never
            // silently — log so a recurring bad node is diagnosable.
            $this->logger->warning('Schema.org JSON-LD encoding failed; omitting the JSON-LD block: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * Scrub ill-formed UTF-8 byte sequences so a corrupt/invalid entity
     * string can never reach json_encode()'s JSON_THROW_ON_ERROR and crash
     * the page render. Valid UTF-8 (including non-ASCII scripts, e.g.
     * Anishinaabemowin diacritics/syllabics) passes through unchanged.
     */
    private function sanitizeUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_scrub($value, 'UTF-8');
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
