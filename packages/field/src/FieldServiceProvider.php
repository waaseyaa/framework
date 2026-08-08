<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

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
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
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
            fn() => new FieldTypeManager(),
        );

        $this->singleton(
            BundleTemplateCompiler::class,
            fn() => new BundleTemplateCompiler(
                $this->resolve(FieldDefinitionRegistryInterface::class),
            ),
        );

        // --- Classification substrate (WP01) ---

        // Register the classification_label_definition entity type.
        $this->entityType(EntityType::fromClass(ClassificationLabelDefinition::class, group: 'classification'));

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
        $this->entityType(EntityType::fromClass(RetentionPolicy::class, group: 'classification'));

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

        // Bind the role-based clearance checker, wiring the configurable
        // role→clearance mapping from `classification.role_clearance`. The
        // previous binding passed no argument, so the override was silently
        // ignored and clearance was permanently the stock default. The checker
        // falls back to its DEFAULT_ROLE_CLEARANCE when no override is present.
        $this->singleton(
            ClassificationClearanceCheckerInterface::class,
            fn(): ClassificationClearanceCheckerInterface => new RoleBasedClearanceChecker(
                $this->classificationRoleClearanceConfig(),
            ),
        );
        $this->singleton(
            RoleBasedClearanceChecker::class,
            fn(): RoleBasedClearanceChecker => $this->resolve(ClassificationClearanceCheckerInterface::class),
        );
    }

    /**
     * The host's `classification.role_clearance` override (role-id → clearance
     * level), or null to use the checker's default mapping.
     *
     * @return array<array-key, mixed>|null
     */
    private function classificationRoleClearanceConfig(): ?array
    {
        $classification = $this->config['classification'] ?? null;
        if (!is_array($classification)) {
            return null;
        }

        $roleClearance = $classification['role_clearance'] ?? null;

        return is_array($roleClearance) ? $roleClearance : null;
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
        //
        // The kernel-services bus serves the dispatcher ONLY under the
        // Symfony-contracts FQCN (ProviderRegistryKernelServices::get());
        // resolving the foundation FQCN returns null and silently skips
        // registration — the classification-label lifecycle subscriber
        // (and its audit trail) never ran in a real kernel boot. Same
        // gotcha RelationshipServiceProvider::boot() fixed for the delete
        // guard (#1852). Resolve the served key, then type-check against
        // the foundation contract.
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
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
