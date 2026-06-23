<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment;

use Waaseyaa\Attachment\Http\AttachmentDownloadRouter;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
use Waaseyaa\Attachment\Storage\PrivateFileStore;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Service provider for the Attachment package.
 *
 * Registers the Attachment entity type, binds AttachmentRepository, and wires the
 * authorized private-file download surface (`GET /attachment/{id}/download` →
 * {@see AttachmentDownloadRouter}). Schema creation (AttachmentSchema::ensureTable())
 * is invoked separately during install/migration — see EntitySchemaSync.
 *
 * The access policy ({@see Policy\ParentDelegatedAccessPolicy}) is auto-discovered
 * via the #[PolicyAttribute] attribute; no registration is needed here.
 */
final class AttachmentServiceProvider extends ServiceProvider implements HasHttpDomainRoutersInterface
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(Attachment::class));

        $this->singleton(AttachmentSchema::class, fn() => new AttachmentSchema(
            $this->resolve(DatabaseInterface::class),
        ));

        $this->singleton(AttachmentRepository::class, fn() => new AttachmentRepository(
            $this->resolve(EntityRepositoryInterface::class),
            $this->resolve(DatabaseInterface::class),
        ));
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
}
