<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\LocalOperator;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * The never-persisted, least-privilege acting identity of the local AI
 * development plane (ADR-022 D-3, D-4, D-5.D, D-6).
 *
 * Its shape is modelled directly on
 * `Waaseyaa\Migration\Account\MigrationSystemAccount`, which solved the same
 * problem for batch imports: a first-class principal that can pass the access
 * gate outside interactive request context without being a wildcard.
 *
 * **Why not `DevAdminAccount`.** A CLI process runs under SAPI `cli`, which is
 * absent from `HttpKernel::DEV_FALLBACK_SAPIS` (`['cli-server',
 * 'frankenphp']`), so a stdio transport starts with no acting account and every
 * tool call comes back `forbidden`. The tempting shortcut is `DevAdminAccount`,
 * whose *own* SAPI guard (`packages/user/src/DevAdminAccount.php:26`) does list
 * `cli`, so nothing mechanical prevents an stdio bootstrap from constructing
 * one. ADR-022 C-2 refuses it: it returns `PHP_INT_MAX` for its id, `true` for
 * every permission, `['administrator']` for its roles, and the constant
 * `'dev-admin'` for its claims generation. There is a second, independent
 * reason — acceptance evidence gathered under the dev fallback is invalid on
 * this framework, because its blanket `hasPermission()` masks protected
 * field-read denials, so a run that passes under it proves nothing about the
 * access posture a real caller sees.
 *
 * **Identity storage: none (D-3.1).** No `users` row, no session record, no
 * token record, no OAuth client, no durable identity artefact of any kind. It
 * is constructed per-process by the validated local stdio bootstrap and dies
 * with that process.
 *
 * **Never install this principal into the kernel `AccountContext` (D-6 R-4).**
 * `EntityRepository::resolveActor()`
 * (`packages/entity-storage/src/EntityRepository.php:364-374`) casts the
 * ambient account's `id()` to `int` for `revision_author` attribution.
 * `(int) 'local-operator:stdio'` is `0`, which is `Waaseyaa\User\AnonymousUser`'s
 * id — so a run whose account context held this principal would silently
 * attribute every write to the anonymous sentinel.
 * {@see LocalOperatorAccountContextGuard} makes that refusal executable rather
 * than a docblock warning; the local transport wraps its account context in
 * one.
 *
 * @api
 */
final class LocalOperatorPrincipal implements AuthorizationPrincipalInterface, \JsonSerializable
{
    /**
     * The fixed sentinel id (D-3.2, D-3.3).
     *
     * A **string**, because `AccountInterface::id()` already permits
     * `int|string` and a string cannot collide with an auto-increment uid,
     * cannot be confused with `AnonymousUser`'s `0`, and cannot be confused
     * with `DevAdminAccount`'s `PHP_INT_MAX`. A **fixed literal**, because
     * D-3.3 forbids embedding the OS username, home directory, hostname,
     * absolute project path, or any other machine-identifying value.
     */
    public const string ID = 'local-operator:stdio';

    /**
     * The sole role (D-3.5).
     *
     * Deliberately *not* `administrator`, and deliberately not any role a
     * framework `AccessPolicyInterface` treats as a blanket grant.
     */
    public const string ROLE = 'local_operator';

    /** Digest domain separator; bumping it invalidates every prior generation. */
    private const string CLAIMS_DIGEST_VERSION = 'local-operator-claims-v1';

    private function __construct(
        private readonly LocalOperatorTransportAttestation $attestation,
        private readonly LocalOperatorToolProfile $profile,
        private readonly ?string $tenantId,
        private readonly ?string $communityId,
    ) {}

    /**
     * The one way to obtain a local operator principal.
     *
     * Everything about R-6 lives in {@see LocalOperatorTransportAttestation}:
     * the `cli` SAPI, an explicitly configured development environment, and an
     * exact transport-identity match. ADR-022 D-3.0a is explicit that this
     * runtime refusal — not the fact that this class ships in a `require-dev`
     * package — is the actual containment.
     *
     * @param array<string, mixed> $runtimeConfig Kernel bootstrap config; only `environment` is read.
     * @param string               $transportId   Must be {@see LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID}.
     * @param ?LocalOperatorToolProfile $profile   Defaults to the D-7 default catalogue.
     * @param ?string              $narrowingSapi A test seam that can only ADD a refusal; it can never
     *                                            admit where the real runtime refuses (see the attestation).
     * @param ?string              $tenantId      Unbound by default (D-3.6).
     * @param ?string              $communityId   Unbound by default (D-3.6).
     *
     * @throws LocalOperatorRefusal R-6 when the runtime is not the validated local stdio transport.
     */
    public static function forLocalStdioTransport(
        array $runtimeConfig,
        string $transportId = LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
        ?LocalOperatorToolProfile $profile = null,
        ?string $narrowingSapi = null,
        ?string $tenantId = null,
        ?string $communityId = null,
    ): self {
        return new self(
            LocalOperatorTransportAttestation::forStdioTransport($runtimeConfig, $transportId, $narrowingSapi),
            $profile ?? new LocalOperatorToolProfile(),
            $tenantId,
            $communityId,
        );
    }

    /** The fixed string sentinel. Never an int, never machine-derived. */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Strict membership against the injected capability list (D-3.4).
     *
     * Never `true` unconditionally; never a prefix, pattern, or wildcard match.
     * This is the lower of D-7.2's two layered controls: a tool on the
     * allowlist whose capability is not granted here is still refused by
     * `AbstractAgentTool::requireCapability()`.
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->profile->capabilities(), true);
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    /**
     * A deterministic digest over the principal's effective authority (D-4).
     *
     * Never a constant. `claimsGeneration()` is a cache and authorization
     * dimension — consumed by `ProtectedCacheDimensions`, `SqlEntityQuery`,
     * `ContentSearchController`, and the queue envelope — so a constant
     * generation means entries computed under one authority are reused under a
     * different one. **The dangerous direction is narrowing**: an operator
     * revokes a capability, or tightens the read-side sovereignty policy, and
     * the principal keeps being served entries computed while it still held the
     * broader authority — data the now-narrower principal must not see. (The
     * reverse — a widened grant reading an entry built under the narrower one —
     * is merely an undergrant.) Digesting the authority is what makes
     * revocation take effect at the cache boundary rather than at the next
     * eviction.
     *
     * Inputs, all of them policy and none of them machine-identifying (D-4.3):
     * the granted capability list, the admitted tool-ID allowlist, the active
     * read-side sovereignty policy, and the tenant/community binding.
     */
    public function claimsGeneration(): string
    {
        return hash('sha256', serialize([
            'version' => self::CLAIMS_DIGEST_VERSION,
            'capabilities' => $this->profile->capabilities(),
            'tools' => $this->profile->toolIds(),
            'read_policy' => $this->profile->readPolicyToken(),
            'tenant' => $this->tenantId,
            'community' => $this->communityId,
        ]));
    }

    /** Unbound by default (D-3.6). */
    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    /** Unbound by default (D-3.6). */
    public function communityId(): ?string
    {
        return $this->communityId;
    }

    /** The default tool catalogue this principal acts under. */
    public function toolProfile(): LocalOperatorToolProfile
    {
        return $this->profile;
    }

    /**
     * The actor uid for a `StrictAuditReservation` (D-5.D.7): always `null`.
     *
     * `StrictAuditReservation::$actorUid` is a three-state `?int` whose
     * documented semantics are "id N, `0` only when the actor IS anonymous, or
     * `null` for no known persisted principal". The local operator has no
     * persisted account, and coercing its string sentinel to an int would yield
     * `0` — silently attributing the session to `AnonymousUser`. Its identity
     * travels in {@see auditMetadata()} instead.
     *
     * The return type is the standalone `null`, not `?int`: for this principal
     * the third state is the *only* state, and saying so in the signature means
     * a future edit that starts returning an integer here fails to compile
     * rather than quietly attributing a local session to a uid.
     */
    public function auditActorUid(): null
    {
        return null;
    }

    /**
     * Safe structural metadata for a `StrictAuditReservation` (D-5.D.7-8).
     *
     * The sentinel string plus the current claims generation, so an audit row
     * records *which* authority acted, not merely that something did. Every
     * value is a fixed literal or a digest: no OS username, home directory,
     * hostname, or absolute project path appears here or anywhere else this
     * class produces (D-5.D.8).
     *
     * Reserving and finalizing around dispatch is D-5.B, owned by the registry
     * bridge (#2657), and the `surface` constant plus per-request correlation
     * id are D-5.C, owned by the stdio transport (#2659). This method is the
     * principal's contribution to both and nothing more.
     *
     * @return array{principal: string, transport: string, claims_generation: string, roles: list<string>}
     */
    public function auditMetadata(): array
    {
        return [
            'principal' => self::ID,
            'transport' => $this->attestation->transportId,
            'claims_generation' => $this->claimsGeneration(),
            'roles' => $this->getRoles(),
        ];
    }

    /**
     * R-5 — refuse serialization.
     *
     * `serialize()` consults `__serialize()` when it exists, so throwing here
     * makes any attempt to place this principal in a queue envelope, session
     * payload, or cache body fail loudly at the point of the mistake.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw LocalOperatorRefusal::serialization('serializing a LocalOperatorPrincipal');
    }

    /**
     * R-5 — refuse deserialization, so a payload that reached a store by some
     * other route still cannot be revived into a principal.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw LocalOperatorRefusal::serialization('unserializing a LocalOperatorPrincipal');
    }

    /**
     * R-5 — refuse `var_export()` round-tripping, the other route by which a
     * principal could be written into a generated PHP cache file.
     *
     * @param array<string, mixed> $state
     */
    public static function __set_state(array $state): never
    {
        throw LocalOperatorRefusal::serialization('exporting a LocalOperatorPrincipal via var_export()');
    }

    /**
     * R-5 — refuse JSON encoding, the route into a session payload or an
     * on-disk cache body that does not go through `serialize()`.
     */
    public function jsonSerialize(): mixed
    {
        throw LocalOperatorRefusal::serialization('JSON-encoding a LocalOperatorPrincipal');
    }
}
