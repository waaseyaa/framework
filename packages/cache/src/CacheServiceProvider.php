<?php

declare(strict_types=1);

namespace Waaseyaa\Cache;

use Waaseyaa\Cache\Rekey\CacheGenerationRekeyAdapter;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Production composition for the installed database-cache generation owner. @api */
final class CacheServiceProvider extends ServiceProvider implements ProvidesApplicationMasterRekeyContributionsInterface
{
    public function register(): void {}

    public function applicationMasterRekeyContributions(): iterable
    {
        $database = $this->resolve(DatabaseInterface::class);
        if (!$database instanceof DatabaseInterface) {
            throw new \LogicException('Cache rekey composition requires the kernel database authority.');
        }

        yield new ApplicationMasterRekeyContribution(
            new CacheGenerationRekeyAdapter($database),
            [new ApplicationMasterPurposePolicy(
                id: ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC,
                ownerPackage: 'waaseyaa/cache',
                strategy: ApplicationMasterPurposeStrategy::InvalidateRebuildable,
                maximumLifetimeSeconds: 0,
                retentionSeconds: 0,
                adapterId: 'cache-generation-v1',
                rollbackBehavior: 'advance-generation',
            )],
        );
    }
}
