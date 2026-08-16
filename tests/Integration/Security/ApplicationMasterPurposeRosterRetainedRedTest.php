<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\AuditServiceProvider;
use Waaseyaa\Cache\CacheServiceProvider;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyComposition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Oidc\OidcServiceProvider;
use Waaseyaa\Publishing\PublishingServiceProvider;
use Waaseyaa\Queue\QueueServiceProvider;

/** Retained-red closure proof for every declared application-master purpose owner. */
final class ApplicationMasterPurposeRosterRetainedRedTest extends TestCase
{
    #[Test]
    public function every_declared_purpose_has_exactly_one_real_package_owner(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $services = new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        };
        $providers = [
            new AuditServiceProvider(),
            new CacheServiceProvider(),
            new OidcServiceProvider(),
            new PublishingServiceProvider(),
            new QueueServiceProvider(),
        ];
        foreach ($providers as $provider) {
            self::assertInstanceOf(ServiceProvider::class, $provider);
            $provider->setKernelContext('', ['queue' => ['driver' => 'database']], []);
            $provider->setKernelServices($services);
        }

        $composition = ApplicationMasterRekeyComposition::fromProviders($database, $providers);
        self::assertNotNull($composition);
        $owned = $composition->purposes()->purposeIds();
        $declared = array_values(array_filter(
            new \ReflectionClass(ApplicationSecret::class)->getConstants(),
            static fn(mixed $value, string $name): bool => str_starts_with($name, 'PURPOSE_') && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        ));
        sort($declared, SORT_STRING);

        self::assertSame(
            $declared,
            $owned,
            'Every ApplicationSecret purpose constant must have exactly one real package contribution.',
        );
    }
}
