<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Seo\SeoServiceProvider;

#[CoversClass(SeoServiceProvider::class)]
final class SeoServiceProviderTest extends TestCase
{
    #[Test]
    public function bootAttachesSeoFunctionsToTheProductionTwigEnvironment(): void
    {
        $twig = new Environment(new ArrayLoader());
        $provider = new SeoServiceProvider();
        $provider->setKernelServices(new class ($twig) implements KernelServicesInterface {
            public function __construct(private readonly Environment $twig) {}

            public function get(string $abstract): ?object
            {
                return $abstract === Environment::class ? $this->twig : null;
            }
        });
        $provider->register();

        $provider->boot();

        self::assertNotNull($twig->getFunction('seo_meta_head'));
    }
}
