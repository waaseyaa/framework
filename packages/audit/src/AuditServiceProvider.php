<?php

declare(strict_types=1);

namespace Waaseyaa\Audit;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Entity\AuditEvent;
use Waaseyaa\Audit\Entity\AuditRetentionPolicy;
use Waaseyaa\Audit\Listener\AgentToolAuditListener;
use Waaseyaa\Audit\Listener\ApiRequestAuditListener;
use Waaseyaa\Audit\Listener\BroadcastAuditListener;
use Waaseyaa\Audit\Listener\EntityLifecycleAuditListener;
use Waaseyaa\Audit\Listener\McpDispatchAuditListener;
use Waaseyaa\Audit\Query\AuditEventQuery;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Wires the OCAP audit log substrate into the application container.
 *
 * register():
 *   - Registers audit_event + audit_retention_policy entity types.
 *   - Binds AuditWriterInterface → AuditEventWriter (best-effort).
 *   - Binds AuditQueryInterface → AuditEventQuery.
 *
 * boot():
 *   - Ensures schema tables exist.
 *   - Subscribes cross-cutting audit listeners.
 *
 * TODO(WP03): bind Waaseyaa\Api\Audit\AuditQueryReadModelInterface once that interface exists.
 */
final class AuditServiceProvider extends ServiceProvider implements HasMiddlewareInterface
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'audit_event',
            label: 'Audit Event',
            class: AuditEvent::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            description: 'OCAP append-only audit log entry',
            group: 'audit',
        ));

        $this->entityType(new EntityType(
            id: 'audit_retention_policy',
            label: 'Audit Retention Policy',
            class: AuditRetentionPolicy::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            description: 'OCAP audit log retention rule',
            group: 'audit',
        ));

        $this->singleton(AuditWriterInterface::class, function (): AuditWriterInterface {
            /** @var EntityRepositoryInterface $repo */
            $repo = $this->resolve(EntityTypeManager::class)->getRepository('audit_event');
            $logger = $this->resolveOptional(LoggerInterface::class);

            return new AuditEventWriter(
                repository: $repo,
                logger: $logger instanceof LoggerInterface ? $logger : null,
            );
        });

        $this->singleton(AuditQueryInterface::class, function (): AuditQueryInterface {
            return new AuditEventQuery(
                database: $this->resolve(DatabaseInterface::class),
            );
        });
    }

    public function boot(): void
    {
        // Ensure schema tables exist.
        $database = $this->resolveOptional(DatabaseInterface::class);
        if ($database instanceof DatabaseInterface) {
            $schemaHandler = new AuditEventSchemaHandler($database);
            $schemaHandler->ensureSchema();
        }

        // Subscribe cross-cutting audit listeners.
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $writer = $this->resolveOptional(AuditWriterInterface::class);
        if (!$writer instanceof AuditWriterInterface) {
            return;
        }

        $logger = $this->resolveOptional(LoggerInterface::class);
        $resolvedLogger = $logger instanceof LoggerInterface ? $logger : null;

        $dispatcher->addSubscriber(new EntityLifecycleAuditListener($writer, $resolvedLogger));
        $dispatcher->addSubscriber(new AgentToolAuditListener($writer, $resolvedLogger));
        $dispatcher->addSubscriber(new McpDispatchAuditListener($writer, $resolvedLogger));
        $dispatcher->addSubscriber(new BroadcastAuditListener($writer, $resolvedLogger));
    }

    /**
     * @return list<HttpMiddlewareInterface>
     */
    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        $writer = $this->resolveOptional(AuditWriterInterface::class);
        if (!$writer instanceof AuditWriterInterface) {
            return [];
        }

        $logger = $this->resolveOptional(LoggerInterface::class);
        $resolvedLogger = $logger instanceof LoggerInterface ? $logger : null;

        return [new ApiRequestAuditListener($writer, $resolvedLogger)];
    }
}
