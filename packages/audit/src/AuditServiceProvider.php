<?php

declare(strict_types=1);

namespace Waaseyaa\Audit;

use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Capability\QueryFieldOperation;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserSelfProfileReaderInterface;
use Waaseyaa\Audit\Bootstrap\AuditedUserIdentityLookup;
use Waaseyaa\Audit\Bootstrap\AuditedUserInternalFieldReader;
use Waaseyaa\Audit\Bootstrap\AuditedUserSelfProfileReader;
use Waaseyaa\Audit\Bootstrap\IdentityBootstrapReader;
use Waaseyaa\Audit\Bootstrap\SessionBootstrapReader;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriteFailureObserver;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\AuditCheckpointCustody;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Integrity\FileCheckpointSink;
use Waaseyaa\Audit\Listener\AgentToolAuditListener;
use Waaseyaa\Audit\Listener\ApiRequestAuditListener;
use Waaseyaa\Audit\Listener\BroadcastAuditListener;
use Waaseyaa\Audit\Listener\EntityLifecycleAuditListener;
use Waaseyaa\Audit\Listener\McpApprovalDecisionAuditListener;
use Waaseyaa\Audit\Listener\McpDispatchAuditListener;
use Waaseyaa\Audit\Listener\PublishPointerAuditListener;
use Waaseyaa\Audit\Listener\RollbackAuditListener;
use Waaseyaa\Audit\Query\AuditEventQuery;
use Waaseyaa\Audit\Rekey\AuditCheckpointSuccessionRekeyAdapter;
use Waaseyaa\Audit\Schedule\AuditCheckpointScheduleEntries;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Storage\ApprovalEventSchema;
use Waaseyaa\Audit\Storage\StrictAuditLedgerSchema;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Audit\Writer\DatabaseOperationApprovalStore;
use Waaseyaa\Audit\Writer\DatabaseStrictAuditLedger;
use Waaseyaa\Audit\Writer\DatabaseStrictFieldStorageGatewayAudit;
use Waaseyaa\Audit\Writer\DatabaseStrictPrivilegedReadLedger;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Backend\StrictFieldStorageGatewayAuditInterface;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
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
final class AuditServiceProvider extends ServiceProvider implements HasMiddlewareInterface, ProvidesApplicationMasterRekeyContributionsInterface
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

        $this->singleton(CapabilityRegistryInterface::class, static function (): CapabilityRegistryInterface {
            $registry = new InMemoryCapabilityRegistry();
            $registry->register(new CapabilityDeclaration(
                issuer: 'http.identity-bootstrap',
                reason: CapabilityReason::SessionBootstrap,
                entityTypes: ['user'],
                bundles: ['user'],
                fields: ['roles', 'permissions', 'status'],
                actorSemantics: [CapabilityActorSemantics::NoActingContext],
                maxTtlSeconds: 60,
                justification: 'Build the immutable HTTP authorization principal after identity resolution.',
                bindTenantFromContext: true,
                bindCommunityFromContext: true,
            ));
            foreach ([
                ['user.credentials', CapabilityReason::CredentialVerification, ['status', 'pass', 'legacy_pass']],
                ['user.two-factor', CapabilityReason::CredentialVerification, ['mail', 'two_factor_secret', 'two_factor_recovery_codes_hash', 'two_factor_last_used_step']],
                ['user.mail-delivery', CapabilityReason::MailDelivery, ['name', 'mail']],
                ['user.verification', CapabilityReason::CredentialVerification, ['mail', 'email_verified']],
                ['user.session-identity', CapabilityReason::SessionBootstrap, ['name', 'mail', 'roles']],
                ['user.maintenance-authorization', CapabilityReason::MaintenanceCli, ['roles', 'permissions']],
            ] as [$issuer, $reason, $fields]) {
                $registry->register(new CapabilityDeclaration(
                    issuer: $issuer,
                    reason: $reason,
                    entityTypes: ['user'],
                    bundles: ['user'],
                    fields: $fields,
                    actorSemantics: [CapabilityActorSemantics::NoActingContext],
                    maxTtlSeconds: 60,
                    justification: 'Exact framework-owned User internal field operation.',
                ));
            }
            $registry->register(new CapabilityDeclaration(
                issuer: 'user.identity-lookup',
                reason: CapabilityReason::CredentialVerification,
                entityTypes: ['user'],
                bundles: ['user'],
                queryFields: ['name', 'mail', 'status'],
                queryOperations: [QueryFieldOperation::Predicate, QueryFieldOperation::Exists],
                actorSemantics: [CapabilityActorSemantics::NoActingContext],
                maxTtlSeconds: 60,
                justification: 'Resolve an active login identity without exposing query authority.',
            ));
            return $registry;
        });

        $this->singleton(AccountFieldReadScopeInterface::class, function (): AccountFieldReadScopeInterface {
            $kernelScope = $this->kernelServices?->get(AccountFieldReadScopeInterface::class);

            return $kernelScope instanceof AccountFieldReadScopeInterface ? $kernelScope : new AccountFieldReadScope();
        });

        $this->singleton(StrictPrivilegedReadLedgerInterface::class, function (): StrictPrivilegedReadLedgerInterface {
            return new DatabaseStrictPrivilegedReadLedger($this->resolve(DatabaseInterface::class));
        });

        // The strict reserve/finalize ledger for mutating request pipelines
        // (#2177 F4). Its port lives in foundation, not here, because the MCP
        // write tier consumes it and must not require waaseyaa/audit at runtime.
        // Runtime resolution validates coordinator-installed schema and never
        // creates or repairs it.
        $this->singleton(StrictAuditLedgerInterface::class, function (): StrictAuditLedgerInterface {
            $database = $this->resolve(DatabaseInterface::class);
            new StrictAuditLedgerSchema($database)->ensure();

            return new DatabaseStrictAuditLedger($database);
        });

        // The durable human-approval store for destructive MCP write-tier calls
        // (#2177 F1). Same shape as the strict ledger above: the port lives in
        // foundation so the MCP write tier needs no runtime waaseyaa/audit
        // dependency. Runtime resolution validates coordinator-installed schema.
        $this->singleton(OperationApprovalStoreInterface::class, function (): OperationApprovalStoreInterface {
            $database = $this->resolve(DatabaseInterface::class);
            new ApprovalEventSchema($database)->ensure();

            return new DatabaseOperationApprovalStore($database, ttlSeconds: $this->approvalTtlSeconds());
        });

        $this->singleton(StrictFieldStorageGatewayAuditInterface::class, function (): StrictFieldStorageGatewayAuditInterface {
            return new DatabaseStrictFieldStorageGatewayAudit($this->resolve(DatabaseInterface::class));
        });

        $this->singleton(UserInternalFieldReaderInterface::class, function (): UserInternalFieldReaderInterface {
            $capabilities = $this->resolve(CapabilityRegistryInterface::class);
            $ledger = $this->resolve(StrictPrivilegedReadLedgerInterface::class);
            assert($capabilities instanceof CapabilityRegistryInterface);
            assert($ledger instanceof StrictPrivilegedReadLedgerInterface);

            return new AuditedUserInternalFieldReader(new AuditedFieldRead($capabilities, $ledger), $capabilities);
        });

        $this->singleton(UserSelfProfileReaderInterface::class, function (): UserSelfProfileReaderInterface {
            $capabilities = $this->resolve(CapabilityRegistryInterface::class);
            $ledger = $this->resolve(StrictPrivilegedReadLedgerInterface::class);
            assert($capabilities instanceof CapabilityRegistryInterface);
            assert($ledger instanceof StrictPrivilegedReadLedgerInterface);

            return new AuditedUserSelfProfileReader(new AuditedFieldRead($capabilities, $ledger), $capabilities);
        });

        $this->singleton(UserIdentityLookupInterface::class, function (): UserIdentityLookupInterface {
            $capabilities = $this->resolve(CapabilityRegistryInterface::class);
            $ledger = $this->resolve(StrictPrivilegedReadLedgerInterface::class);
            assert($capabilities instanceof CapabilityRegistryInterface);
            assert($ledger instanceof StrictPrivilegedReadLedgerInterface);

            return new AuditedUserIdentityLookup(new AuditedQueryFieldRead($capabilities, $ledger), $capabilities);
        });

        $this->singleton(AccountPrincipalFactoryInterface::class, function (): AccountPrincipalFactoryInterface {
            $capabilities = $this->resolve(CapabilityRegistryInterface::class);
            $ledger = $this->resolve(StrictPrivilegedReadLedgerInterface::class);
            assert($capabilities instanceof CapabilityRegistryInterface);
            assert($ledger instanceof StrictPrivilegedReadLedgerInterface);

            return new AccountPrincipalFactory(new IdentityBootstrapReader(
                new SessionBootstrapReader(new AuditedFieldRead($capabilities, $ledger)),
                $capabilities,
                'http.identity-bootstrap',
            ));
        });

        $this->singleton(AuditWriterInterface::class, function (): AuditWriterInterface {
            $database = $this->resolve(DatabaseInterface::class);
            $logger = $this->resolveOptional(LoggerInterface::class);

            // Optional L1 observer seam (design §10.4): higher-layer metric
            // collectors (e.g. Telescope / Prometheus at L6) bind this to get
            // a loud signal on write failure. Null observer if nothing is bound.
            $observer = $this->resolveOptional(AuditWriteFailureObserver::class);

            // Wrap the database in the append-only decorator: the writer can only
            // ever append to audit_event — never update or delete (OCAP FR-003).
            return new AuditEventWriter(
                database: new AppendOnlyAuditDatabase($database),
                logger: $logger instanceof LoggerInterface ? $logger : null,
                observer: $observer instanceof AuditWriteFailureObserver ? $observer : null,
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

        $this->singleton(AuditCheckpointCustody::class, function (): AuditCheckpointCustody {
            $keyring = $this->resolveOptional(ApplicationMasterKeyring::class);
            if ($keyring instanceof ApplicationMasterKeyring) {
                $legacyKey = null;
                if (($this->config['audit']['accept_legacy_application_secret_checkpoints'] ?? false) === true) {
                    $applicationSecret = $this->resolve(ApplicationSecret::class);
                    assert($applicationSecret instanceof ApplicationSecret);
                    $legacyKey = $applicationSecret->derive(ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC);
                }

                return new AuditCheckpointCustody($keyring, $legacyKey);
            }

            $applicationSecret = $this->resolve(ApplicationSecret::class);
            assert($applicationSecret instanceof ApplicationSecret);

            return new AuditCheckpointCustody(
                legacyKey: $applicationSecret->derive(ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC),
            );
        });

        $this->singleton(AuditCheckpointBuilder::class, function (): AuditCheckpointBuilder {
            $logger = $this->resolveOptional(LoggerInterface::class);

            return new AuditCheckpointBuilder(
                database: $this->resolve(DatabaseInterface::class),
                sink: $this->resolve(CheckpointSink::class),
                logger: $logger instanceof LoggerInterface ? $logger : null,
                custody: $this->resolve(AuditCheckpointCustody::class),
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

    public function applicationMasterRekeyContributions(): iterable
    {
        $database = $this->resolve(DatabaseInterface::class);
        if (!$database instanceof DatabaseInterface) {
            throw new \LogicException('Audit rekey composition requires the kernel database authority.');
        }

        yield new ApplicationMasterRekeyContribution(
            new AuditCheckpointSuccessionRekeyAdapter($database),
            [new ApplicationMasterPurposePolicy(
                id: ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
                ownerPackage: 'waaseyaa/audit',
                strategy: ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
                maximumLifetimeSeconds: 0,
                retentionSeconds: 0,
                adapterId: AuditCheckpointSuccessionRekeyAdapter::ID,
                rollbackBehavior: 'append-authenticated-rollback-marker',
            )],
        );
    }

    /**
     * The approval expiry window, from `mcp.write_tier.approval.ttl_seconds`.
     *
     * The key lives under `mcp.` because the TTL is write-tier policy — how
     * long a standing human approval can authorize a destructive call — and
     * this provider merely carries it to the store it wires. A supplied value
     * must be a strict positive integer (the integer-shaped strings YAML/env
     * actually deliver are accepted); anything else is refused rather than
     * guessed, because a misread TTL silently widens the approval window.
     *
     * @throws ConfigException on a malformed value
     */
    private function approvalTtlSeconds(): int
    {
        $mcp = $this->config['mcp'] ?? null;
        $writeTier = \is_array($mcp) && \is_array($mcp['write_tier'] ?? null) ? $mcp['write_tier'] : [];
        $approval = \is_array($writeTier['approval'] ?? null) ? $writeTier['approval'] : [];

        if (!\array_key_exists('ttl_seconds', $approval)) {
            return DatabaseOperationApprovalStore::DEFAULT_TTL_SECONDS;
        }

        $value = $approval['ttl_seconds'];
        $ttl = null;
        if (\is_int($value)) {
            $ttl = $value;
        } elseif (\is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            $ttl = (int) trim($value);
        }

        if ($ttl === null || $ttl <= 0) {
            // Names the key and the value's type, never the value —
            // configuration may hold secrets and this message reaches logs.
            throw new ConfigException(
                \sprintf(
                    'Configuration key "mcp.write_tier.approval.ttl_seconds" must be a positive integer '
                    . 'number of seconds; got a value of type %s. Remove the key to accept the default (%d).',
                    \get_debug_type($value),
                    DatabaseOperationApprovalStore::DEFAULT_TTL_SECONDS,
                ),
                ['config_key' => 'mcp.write_tier.approval.ttl_seconds', 'value_type' => \get_debug_type($value)],
            );
        }

        return $ttl;
    }

    public function boot(): void
    {
        // Validate schema tables without mutating them.
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
        $approvalDecisionListener = new McpApprovalDecisionAuditListener($writer, $resolvedLogger);
        $dispatcher->addListener(
            McpApprovalDecisionAuditListener::EVENT_NAME,
            [$approvalDecisionListener, 'onApprovalDecision'],
        );
        $dispatcher->addSubscriber(new BroadcastAuditListener($writer, $resolvedLogger));
        $dispatcher->addSubscriber(new PublishPointerAuditListener($writer, $resolvedLogger, $resolvedContext));
        $dispatcher->addSubscriber(new RollbackAuditListener($writer, $resolvedLogger, $resolvedContext));
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

        $scope = $this->resolve(AccountFieldReadScopeInterface::class);
        assert($scope instanceof AccountFieldReadScopeInterface);
        $principalFactory = $this->resolve(AccountPrincipalFactoryInterface::class);
        assert($principalFactory instanceof AccountPrincipalFactoryInterface);
        $accountContext = $this->resolveOptional(AccountContextInterface::class);

        return [
            new FieldReadContextMiddleware(
                $principalFactory,
                $scope,
                $accountContext instanceof AccountContextInterface ? $accountContext : null,
            ),
            new ApiRequestAuditListener($writer, $resolvedLogger),
        ];
    }
}
