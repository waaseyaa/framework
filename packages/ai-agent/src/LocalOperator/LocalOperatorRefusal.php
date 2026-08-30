<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\LocalOperator;

/**
 * The loud failure every ADR-022 D-6 refusal row raises.
 *
 * D-6 is explicit that "refusal MUST be an explicit, loud failure — a thrown
 * exception or a structured refusal envelope. A silent downgrade to anonymous
 * is not an acceptable implementation of any row." Every named constructor
 * below therefore carries the row identifier it discharges, so a caller (and a
 * test) can assert *which* boundary refused rather than only that something
 * did.
 *
 * It extends `LogicException` rather than `RuntimeException` deliberately:
 * every row describes a wiring mistake — an HTTP path reaching for a CLI-only
 * principal, a serializer trying to persist a per-process identity, a
 * production runtime asking for a development identity. None are recoverable
 * conditions to be caught and retried.
 *
 * @api
 */
final class LocalOperatorRefusal extends \LogicException
{
    /**
     * @param string $row The ADR-022 refusal row this instance discharges
     *                    (`R-1` … `R-6`), `D-7` for a malformed profile, or
     *                    `D-8` for the fail-closed read-side sovereignty
     *                    precondition.
     */
    private function __construct(
        string $message,
        public readonly string $row,
    ) {
        parent::__construct($message);
    }

    /**
     * R-6 — refused because the acting process is not the local stdio
     * transport.
     */
    public static function notLocalStdioTransport(string $transportId): self
    {
        return new self(
            sprintf(
                'LocalOperatorPrincipal refused (ADR-022 D-6 R-6): only the validated local stdio transport '
                . '"%s" may construct it; got "%s".',
                LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
                $transportId,
            ),
            'R-6',
        );
    }

    /**
     * R-6 — refused because the SAPI is not `cli`.
     *
     * Note the deliberate asymmetry with `Waaseyaa\User\DevAdminAccount`,
     * whose own `ALLOWED_SAPIS` is `['cli-server', 'cli', 'frankenphp']`: the
     * local operator admits `cli` and nothing else, so no HTTP-served SAPI can
     * reach it even inside a development runtime. That is what makes R-1
     * structural rather than merely conventional.
     */
    public static function notCliSapi(string $sapi): self
    {
        return new self(
            sprintf(
                'LocalOperatorPrincipal refused (ADR-022 D-6 R-6): requires the "cli" SAPI; got "%s".',
                $sapi,
            ),
            'R-6',
        );
    }

    /**
     * R-6 — refused because the runtime is not an explicitly configured
     * development environment.
     *
     * Fail-closed by construction: this consults
     * `RuntimePolicy::isExplicitDevelopment()`, which classifies only an
     * explicitly configured `environment` key and never inherits the mutable
     * process environment. An unconfigured runtime is production-like.
     */
    public static function notDevelopmentRuntime(string $environment): self
    {
        return new self(
            sprintf(
                'LocalOperatorPrincipal refused (ADR-022 D-6 R-6): requires an explicitly configured development '
                . 'environment; got "%s".',
                $environment,
            ),
            'R-6',
        );
    }

    /**
     * R-4 — the principal was found already sitting in the account context.
     *
     * The read side of the same row. A guard that only refuses `set()` still
     * hands the principal back from `current()` when it wraps a context that
     * already held it, or when something mutates the inner context through a
     * second reference after wrapping — and `current()` is the side
     * `EntityRepository::resolveActor()` actually consumes.
     */
    public static function ambientAccountObserved(): self
    {
        return new self(
            'LocalOperatorPrincipal refused (ADR-022 D-6 R-4): it was found in the kernel AccountContext. '
            . 'Reading it back would let EntityRepository::resolveActor() cast the ambient account id to int, '
            . 'and (int) "' . LocalOperatorPrincipal::ID . '" is 0 — the AnonymousUser id. Whatever installed '
            . 'it bypassed the guard (a second reference to the inner context, or a context wrapped after the '
            . 'principal was already set).',
            'R-4',
        );
    }

    /** R-4 — the principal must never become the ambient acting account. */
    public static function ambientAccountInstallation(): self
    {
        return new self(
            'LocalOperatorPrincipal refused (ADR-022 D-6 R-4): it must not be installed into the kernel '
            . 'AccountContext. EntityRepository::resolveActor() casts the ambient account id to int, and '
            . '(int) "' . LocalOperatorPrincipal::ID . '" is 0 — the AnonymousUser id — which would silently '
            . 'attribute every write on this plane to the anonymous sentinel.',
            'R-4',
        );
    }

    /** R-5 — the principal must not outlive the stdio process. */
    public static function serialization(string $operation): self
    {
        return new self(
            sprintf(
                'LocalOperatorPrincipal refused (ADR-022 D-6 R-5): %s is forbidden. The principal is constructed '
                . 'per-process by the local stdio bootstrap and dies with that process; it must not reach a queue '
                . 'envelope, session payload, cache key body, or any other artefact that outlives it.',
                $operation,
            ),
            'R-5',
        );
    }

    /**
     * D-8 — a content-bearing capability was granted before the fail-closed
     * read-side sovereignty evaluation surface exists.
     */
    public static function contentCapabilityWithoutReadPolicy(string $capability): self
    {
        return new self(
            sprintf(
                'LocalOperatorToolProfile refused (ADR-022 D-8): capability "%s" is content-bearing, and the '
                . 'read-side sovereignty evaluation it must be gated by does not exist yet. SovereigntyGuardrails '
                . 'takes a MutationRequest and cannot express a read decision, so this fails closed rather than '
                . 'granting a content capability with no read-side gate.',
                $capability,
            ),
            'D-8',
        );
    }

    /** D-7 — a malformed profile: an empty tool id or capability. */
    public static function malformedProfile(string $detail): self
    {
        return new self(
            sprintf('LocalOperatorToolProfile refused (ADR-022 D-7): %s', $detail),
            'D-7',
        );
    }
}
