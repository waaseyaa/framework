<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

/**
 * The production CFG-02 activation authorizer (#2430).
 *
 * Until this existed, `ConfigurationActivationAuthorizerInterface` had no
 * production producer, so every non-genesis activation received
 * {@see RefusingConfigurationActivationAuthorizer} and `config:import` could not
 * complete on any consumer — CFG-03 verification would pass in full and CFG-02
 * would then refuse.
 *
 * ## What actually authorizes an import
 *
 * Two independent facts, held by two different parties:
 *
 *  - **The bytes** are authorized by a protected signer. The content reaching
 *    the active store was signed on a host holding CFG-04 custody, and the
 *    importing side verified that signature against a public trust key it did
 *    not mint, bound the manifest to a freshly revalidated directory, checked
 *    the installed cohort, and checked replay state.
 *  - **The timing** is authorized by explicit CLI execution. Somebody with
 *    shell access to this deployment chose to run `config:import` now.
 *
 * Neither alone is sufficient and this class manufactures neither. It is a
 * predicate over a request that already carries its own proof, not a source of
 * authority.
 *
 * ## What it will not authorize
 *
 * Deletion, rollback, and candidate sweep are deliberately out of scope and
 * keep refusing. Those are destructive operations whose safe policy is a
 * separate decision — a signature proves what the new content *is*, never that
 * removing existing content is intended or safe. Widening this class to cover
 * them would smuggle a policy nobody has made.
 *
 * ## Why the `$deletes` argument is not the deletion test
 *
 * The activator computes it as `tombstones !== [] || completeReplacement`, and
 * every ordinary verified activation is a complete replacement by construction
 * ({@see ConfigurationActivationRequest::activateVerified()} forces it). So the
 * argument is always true here and carries no information — testing it would
 * refuse every import, which is indistinguishable from having no authorizer at
 * all.
 *
 * Tombstones are the exact deletion signal, and that equivalence is enforced
 * upstream rather than assumed: a complete replacement must carry one
 * content-bound tombstone for every active entry it omits, or the activator
 * throws. So no tombstones means no active entry is removed, and the importer
 * only produces tombstones under `--delete-orphans`.
 *
 * @api
 */
final class VerifiedNonDestructiveConfigurationActivationAuthorizer implements ConfigurationActivationAuthorizerInterface
{
    public function authorize(ConfigurationActivationRequest $request, bool $deletes): void
    {
        if ($request->isGenesis) {
            // Genesis never reaches an authorizer (the activator excludes it),
            // and it carries no bundle, so it could not satisfy the checks
            // below. Refuse explicitly rather than letting a future caller
            // discover an accidental answer here.
            throw new \DomainException(
                'Genesis activation is not operator-authorized and must not be routed through the activation authorizer.',
            );
        }

        if ($request->operation !== 'activate') {
            throw new \DomainException(sprintf(
                'Configuration operation "%s" is not authorized. This authority covers ordinary activation only; '
                . 'rollback has its own unbound validator and continues to refuse, as does candidate sweep.',
                $request->operation,
            ));
        }

        if ($request->tombstones() !== []) {
            throw new \DomainException(sprintf(
                'Configuration activation that deletes %d active entr%s is not authorized. '
                . 'A signature proves what the new content is, never that removing existing content is intended. '
                . 'Destructive import policy is a separate decision.',
                count($request->tombstones()),
                count($request->tombstones()) === 1 ? 'y' : 'ies',
            ));
        }

        $bundle = $request->verifiedBundle;
        if ($bundle === null) {
            // A backstop rather than a live path: the request type already
            // refuses an ordinary activation without a bundle. It stays because
            // this authority must never depend on a constructor invariant it
            // does not own.
            throw new \DomainException(
                'Configuration activation requires a CFG-03 verified bundle. '
                . 'Unverified content is never authorized, whatever the caller.',
            );
        }

        if (!$bundle->verification->signed) {
            // Unsigned verification exists only under sealed bootstrap policy,
            // which production refuses. Authorizing it here would reintroduce
            // through the back door exactly what UnsignedConfigPolicy closes.
            throw new \DomainException(
                'Configuration activation requires a signed CFG-03 bundle; unsigned verification is not authorized.',
            );
        }
    }
}
