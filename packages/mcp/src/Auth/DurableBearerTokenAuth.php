<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Durable bearer-token auth for the authenticated MCP write tier (#2177 F3).
 *
 * Replaces the static in-memory token→account map for production: credentials
 * live in the {@see BearerTokenStoreInterface} (hashed, expiring, revocable,
 * rotatable, audience-bound), and the authenticated principal is the REAL
 * owning account loaded from the user repository — never a token-derived
 * identity — so the F1 approval separation-of-duties comparison (requesting
 * owner vs deciding operator) keeps its meaning.
 *
 * Fail-closed on every branch: absent/malformed header, unknown/expired/
 * revoked/wrong-audience token, store outage, missing or inactive owner
 * account, and a scopeless (malformed) record all answer null —
 * byte-indistinguishable at the endpoint (same 401 envelope). Failures log
 * safe METADATA only (exception class, no token material, no driver detail).
 */
final readonly class DurableBearerTokenAuth implements WriteTierAuthInterface, ScopedMcpAuthInterface
{
    public const string DEFAULT_AUDIENCE = 'mcp:write';

    /**
     * @param EntityRepositoryInterface $accounts The `user` entity repository
     *        the token's owner uid resolves through.
     * @param AccountPrincipalFactoryInterface $principals The audited
     *        principal factory: an entity-backed account can never reach an
     *        access decision directly ({@see \Waaseyaa\Access\DecisionAccountResolver}),
     *        so the loaded owner is snapshotted into immutable claims here.
     *        The principal's id() IS the owner account uid — token ids never
     *        become identities.
     */
    public function __construct(
        private BearerTokenStoreInterface $store,
        private EntityRepositoryInterface $accounts,
        private AccountPrincipalFactoryInterface $principals,
        private string $audience = self::DEFAULT_AUDIENCE,
        private ?LoggerInterface $logger = null,
    ) {}

    public function authenticate(?string $authorizationHeader): ?AccountInterface
    {
        return $this->authenticateWithScopes($authorizationHeader)?->account;
    }

    public function authenticateWithScopes(?string $authorizationHeader): ?ScopedPrincipal
    {
        if ($authorizationHeader === null || $authorizationHeader === '') {
            return null;
        }
        if (!\str_starts_with(\strtolower($authorizationHeader), 'bearer ')) {
            return null;
        }

        $token = \trim(\substr($authorizationHeader, 7));
        if ($token === '') {
            return null;
        }

        try {
            $record = $this->store->verify($token, $this->audience);
        } catch (\Throwable $e) {
            // The store contract already answers null on outages, but a
            // custom implementation may throw: same fail-closed result, with
            // a diagnosable (token-free) log line.
            $this->logger?->error('MCP durable bearer auth: token store failure.', [
                'exception_class' => $e::class,
            ]);

            return null;
        }

        if ($record === null) {
            return null;
        }

        if ($record->scopes === []) {
            // A persisted record without scopes is malformed — least
            // privilege has nothing to enforce, so nothing is granted.
            return null;
        }

        try {
            // ACTIVE-owner query, mirroring AuditedUserIdentityLookup::
            // findActiveByLogin: liveness is a `status = 1` QUERY CONDITION,
            // not a field read — `status` is a Protected field and a direct
            // `isActive()` read pre-auth has no account read context to run
            // in (it would throw MissingFieldReadContext). A blocked or
            // missing owner simply matches no row: indistinguishable from an
            // unknown token, exactly like BearerTokenAuth's FR-006 posture.
            $matches = $this->accounts->findBy(
                ['uid' => $record->accountUid, 'status' => 1],
                null,
                1,
            );
        } catch (\Throwable $e) {
            $this->logger?->error('MCP durable bearer auth: owner account lookup failure.', [
                'exception_class' => $e::class,
            ]);

            return null;
        }

        $account = $matches[0] ?? null;
        if (!$account instanceof AccountInterface) {
            return null;
        }

        try {
            $principal = $this->principals->fromAccount($account);
        } catch (\Throwable $e) {
            // No audited bootstrap reader (or a snapshot failure): the owner
            // cannot become a decision principal, so nothing authenticates.
            $this->logger?->error('MCP durable bearer auth: principal snapshot failure.', [
                'exception_class' => $e::class,
            ]);

            return null;
        }

        return new ScopedPrincipal($principal, $record->scopes);
    }
}
