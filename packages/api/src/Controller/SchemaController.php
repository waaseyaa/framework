<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Returns JSON Schema representations of entity types.
 *
 * GET /api/schema/{entity_type} — returns a JSON Schema with widget hints,
 * field metadata, and permission requirements.
 *
 * Field-access scope: when an access handler and account are supplied, field
 * visibility is filtered against a *prototype* entity (a bare instance carrying
 * only the requested bundle key — see show()). The resulting `x-access-restricted`
 * markers and view-denied removals therefore reflect only STATIC, type/bundle-level
 * field policy, not instance-level decisions (owner-only fields, row-state gates).
 * Callers must not treat the rendered field set as a per-record access oracle.
 *
 * Route exposure: this endpoint (and /api/openapi.json) is registered by
 * foundation's BuiltinRouteRegistrar with requireAuthentication() — it is the
 * self-description of an auth-gated API and the prototype-filter caveat above
 * means it over-discloses instance-gated field definitions, so an anonymous
 * caller is 401'd. See docs/specs/api-layer.md "Schema self-description surface
 * requires authentication"; pinned by tests/Integration/SchemaSurfaceRequiresAuthTest.
 */
final class SchemaController
{
    private readonly LoggerInterface $logger;

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface|null $account */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly SchemaPresenter $schemaPresenter,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * GET /api/schema/{entity_type}[?bundle=...] — return JSON Schema for the
     * given entity type, scoped to a bundle when one is supplied so a content
     * type's distinct typed fields surface (e.g. page vs news).
     */
    public function show(string $entityTypeId, ?string $bundle = null): JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return JsonApiDocument::fromErrors(
                [JsonApiError::notFound("Unknown entity type: {$entityTypeId}.")],
                statusCode: 404,
            );
        }

        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $bundle = ($bundle === null || $bundle === '') ? null : $bundle;

        if ($bundle !== null) {
            $availableBundles = $this->schemaPresenter->availableBundles($entityTypeId);
            if ($availableBundles !== null && !in_array($bundle, $availableBundles, true)) {
                return JsonApiDocument::fromErrors(
                    [JsonApiError::unprocessable(sprintf(
                        "Unknown bundle '%s' for entity type '%s'.",
                        $bundle,
                        $entityTypeId,
                    ))],
                    statusCode: 422,
                );
            }
        }

        $entity = null;
        if ($this->accessHandler !== null && $this->account !== null) {
            $class = $entityType->getClass();
            // Build the prototype entity. Seed a non-null placeholder for every
            // declared field and entity key so that entity constructors which
            // require certain fields to be present (isset()-gated invariants —
            // e.g. UserBlock's blocker_id, engagement Comment's user_id/body)
            // still construct; without this a strict constructor would throw and
            // — via the fail-closed backstop below — 500 the schema endpoint for
            // an otherwise-valid type. Presence is what constructor gates test,
            // so the placeholder value is a type-appropriate zero, not a valid
            // domain value. The requested bundle overrides the bundle-key seed.
            $protoValues = [];
            foreach (array_values($entityType->getKeys()) as $keyColumn) {
                $protoValues[$keyColumn] = '';
            }
            foreach ($this->entityTypeManager->resolveFieldDefinitions($entityTypeId, $bundle) as $fieldName => $definition) {
                $protoValues[$fieldName] = $this->placeholderForField($definition);
            }
            $bundleKey = $entityType->getKeys()['bundle'] ?? null;
            if ($bundle !== null && $bundleKey !== null) {
                $protoValues[$bundleKey] = $bundle;
            }
            try {
                $entity = new $class($protoValues);
            } catch (\Throwable $e) {
                // Fail CLOSED: without a prototype entity, SchemaPresenter::present()
                // cannot run its field-access-filtering block (it is gated on
                // $entity !== null) and would emit an unfiltered schema — leaking
                // access-restricted field metadata. Refuse to emit any schema
                // instead of falling through with $entity left null. The exception
                // detail stays server-side only; the client gets a generic message.
                $this->logger->error(sprintf(
                    'SchemaController: refusing to emit an unfiltered schema for %s (%s) — failed to construct the access-check prototype entity: %s',
                    $entityTypeId,
                    $class,
                    $e->getMessage(),
                ));

                return JsonApiDocument::fromErrors(
                    [JsonApiError::internalError("Could not generate the access-filtered schema for '{$entityTypeId}'.")],
                    statusCode: 500,
                );
            }
        }

        $schema = $this->schemaPresenter->present(
            $entityType,
            $this->entityTypeManager->resolveFieldDefinitions($entityTypeId, $bundle),
            $entity,
            $this->accessHandler,
            $this->account,
        );

        // A base schema is the bundled-create discovery surface. Filter its
        // structural roster through the same bundle-aware create access check
        // used by persistence. A requested bundle, however, also scopes edit
        // schemas and must not be rejected merely because the actor cannot
        // create another entity in that bundle.
        if ($bundle === null && $this->accessHandler !== null && $this->account !== null) {
            $schema = $this->filterCreateBundles($entityTypeId, $schema);
        }

        $self = "/api/schema/{$entityTypeId}";
        if ($bundle !== null) {
            $self .= '?bundle=' . rawurlencode($bundle);
        }

        return new JsonApiDocument(
            meta: [
                'schema' => $schema,
            ],
            links: [
                'self' => $self,
            ],
            statusCode: 200,
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function filterCreateBundles(string $entityTypeId, array $schema): array
    {
        if ($this->accessHandler === null || $this->account === null) {
            return $schema;
        }

        $availableBundles = $this->schemaPresenter->availableBundles($entityTypeId);
        $bundleKey = $schema['x-bundle-key'] ?? null;
        if ($availableBundles === null
            || $availableBundles === []
            || !is_string($bundleKey)
            || !isset($schema['properties'][$bundleKey])
            || !is_array($schema['properties'][$bundleKey])) {
            return $schema;
        }

        $authorizedBundles = array_values(array_filter(
            $availableBundles,
            fn(string $candidate): bool => $this->accessHandler
                ->checkCreateAccess($entityTypeId, $candidate, $this->account)
                ->isAllowed(),
        ));

        if ($authorizedBundles !== []) {
            $schema['properties'][$bundleKey]['enum'] = $authorizedBundles;

            return $schema;
        }

        // Keep the structural key for bundled-create detection, but remove the
        // selector/free-text affordance. A direct create remains protected by
        // JsonApiController::store() and returns 403.
        unset(
            $schema['properties'][$bundleKey]['enum'],
            $schema['properties'][$bundleKey]['x-label'],
            $schema['properties'][$bundleKey]['x-required'],
        );
        $schema['properties'][$bundleKey]['x-widget'] = 'hidden';
        $schema['properties'][$bundleKey]['readOnly'] = true;

        return $schema;
    }

    /**
     * A non-null, type-appropriate placeholder used only to satisfy a strict
     * entity constructor's presence (isset) checks when building the prototype
     * for field-access evaluation. The value is never persisted or surfaced —
     * only its presence matters.
     *
     * @param FieldDefinitionInterface|array<string, mixed> $definition
     */
    private function placeholderForField(FieldDefinitionInterface|array $definition): string|int|bool
    {
        $type = $definition instanceof FieldDefinitionInterface
            ? $definition->getType()
            : (string) ($definition['type'] ?? 'string');

        return match ($type) {
            'boolean' => false,
            'integer', 'list_integer' => 0,
            default => '',
        };
    }
}
