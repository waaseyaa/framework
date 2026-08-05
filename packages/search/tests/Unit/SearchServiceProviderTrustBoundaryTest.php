<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchCandidateResolverRegistry;
use Waaseyaa\Search\SearchServiceProvider;

#[CoversClass(SearchServiceProvider::class)]
final class SearchServiceProviderTrustBoundaryTest extends TestCase
{
    #[Test]
    public function production_wiring_resolves_the_fail_closed_candidate_registry(): void
    {
        $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
        $accessHandler = new EntityAccessHandler([]);
        $fieldReadScope = new AccountFieldReadScope();
        $services = new class ($entityTypeManager, $accessHandler, $fieldReadScope) implements KernelServicesInterface {
            public function __construct(
                private readonly EntityTypeManagerInterface $entityTypeManager,
                private readonly EntityAccessHandler $accessHandler,
                private readonly AccountFieldReadScopeInterface $fieldReadScope,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    EntityTypeManagerInterface::class => $this->entityTypeManager,
                    EntityAccessHandler::class => $this->accessHandler,
                    AccountFieldReadScopeInterface::class => $this->fieldReadScope,
                    default => null,
                };
            }
        };

        $provider = new SearchServiceProvider();
        $provider->setKernelServices($services);
        $provider->register();

        self::assertInstanceOf(
            SearchCandidateResolverRegistry::class,
            $provider->resolve(SearchCandidateResolverInterface::class),
        );
    }
}
