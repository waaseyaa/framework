<?php

declare(strict_types=1);

namespace Waaseyaa\Audit;

use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Integrity\FileCheckpointSink;
use Waaseyaa\Audit\Listener\AgentToolAuditListener;
use Waaseyaa\Audit\Listener\ApiRequestAuditListener;
use Waaseyaa\Audit\Listener\BroadcastAuditListener;
use Waaseyaa\Audit\Listener\EntityLifecycleAuditListener;
use Waaseyaa\Audit\Listener\McpDispatchAuditListener;
use Waaseyaa\Audit\Listener\PublishPointerAuditListener;
use Waaseyaa\Audit\Query\AuditEventQuery;
use Waaseyaa\Audit\Schedule\AuditCheckpointScheduleEntries;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Wires the OCAP audit log substrate into the application container.
 *
 * register():
 *   - Binds AuditWriterInterface → AuditEventWriter (append-only, best-effort).
 *   - Binds AuditQueryInterface → AuditEventQuery.
 *   - audit_event / audit_retention_policy are deliberately NOT registered as
 *     content entities — they are raw OCAP log tables, not entity types.
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
        // audit_event and audit_retention_policy are intentionally NOT registered
        // as content entity types. They are flat OCAP log tables built by
        // AuditEventSchemaHandler and accessed through raw DatabaseInterface
        // writes/reads — never the entity repository. Registering them as content
        // entities produced 8 permanent schema:check false-positives (the lean
        // log tables lack the content-entity column set) and falsely implied an
        // entity CRUD/update path for an append-only log. See ocap-audit-log.md.

        $this->singleton(AuditWriterInterface::class, function (): AuditWriterInterface {
            $database = $this->resolve(DatabaseInterface::class);
            $logger = $this->resolveOptional(LoggerInterface::class);

            // Wrap the database in the append-only decorator: the writer can only
            // ever append to audit_event — never update or delete (OCAP FR-003).
            return new AuditEventWriter(
                database: new AppendOnlyAuditDatabase($database),
                logger: $logger instanceof LoggerInterface ? $logger : null,
            );
        });

        $this->singleton(AuditQueryInterface::class, function (): AuditQueryInterface {
            return new AuditEventQuery(
                database: $this->resolve(DatabaseInterface::class),
            );
        });

        $this->singleton(CheckpointSink::class, function (): CheckpointSink {
            $logger = $this->resolveOptional(LoggerInterface::class);
            $filePath = $this->config['audit']['checkpoint_file'] ?? (rtrim($this->projectRoot, '/') . '/storage/audit/checkpoints.jsonl');

            return new FileCheckpointSink(
                filePath: $filePath,
                echoStdout: false,
                logger: $logger instanceof LoggerInterface ? $logger : null,
            );
        });

        $this->singleton(AuditCheckpointBuilder::class, function (): AuditCheckpointBuilder {
            $logger = $this->resolveOptional(LoggerInterface::class);

            return new AuditCheckpointBuilder(
                database: $this->resolve(DatabaseInterface::class),
                sink: $this->resolve(CheckpointSink::class),
                logger: $logger instanceof LoggerInterface ? $logger : null,
                hmacKey: $this->config['audit']['checkpoint_hmac_key'] ?? null,
            );
        });

        $this->singleton(AuditCheckpointScheduleEntries::class, function (): AuditCheckpointScheduleEntries {
            $logger = $this->resolveOptional(LoggerInterface::class);
            $builder = $this->resolveOptional(AuditCheckpointBuilder::class);

            return new AuditCheckpointScheduleEntries(
                builder: $builder instanceof AuditCheckpointBuilder ? $builder : null,
                config: $this->config,
                logger: $logger instanceof LoggerInterface ? $logger : null,
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

        // The kernel's shared acting-account holder, served via the
        // kernel-services bus (null in bare-provider tests — listeners then
        // record null actors, the correct degraded behavior).
        $accountContext = $this->resolveOptional(AccountContextInterface::class);
        $resolvedContext = $accountContext instanceof AccountContextInterface ? $accountContext : null;

        $dispatcher->addSubscriber(new EntityLifecycleAuditListener($writer, $resolvedLogger, $resolvedContext));
        $dispatcher->addSubscriber(new AgentToolAuditListener($writer, $resolvedLogger, $resolvedContext));
        $dispatcher->addSubscriber(new McpDispatchAuditListener($writer, $resolvedLogger));
        $dispatcher->addSubscriber(new BroadcastAuditListener($writer, $resolvedLogger));
        $dispatcher->addSubscriber(new PublishPointerAuditListener($writer, $resolvedLogger, $resolvedContext));
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
