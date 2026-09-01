<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Kernel\RuntimePolicy;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class TaxonomyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $term = EntityType::fromClass(Term::class);
        $this->entityType(new EntityType(
            id: $term->id(),
            label: $term->getLabel(),
            class: $term->getClass(),
            keys: $term->getKeys(),
            group: 'taxonomy',
            bundleEntityType: 'taxonomy_vocabulary',
            description: $term->getDescription(),
            api: true,
            _fieldDefinitions: $term->getFieldDefinitions(),
            _foreignKeys: [[
                'name' => VocabularyReferenceConstraint::NAME,
                'columns' => ['vid'],
                'table' => 'taxonomy_vocabulary',
                'references' => ['vid'],
                'options' => ['onDelete' => 'RESTRICT', 'onUpdate' => 'CASCADE'],
            ]],
        ));

        // Vocabulary is a configuration entity (ConfigEntityBase). Field-attribute
        // reflection does not apply; declare field definitions explicitly so the
        // admin SPA and API can render description/weight as editable fields.
        $this->entityType(new EntityType(
            id: 'taxonomy_vocabulary',
            label: 'Vocabulary',
            description: 'Term groupings that define classification systems',
            class: Vocabulary::class,
            keys: ['id' => 'vid', 'label' => 'name'],
            group: 'taxonomy',
            api: true,
            _fieldDefinitions: [
                'vid' => new FieldDefinition(
                    name: 'vid',
                    type: 'string',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Machine name',
                    description: 'Stable vocabulary identifier.',
                    readOnly: true,
                    settings: ['weight' => 0],
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
                ),
                'name' => new FieldDefinition(
                    name: 'name',
                    type: 'string',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Name',
                    description: 'The vocabulary display name.',
                    settings: ['weight' => 1],
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
                ),
                'description' => new FieldDefinition(
                    name: 'description',
                    type: 'text',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Description',
                    description: 'A description of the vocabulary.',
                    settings: ['weight' => 5],
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
                ),
                'weight' => new FieldDefinition(
                    name: 'weight',
                    type: 'integer',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Weight',
                    description: 'Sort order for this vocabulary.',
                    settings: ['weight' => 10],
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
                ),
            ],
        ));
    }

    /**
     * Wires TWO independent cross-cutting concerns:
     *
     *   - {@see TermHierarchyGuard} onto `EntityEvents::PRE_SAVE` — needs the
     *     dispatcher and entity type manager both.
     *   - {@see VocabularyReferenceConstraint::ensure()} — the vocabulary
     *     foreign key. Local/development boot may still materialize it for
     *     convenience; production and staging HTTP/kernel and
     *     retained-worker boot must not (#2761, reusing #2478's
     *     no-request-DDL contract — see AttachmentServiceProvider for the
     *     same pattern). Coordinated schema sync (`db:init`, `schema:sync`)
     *     is the single authoritative path: it applies the identical
     *     declared foreign key via SqlSchemaHandler's generic
     *     `ensureDeclaredForeignKeys()`, fed by the `_foreignKeys` entity-type
     *     declaration in {@see register()} above. Missing production shape
     *     fails closed with `[S1-DB106]` from `assertRuntimeSchema()` at
     *     `getRepository('taxonomy_term')` resolution — no silent skip.
     *
     * These are independent of each other: neither needs the other's
     * dependency to be present.
     */
    public function boot(): void
    {
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        $entityTypeManager = $this->resolveOptional(EntityTypeManager::class);
        if ($dispatcher instanceof EventDispatcherInterface && $entityTypeManager instanceof EntityTypeManagerInterface) {
            $dispatcher->addListener(
                EntityEvents::PRE_SAVE->value,
                new TermHierarchyGuard($entityTypeManager),
            );
        }

        $database = $this->resolveOptional(DatabaseInterface::class);
        if ($database instanceof DatabaseInterface && $this->allowsConvenientSchemaMaterialization()) {
            new VocabularyReferenceConstraint($database)->ensure();
        }
    }

    /** Production and staging HTTP/worker boot must not CREATE or ALTER the vocabulary foreign key (#2761). */
    private function allowsConvenientSchemaMaterialization(): bool
    {
        return RuntimePolicy::resolve($this->config)->isDevelopment();
    }
}
