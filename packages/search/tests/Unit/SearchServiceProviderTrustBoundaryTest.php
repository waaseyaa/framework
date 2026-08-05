<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Search\Access\EntitySearchAccessChecker;
use Waaseyaa\Search\Access\SearchAccessChecker;
use Waaseyaa\Search\SearchServiceProvider;

#[CoversClass(SearchServiceProvider::class)]
final class SearchServiceProviderTrustBoundaryTest extends TestCase
{
    #[Test]
    public function production_wiring_resolves_the_fail_closed_entity_checker(): void
    {
        $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
        $accessHandler = new EntityAccessHandler([]);
        $accountContext = new RequestAccountContext();
        $services = new class ($entityTypeManager, $accessHandler, $accountContext) implements KernelServicesInterface {
            public function __construct(
                private readonly EntityTypeManagerInterface $entityTypeManager,
                private readonly EntityAccessHandler $accessHandler,
                private readonly AccountContextInterface $accountContext,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    EntityTypeManagerInterface::class => $this->entityTypeManager,
                    EntityAccessHandler::class => $this->accessHandler,
                    AccountContextInterface::class => $this->accountContext,
                    default => null,
                };
            }
        };

        $provider = new SearchServiceProvider();
        $provider->setKernelServices($services);
        $provider->register();

        self::assertInstanceOf(
            EntitySearchAccessChecker::class,
            $provider->resolve(SearchAccessChecker::class),
        );
    }
}
