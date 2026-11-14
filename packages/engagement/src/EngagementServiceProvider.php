<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Engagement\Command\EngagementUpgradeCommand;
use Waaseyaa\Engagement\Migration\EngagementDataUpgrader;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class EngagementServiceProvider extends ServiceProvider implements ProvidesApplicationMasterRekeyContributionsInterface, ProvidesConsoleCommandsInterface
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(Reaction::class, group: 'engagement'));
        $this->entityType(EntityType::fromClass(Comment::class, group: 'engagement'));
        $this->entityType(EntityType::fromClass(Follow::class, group: 'engagement'));
        $this->singleton(EngagementMutationScope::class, fn(): EngagementMutationScope => new EngagementMutationScope(
            $this->required(AccountContextInterface::class),
            $this->required(AccountFieldReadScopeInterface::class),
            fn(): EntityAccessHandler => $this->required(EntityAccessHandler::class),
        ));
        $this->singleton(EngagementAccess::class, fn(): EngagementAccess => new EngagementAccess(
            $this->database(),
            $this->required(EntityTypeManagerInterface::class),
            $this->required(EntityAccessHandler::class),
            $this->required(EngagementPolicyInterface::class),
        ));
        $this->singleton(EngagementMutationInvariant::class, fn(): EngagementMutationInvariant => new EngagementMutationInvariant(
            $this->database(),
            fn(): EngagementAccess => $this->required(EngagementAccess::class),
            $this->required(EngagementMutationScope::class),
        ));
        $this->singleton(EngagementTokens::class, fn(): EngagementTokens => new EngagementTokens(
            $this->required(ApplicationMasterKeyring::class),
            $this->installation(),
        ));
        $this->singleton(EngagementRateLimiter::class, fn(): EngagementRateLimiter => new EngagementRateLimiter(
            $this->database(),
            $this->installation(),
            $this->config['engagement']['lock_directory'] ?? $this->projectRoot . '/storage/engagement-locks',
            $this->config['engagement']['limits'] ?? [],
        ));
        $this->singleton(EngagementService::class, fn(): EngagementService => new EngagementService(
            $this->database(),
            $this->required(EntityTypeManagerInterface::class),
            $this->required(EngagementAccess::class),
            $this->required(EngagementMutationScope::class),
            $this->required(EngagementTokens::class),
            $this->required(EngagementRateLimiter::class),
            $this->required(AccountFieldReadScopeInterface::class),
            $this->resolveOptional(AuditWriterInterface::class),
        ));
        $this->singleton(EngagementRetention::class, fn(): EngagementRetention => new EngagementRetention(
            $this->database(),
            $this->required(EntityTypeManagerInterface::class),
            $this->required(EngagementMutationScope::class),
        ));
        $this->singleton(EngagementDataUpgrader::class, fn(): EngagementDataUpgrader => new EngagementDataUpgrader(
            $this->database(),
            $this->required(EntityTypeManagerInterface::class),
            $this->required(EngagementMutationScope::class),
        ));
        $this->singleton(EngagementUpgradeCommand::class, fn(): EngagementUpgradeCommand => new EngagementUpgradeCommand(
            $this->database(),
            $this->required(EntityTypeManagerInterface::class),
            $this->required(EngagementMutationScope::class),
        ));
    }

    public function boot(): void
    {
        $this->required(\Waaseyaa\Foundation\Event\EventDispatcherInterface::class)->addListener(
            \Waaseyaa\Entity\Event\EntityEvents::PRE_DELETE->value,
            function (\Waaseyaa\Entity\Event\EntityEvent $event): void {
                $this->required(EngagementRetention::class)->queueDeletion($event->entity);
            },
        );
    }

    public function consoleCommands(): iterable
    {
        yield EngagementUpgradeCommand::class;
    }

    public function applicationMasterRekeyContributions(): iterable
    {
        yield from EngagementTokens::rekeyContributions($this->database());
    }

    private function database(): DBALDatabase
    {
        $database = $this->required(DatabaseInterface::class);
        if (!$database instanceof DBALDatabase) {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Engagement requires its canonical S1 SQLite database.');
        }
        return $database;
    }

    private function installation(): string
    {
        $value = $this->config['engagement']['installation_id'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Configure the engagement installation identity.');
        }
        return $value;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function required(string $class): object
    {
        $service = $this->resolve($class);
        if (!$service instanceof $class) {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'An engagement dependency has an incompatible type.');
        }
        return $service;
    }
}
