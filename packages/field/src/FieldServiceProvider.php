<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\Classification\ClassificationClearanceCheckerInterface;
use Waaseyaa\Field\Classification\ClassificationLabelRegistry;
use Waaseyaa\Field\Classification\ClassificationLabelRegistryInterface;
use Waaseyaa\Field\Classification\EntityLifecycleSubscriber;
use Waaseyaa\Field\Classification\LabelInheritanceResolver;
use Waaseyaa\Field\Classification\ParentResolver\AttachmentParentResolver;
use Waaseyaa\Field\Classification\ParentResolver\MediaParentResolver;
use Waaseyaa\Field\Classification\ParentResolver\NodeParentResolver;
use Waaseyaa\Field\Classification\RoleBasedClearanceChecker;
use Waaseyaa\Field\Entity\ClassificationLabelDefinition;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Wires the field package services and boots the BundleTemplateCompiler.
 *
 * Discovery: compile() is called with an empty list by default, making it
 * a no-op until callers explicitly supply template classes. Host applications
 * may resolve BundleTemplateCompiler and call compile(['My\Template', ...])
 * in their own boot step, or WP10 may wire automatic discovery from
 * PackageManifest.
 *
 * Classification substrate (WP01):
 *   - Registers the classification_label_definition entity type.
 *   - Wires LabelInheritanceResolver with three stock parent resolvers.
 *   - Subscribes EntityLifecycleSubscriber to entity PRE_SAVE events.
 *
 * Classification retention engine (WP02):
 *   - Registers the retention_policy entity type.
 *   - Binds ClassificationLabelRegistryInterface and
 *     ClassificationClearanceCheckerInterface so the auto-discovered
 *     ClassificationFieldAccessPolicy can resolve its dependencies via the
 *     kernel's PolicyDependencyResolver.
 *
 * Refs: gap-matrix-A4, DIR-004, FR-005, FR-006, FR-008.
 */
final class FieldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(
            FieldDefinitionRegistryInterface::class,
            fn() => new FieldDefinitionRegistry(),
        );

        $this->singleton(
            FieldDefinitionRegistry::class,
            fn() => $this->resolve(FieldDefinitionRegistryInterface::class),
        );

        $this->singleton(
            FieldTypeManager::class,
            fn() => new FieldTypeManager(
                directories: [
                    __DIR__ . '/Item',
                    __DIR__ . '/FieldType',
                ],
            ),
        );

        $this->singleton(
            BundleTemplateCompiler::class,
            fn() => new BundleTemplateCompiler(
                $this->resolve(FieldDefinitionRegistryInterface::class),
            ),
        );

        // --- Classification substrate (WP01) ---

        // Register the classification_label_definition entity type.
        $this->entityType(new EntityType(
            id: 'classification_label_definition',
            label: 'Classification Label Definition',
            class: ClassificationLabelDefinition::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'display_name'],
            description: 'Defines the vocabulary of classification labels available in the system',
            group: 'classification',
        ));

        // Wire the inheritance resolver as a singleton so host applications
        // can resolve it and register additional parent resolvers.
        $this->singleton(LabelInheritanceResolver::class, function (): LabelInheritanceResolver {
            $resolver = new LabelInheritanceResolver();
            // Register the three stock parent resolvers (all stubs — host
            // applications replace them by resolving LabelInheritanceResolver
            // and calling addResolver() with a concrete implementation).
            $resolver->addResolver(new NodeParentResolver());
            $resolver->addResolver(new MediaParentResolver());
            $resolver->addResolver(new AttachmentParentResolver());

            return $resolver;
        });

        // --- Classification retention engine (WP02) ---

        // Register the retention_policy entity type. Schema lives in
        // packages/field/migrations/2026_05_25_000004_create_retention_policy_table.php.
        $this->entityType(new EntityType(
            id: 'retention_policy',
            label: 'Retention Policy',
            class: RetentionPolicy::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
            description: 'Classification-driven retention rule (purge, redact, or hold-flag) keyed by label set.',
            group: 'classification',
        ));

        // Bind the classification label registry (consumed by
        // ClassificationFieldAccessPolicy via the policy dependency resolver).
        $this->singleton(
            ClassificationLabelRegistryInterface::class,
            fn(): ClassificationLabelRegistryInterface => new ClassificationLabelRegistry(
                $this->resolve(EntityTypeManager::class),
            ),
        );
        $this->singleton(
            ClassificationLabelRegistry::class,
            fn(): ClassificationLabelRegistry => $this->resolve(ClassificationLabelRegistryInterface::class),
        );

        // Bind the role-based clearance checker. The configurable mapping
        // (`classification.role_clearance`) is consumed by the host
        // application's ConfigInterface at boot; the default mapping is
        // applied when no override is present.
        $this->singleton(
            ClassificationClearanceCheckerInterface::class,
            fn(): ClassificationClearanceCheckerInterface => new RoleBasedClearanceChecker(),
        );
        $this->singleton(
            RoleBasedClearanceChecker::class,
            fn(): RoleBasedClearanceChecker => $this->resolve(ClassificationClearanceCheckerInterface::class),
        );
    }

    public function boot(): void
    {
        /** @var BundleTemplateCompiler $compiler */
        $compiler = $this->resolve(BundleTemplateCompiler::class);

        // Pass an empty list: the compiler is a no-op until template classes
        // are supplied. Host applications may call compile([...]) explicitly,
        // or WP10 will wire PackageManifest-based discovery.
        $compiler->compile([]);

        // --- Classification substrate: wire the lifecycle subscriber ---
        $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $auditWriter = $this->resolveOptional(AuditWriterInterface::class);
        if (!$auditWriter instanceof AuditWriterInterface) {
            return;
        }

        $logger = $this->resolveOptional(LoggerInterface::class);
        $resolvedLogger = $logger instanceof LoggerInterface ? $logger : null;

        $resolver = $this->resolve(LabelInheritanceResolver::class);

        $dispatcher->addSubscriber(new EntityLifecycleSubscriber(
            resolver: $resolver,
            auditWriter: $auditWriter,
            logger: $resolvedLogger,
        ));
    }
}
