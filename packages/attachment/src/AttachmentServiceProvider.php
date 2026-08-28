<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment;

use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Attachment\Http\AttachmentDownloadMetadataReaderInterface;
use Waaseyaa\Attachment\Http\AttachmentDownloadRouter;
use Waaseyaa\Attachment\Http\AuditedAttachmentDownloadMetadataReader;
use Waaseyaa\Attachment\Maintenance\AttachmentMaintenanceFieldReader;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
use Waaseyaa\Attachment\Storage\PrivateFileStore;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Kernel\RuntimePolicy;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Service provider for the Attachment package.
 *
 * Registers the Attachment entity type, binds AttachmentRepository, and wires the
 * authorized private-file download surface (`GET /attachment/{id}/download` →
 * {@see AttachmentDownloadRouter}).
 *
 * The access policy ({@see Policy\ParentDelegatedAccessPolicy}) is auto-discovered
 * via the #[PolicyAttribute] attribute; no registration is needed here.
 *
 * {@see boot()} wires TWO cross-cutting concerns:
 *
 *   - {@see AttachmentSchema::ensureTable()} — the canonical schema
 *     materializer for this package's `#[Field]`-declared columns and
 *     composite/partial indexes. Coordinated schema sync applies it via
 *     `#[StorageSchemaTransition]`. Local/development boots still call it
 *     for convenience; production HTTP must not (#2478).
 *   - {@see AttachmentActiveGuardListener} onto `EntityEvents::PRE_SAVE` —
 *     the at-most-one-active guard for the generic entity API
 *     (`getRepository('attachment')->save()`), which bypasses
 *     {@see AttachmentRepository} entirely and therefore needs its own
 *     enforcement point.
 */
final class AttachmentServiceProvider extends ServiceProvider implements HasHttpDomainRoutersInterface
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(Attachment::class));

        $this->singleton(AttachmentSchema::class, fn() => new AttachmentSchema(
            $this->resolve(DatabaseInterface::class),
            $this->resolveLoggerOrNull(),
        ));

        $this->singleton(AttachmentRepository::class, fn() => new AttachmentRepository(
            $this->resolve(EntityRepositoryInterface::class),
            $this->resolve(DatabaseInterface::class),
            $this->resolveLoggerOrNull(),
            new AttachmentMaintenanceFieldReader(),
        ));

        $this->singleton(AttachmentDownloadMetadataReaderInterface::class, function (): AttachmentDownloadMetadataReaderInterface {
            $capabilities = $this->resolve(CapabilityRegistryInterface::class);
            $ledger = $this->resolve(StrictPrivilegedReadLedgerInterface::class);
            assert($capabilities instanceof CapabilityRegistryInterface);
            assert($ledger instanceof StrictPrivilegedReadLedgerInterface);
            return new AuditedAttachmentDownloadMetadataReader(
                new AuditedFieldRead($capabilities, $ledger),
                $capabilities,
                'field-read-active',
                'field-read-active',
            );
        });
    }

    /**
     * Resolves the kernel logger when available; both AttachmentSchema and
     * AttachmentRepository already default to NullLogger, so this is
     * best-effort wiring, not a hard dependency.
     */
    private function resolveLoggerOrNull(): ?LoggerInterface
    {
        $logger = $this->resolveOptional(LoggerInterface::class);

        return $logger instanceof LoggerInterface ? $logger : null;
    }

    /**
     * Materializes the canonical attachment schema, then wires
     * {@see AttachmentActiveGuardListener} onto `EntityEvents::PRE_SAVE`.
     *
     * Schema materialization on this boot path is local/development convenience
     * only. Production HTTP must not mutate entity-storage schema (#2478);
     * `install:init` / `schema:sync` apply {@see AttachmentSchema} through the
     * coordinated transition declared on the entity type.
     *
     * The listener wiring additionally needs the dispatcher, so it stays gated
     * on the database and dispatcher both.
     *
     * The kernel-services bus serves the event dispatcher ONLY under the
     * Symfony-contracts FQCN (`ProviderRegistryKernelServices::get()`);
     * resolving the foundation FQCN returns null and would silently skip
     * registration — same gotcha `RelationshipServiceProvider::boot()`
     * fixed for the delete guard (#1852). Resolve the served key, then
     * type-check against the foundation contract.
     */
    public function boot(): void
    {
        $database = $this->resolveOptional(DatabaseInterface::class);
        if (!$database instanceof DatabaseInterface) {
            return;
        }

        if ($this->allowsConvenientSchemaMaterialization()) {
            $this->resolve(AttachmentSchema::class)->ensureTable();
        }

        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof \Waaseyaa\Foundation\Event\EventDispatcherInterface) {
            return;
        }

        $dispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            new AttachmentActiveGuardListener($database),
        );
    }

    /**
     * The `GET /attachment/{id}/download` route itself is registered centrally in
     * foundation's `BuiltinRouteRegistrar` (the same place `media.upload` lives) —
     * an L2 package cannot depend on routing (L4). It is option-less
     * (anonymous-reachable): the {@see AttachmentDownloadRouter} below is the
     * enforcement point — it runs the deny-by-default `view` check (which
     * delegates to the parent entity) before streaming any bytes, so an
     * attachment whose parent is public is downloadable and one whose parent
     * is restricted 404s.
     *
     * @return iterable<AttachmentDownloadRouter>
     */
    public function httpDomainRouters(HttpKernel $httpKernel): iterable
    {
        yield new AttachmentDownloadRouter(
            $httpKernel->getEntityTypeManager(),
            $httpKernel->getAccessHandler(),
            new PrivateFileStore($this->resolvePrivateFilesRoot($httpKernel)),
            $this->resolve(AttachmentDownloadMetadataReaderInterface::class),
        );
    }

    /**
     * The private-bytes root — a sibling of the public `storage/files` tree that
     * is NEVER the `/files/` static-serving target, so private bytes are not
     * URL-reachable. Override via `config['attachment']['private_files_root']`.
     */
    private function resolvePrivateFilesRoot(HttpKernel $httpKernel): string
    {
        $config = $httpKernel->getConfig();
        $configured = $config['attachment']['private_files_root'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $httpKernel->getProjectRoot() . '/storage/private-files';
    }

    /** Production and staging HTTP must not CREATE or ALTER entity tables. */
    private function allowsConvenientSchemaMaterialization(): bool
    {
        return RuntimePolicy::resolve($this->config)->isDevelopment();
    }
}
