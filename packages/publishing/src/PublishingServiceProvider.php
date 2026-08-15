<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\Publishing\Rekey\PublishingPreviewRekeyAdapter;

/** Production composition for publishing preview custody. @api */
final class PublishingServiceProvider extends ServiceProvider implements ProvidesApplicationMasterRekeyContributionsInterface
{
    public function register(): void
    {
        $this->singleton(PreviewLinkService::class, function (): PreviewLinkService {
            $keyring = $this->resolveOptional(ApplicationMasterKeyring::class);
            if ($keyring instanceof ApplicationMasterKeyring) {
                $legacyKey = null;
                if (($this->config['publishing']['preview']['accept_legacy_application_secret_signatures'] ?? false) === true) {
                    $legacyKey = $this->applicationSecret()->derive(ApplicationSecret::PURPOSE_PUBLISHING_PREVIEW_HMAC);
                }

                return PreviewLinkService::fromApplicationMasterKeyring($keyring, $legacyKey);
            }

            return new PreviewLinkService(
                $this->applicationSecret()->derive(ApplicationSecret::PURPOSE_PUBLISHING_PREVIEW_HMAC),
            );
        });
    }

    public function applicationMasterRekeyContributions(): iterable
    {
        $database = $this->resolve(DatabaseInterface::class);
        if (!$database instanceof DatabaseInterface) {
            throw new \LogicException('Publishing preview rekey composition requires the kernel database authority.');
        }

        yield new ApplicationMasterRekeyContribution(
            new PublishingPreviewRekeyAdapter($database),
            [new ApplicationMasterPurposePolicy(
                ApplicationSecret::PURPOSE_PUBLISHING_PREVIEW_HMAC,
                'waaseyaa/publishing',
                ApplicationMasterPurposeStrategy::EphemeralNoPersistence,
                PreviewLinkService::DEFAULT_TTL_SECONDS,
                PreviewLinkService::DEFAULT_TTL_SECONDS,
                PublishingPreviewRekeyAdapter::ID,
                'verify-declared-version-until-expiry',
            )],
        );
    }

    private function applicationSecret(): ApplicationSecret
    {
        $applicationSecret = $this->resolve(ApplicationSecret::class);
        if (!$applicationSecret instanceof ApplicationSecret) {
            throw new \LogicException('Publishing preview legacy custody requires the application secret authority.');
        }

        return $applicationSecret;
    }
}
