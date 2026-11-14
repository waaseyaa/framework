<?php

declare(strict_types=1);

namespace EngagementConsumer;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Engagement\EngagementPolicyInterface;
use Waaseyaa\Engagement\EngagementTokens;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class EngagementConsumerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(SyntheticPost::class, group: 'content'));
        $this->singleton(SyntheticPostAccessPolicy::class, static fn(): SyntheticPostAccessPolicy => new SyntheticPostAccessPolicy());
        $resolver = $this->kernelServices?->get(SecretResolverRegistry::class);
        if (!$resolver instanceof SecretResolverRegistry) {
            throw new \RuntimeException('Synthetic engagement fixture requires the normal Foundation secret resolver.');
        }
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->registerProvider(new SyntheticMasterSecretProvider());
        $resolver->allow(
            'engagement-fixture-master',
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            [$resolver->environment()],
        );
        $this->singleton(ApplicationMasterKeyring::class, function (): ApplicationMasterKeyring {
            $database = $this->resolve(DatabaseInterface::class);
            $resolver = $this->kernelServices?->get(SecretResolverRegistry::class);
            if (!$database instanceof DBALDatabase || !$resolver instanceof SecretResolverRegistry) {
                throw new \RuntimeException('Synthetic engagement key custody requires canonical Foundation services.');
            }
            $purposes = new ApplicationMasterPurposeRegistry();
            foreach (EngagementTokens::rekeyContributions($database) as $contribution) {
                foreach ($contribution->policies() as $policy) {
                    $purposes->register($policy);
                }
            }
            $purposes->freeze();
            return ApplicationMasterKeyring::fromReferences(
                $resolver,
                1,
                SecretReference::create(
                    'engagement-fixture-master',
                    'synthetic-fixture-master-v1',
                    SecretClass::ApplicationMaster,
                    ApplicationMasterKeyring::MASTER_PURPOSE,
                ),
                [],
                $purposes,
            );
        });
        $this->singleton(EngagementPolicyInterface::class, function (): EngagementPolicyInterface {
            $database = $this->resolve(DatabaseInterface::class);
            if (!$database instanceof DBALDatabase) {
                throw new \RuntimeException('Synthetic engagement fixture requires canonical DBALDatabase.');
            }
            return new SyntheticEngagementPolicy($database);
        });
    }
}
