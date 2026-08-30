<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\LocalOperator;

use Waaseyaa\Foundation\Kernel\RuntimePolicy;

/**
 * Proof that the acting process really is the validated local stdio transport,
 * running in an explicitly configured development runtime.
 *
 * This class *is* ADR-022 D-6 R-6, and D-3.0a is explicit that R-6 — not
 * packaging — is the actual containment: "A design that depends on the class
 * being *absent*, rather than on its *refusing to exist*, is one dependency
 * edit away from being wrong." So the attestation is the only way to obtain a
 * {@see LocalOperatorPrincipal}, and it refuses on three independent grounds:
 *
 * 1. **SAPI.** Only `cli`. Every HTTP-served SAPI (`fpm-fcgi`,
 *    `apache2handler`, `cgi-fcgi`, `litespeed`, and the two dev servers
 *    `cli-server` / `frankenphp`) is refused. This is what makes R-1 (HTTP
 *    authentication) and R-2 (token validation) structural: no request-borne
 *    header, cookie, parameter, bearer token, API key, JWT, or OAuth access
 *    token can produce this object, because the process serving the request is
 *    not running under `cli` at all. Note that this is *narrower* than
 *    `Waaseyaa\User\DevAdminAccount::ALLOWED_SAPIS`, which admits
 *    `cli-server` and `frankenphp` as well.
 * 2. **Environment.** {@see RuntimePolicy::isExplicitDevelopment()}, which
 *    classifies only an explicitly configured `environment` key and never
 *    inherits the mutable process environment — so an unconfigured runtime is
 *    production-like and refused, and setting `APP_ENV=local` in the
 *    environment is not by itself sufficient.
 * 3. **Transport identity.** An exact match against
 *    {@see self::STDIO_TRANSPORT_ID}. A caller that is not the local stdio
 *    server names something else and is refused.
 *
 * **The SAPI seam can only narrow, never widen.** `PHP_SAPI` is consulted
 * unconditionally and first; the `$narrowingSapi` argument is consulted second
 * and only to ADD a refusal. That asymmetry is the whole point. An override
 * that can admit where the real runtime would refuse is not a testing seam, it
 * is a way to mint authority: under a real `cli-server` process, a
 * `$sapi ?? PHP_SAPI` resolution would hand out a principal to anything that
 * passed the string `'cli'`. The seam exists so a test can prove the refusal
 * for a SAPI its own process cannot run under, and for nothing else.
 *
 * `tests/Integration/LocalOperator/LocalOperatorHttpSapiRefusalTest.php` proves
 * this from outside: it starts a real PHP built-in server (SAPI `cli-server`),
 * issues an HTTP request, and asserts the handler is refused even though it
 * passes `'cli'` for the seam and an explicit development config for the
 * environment gate. Restore the `?? PHP_SAPI` resolution and that test goes
 * red — no in-process test can distinguish the two, because the suite itself
 * runs under `cli`.
 *
 * Carries no machine-identifying value (D-3.3, D-5.D.8): no OS username, home
 * directory, hostname, or absolute project path is read, stored, or reported.
 * It is not serializable, for the same reason the principal is not (R-5).
 *
 * @api
 */
final class LocalOperatorTransportAttestation
{
    /**
     * The one transport permitted to construct a local operator principal.
     *
     * The conformant stdio server of #2659 names this constant. Nothing else
     * legitimately does.
     */
    public const string STDIO_TRANSPORT_ID = 'waaseyaa.local.stdio';

    /** The only SAPI a local stdio transport can be running under. */
    public const string REQUIRED_SAPI = 'cli';

    private function __construct(
        public readonly string $transportId,
        public readonly string $environment,
    ) {}

    /**
     * Attest that this process is the local stdio transport, or refuse loudly.
     *
     * @param array<string, mixed> $runtimeConfig  The kernel bootstrap config. Only its
     *                                             `environment` key is read, and only via
     *                                             {@see RuntimePolicy::isExplicitDevelopment()}.
     * @param string               $transportId    Must equal {@see self::STDIO_TRANSPORT_ID}.
     * @param ?string              $narrowingSapi  A test seam that can only ever ADD a refusal.
     *                                             `PHP_SAPI` is consulted unconditionally first,
     *                                             so this value cannot admit where the real
     *                                             runtime refuses — see the note below.
     *
     * @throws LocalOperatorRefusal R-6, on any of the three grounds above.
     */
    public static function forStdioTransport(
        array $runtimeConfig,
        string $transportId = self::STDIO_TRANSPORT_ID,
        ?string $narrowingSapi = null,
    ): self {
        if ($transportId !== self::STDIO_TRANSPORT_ID) {
            throw LocalOperatorRefusal::notLocalStdioTransport($transportId);
        }

        // The real runtime is consulted FIRST and is not overridable. A caller
        // running under `cli-server`, `fpm-fcgi`, or `frankenphp` is refused
        // here no matter what it passes for $narrowingSapi.
        if (PHP_SAPI !== self::REQUIRED_SAPI) {
            throw LocalOperatorRefusal::notCliSapi(PHP_SAPI);
        }

        // Only then is the seam consulted, and only to ADD a refusal. It lets
        // a test prove the refusal for a SAPI its own process cannot run
        // under; it can never turn a refusal into an admission, because the
        // unconditional check above has already run.
        if ($narrowingSapi !== null && $narrowingSapi !== self::REQUIRED_SAPI) {
            throw LocalOperatorRefusal::notCliSapi($narrowingSapi);
        }

        if (!RuntimePolicy::isExplicitDevelopment($runtimeConfig)) {
            $configured = $runtimeConfig['environment'] ?? null;

            throw LocalOperatorRefusal::notDevelopmentRuntime(
                is_string($configured) ? $configured : '<unconfigured>',
            );
        }

        /** @var string $environment It is a string: isExplicitDevelopment() returned true. */
        $environment = $runtimeConfig['environment'];

        return new self($transportId, $environment);
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw LocalOperatorRefusal::serialization('serializing a LocalOperatorTransportAttestation');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw LocalOperatorRefusal::serialization('unserializing a LocalOperatorTransportAttestation');
    }
}
