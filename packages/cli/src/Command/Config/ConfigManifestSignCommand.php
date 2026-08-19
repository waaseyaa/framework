<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Config;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Manifest\ConfigManifestBundleSigner;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;

/**
 * `bin/waaseyaa config:manifest:sign` — the CFG-03 authoring command (#2430).
 *
 * Runs on a host that holds signing custody: a maintainer machine or a
 * protected CI environment. It validates the authored sync directory, builds
 * the canonical bundle manifest from the reviewed Composer cohort, signs it, and
 * writes the envelope beside the directory. It never modifies or activates
 * configuration — the sync directory is an input, and the sidecar is the only
 * output.
 *
 * The importing consumer receives the sync directory, this envelope, and the
 * public `trust_keys`. It never receives the signing key. Do not expose the
 * signing secret to pull-request workflows or to ordinary production runtime.
 *
 * A profile without `config_manifest_signing.signing_key` publishes no signer,
 * and this command refuses there rather than degrading to something weaker —
 * a verifier-only host is not a signing host.
 *
 * Exit codes:
 *  - `0` — the envelope was written.
 *  - `1` — refused (no custody, invalid bundle, or an incompatible cohort).
 *
 * @api
 */
final class ConfigManifestSignCommand
{
    /** @param callable(): ?ConfigManifestBundleSigner $signerFactory */
    public function __construct(
        private readonly mixed $signerFactory,
        private readonly ConfigurationAuthorityContext $authority,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $signer = ($this->signerFactory)();
        if (!$signer instanceof ConfigManifestBundleSigner) {
            $io->error(sprintf(
                'No configuration manifest signing custody is composed for this profile. '
                . 'Signing requires config_manifest_signing.signing_key (a %s binding); '
                . 'a verifier-only host cannot sign.',
                ConfigManifestSignerInterface::class,
            ));

            return 1;
        }

        $syncPath = $this->authority->syncPath;
        $scope = $io->option('scope') ?? ConfigManifestBundleSigner::previousScope($syncPath);
        if (!is_string($scope) || $scope === '') {
            $io->error(
                'A bundle scope is required for the first signature. '
                . 'Pass --scope=<authority-scope>; later signatures continue the same lineage by default.',
            );

            return 1;
        }

        $sequenceOption = $io->option('sequence');
        $sequence = $sequenceOption === null
            ? ConfigManifestBundleSigner::nextSequence($syncPath)
            : (int) $sequenceOption;

        try {
            $result = $signer->sign($syncPath, $scope, $sequence, $this->producerEvidence());
        } catch (\Throwable $exception) {
            $io->error('Refused to sign the configuration bundle: ' . $exception->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Signed %d configuration entr%s from %s.', $result->entryCount, $result->entryCount === 1 ? 'y' : 'ies', $syncPath));
        $io->writeln(sprintf('  scope           %s', $result->bundleScope));
        $io->writeln(sprintf('  sequence        %d', $result->bundleSequence));
        $io->writeln(sprintf('  manifest hash   %s', $result->manifestHash));
        $io->writeln(sprintf('  trust key       %s', $result->trustKeyReference));
        foreach ($result->requiredPackageContracts as $package => $version) {
            $io->writeln(sprintf('  contract        %s@%d', $package, $version));
        }
        $io->writeln(sprintf('Envelope written to %s.', $result->envelopePath));
        $io->writeln('Ship the sync directory and this envelope to the consumer. The signing key stays here.');

        return 0;
    }

    /**
     * Evidence that is reproducible from the same tree.
     *
     * Anything varying per run (a timestamp, a hostname, an operator name)
     * would make two signatures of identical content differ, and the sidecar is
     * meant to be reviewable by comparing it to what a rebuild produces.
     *
     * @return array<string, string>
     */
    private function producerEvidence(): array
    {
        return [
            'producer' => 'waaseyaa/config:manifest-sign.v1',
            'authority_id' => $this->authority->authorityId,
        ];
    }
}
