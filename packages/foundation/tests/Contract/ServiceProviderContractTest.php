<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Contract;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
use Waaseyaa\Foundation\Http\LanguagePathStripperInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsMigrationProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ConfiguresHttpKernelInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasGraphqlMutationOverridesInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasRenderCacheListenersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\ServiceProviderInterface;

/**
 * Locks `ServiceProviderInterface`, abstract `ServiceProvider`, and the
 * kernel call sites in lockstep. Surface B of WP03 (#824).
 *
 * Reflection-only — DIR-008 permits reflection in contract tests.
 */
#[CoversNothing]
final class ServiceProviderContractTest extends TestCase
{
    /**
     * Methods declared on `ServiceProviderInterface`. Adding or removing one
     * is a breaking change for third-party providers — update this roster
     * deliberately, in the same WP that moves the interface.
     *
     * @var list<string>
     */
    private const array INTERFACE_METHODS = [
        'register',
        'boot',
        'routes',
        'provides',
        'isDeferred',
        'getBindings',
        'resolve',
        'setKernelContext',
        'setKernelServices',
        'getEntityTypeRegistrations',
    ];

    /**
     * Provider hooks the kernel calls that live on the abstract base only.
     *
     * The capability-interface split (mission #824 WP03 surfaces D–I) lifted
     * every former entry into `CAPABILITY_INTERFACES`. This list is now empty
     * and SHOULD stay empty: new kernel-invoked hooks must enter as capability
     * interfaces, never as no-op defaults on the abstract base.
     *
     * @var list<string>
     */
    private const array ABSTRACT_BASE_ONLY = [];

    /**
     * Provider methods invoked only after an `instanceof` guard against a
     * named capability interface. Each entry maps a method name to the
     * capability interface that gates it at the call site (kernel or, for
     * cross-package hooks like GraphQL, the owning subsystem's bootstrap).
     *
     * @var array<string, class-string>
     */
    private const array CAPABILITY_INTERFACES = [
        'withMigrationProviders' => AcceptsMigrationProvidersInterface::class,
        'stripLanguagePrefixForRouting' => LanguagePathStripperInterface::class,
        'graphqlMutationOverrides' => HasGraphqlMutationOverridesInterface::class,
        'consoleCommands' => ProvidesConsoleCommandsInterface::class,
        'registerRenderCacheListeners' => HasRenderCacheListenersInterface::class,
        'configureHttpKernel' => ConfiguresHttpKernelInterface::class,
        'middleware' => HasMiddlewareInterface::class,
        'httpDomainRouters' => HasHttpDomainRoutersInterface::class,
    ];

    #[Test]
    public function abstractBaseImplementsEveryInterfaceMethod(): void
    {
        $iface = new ReflectionClass(ServiceProviderInterface::class);
        $base = new ReflectionClass(ServiceProvider::class);

        self::assertTrue(
            $base->implementsInterface(ServiceProviderInterface::class),
            'Abstract ServiceProvider must implement ServiceProviderInterface.',
        );

        foreach ($iface->getMethods() as $method) {
            self::assertTrue(
                $base->hasMethod($method->getName()),
                sprintf(
                    'Abstract ServiceProvider must implement %s::%s().',
                    ServiceProviderInterface::class,
                    $method->getName(),
                ),
            );
        }
    }

    #[Test]
    public function interfaceMethodRosterMatchesDeclaration(): void
    {
        $iface = new ReflectionClass(ServiceProviderInterface::class);
        $declared = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            $iface->getMethods(),
        );
        sort($declared);

        $expected = self::INTERFACE_METHODS;
        sort($expected);

        self::assertSame(
            $expected,
            $declared,
            'ServiceProviderInterface drifted from the contract roster. '
                . 'Update self::INTERFACE_METHODS deliberately and audit kernel call sites.',
        );
    }

    #[Test]
    public function everyKernelCallSiteResolvesToInterfaceOrAbstractBase(): void
    {
        $invocations = $this->discoverProviderInvocations();
        $base = new ReflectionClass(ServiceProvider::class);

        foreach ($invocations as $method => $sites) {
            if (in_array($method, self::INTERFACE_METHODS, true)) {
                continue;
            }

            if (in_array($method, self::ABSTRACT_BASE_ONLY, true)) {
                self::assertTrue(
                    $base->hasMethod($method),
                    sprintf(
                        'Method %s() listed in ABSTRACT_BASE_ONLY but missing from %s.',
                        $method,
                        ServiceProvider::class,
                    ),
                );
                continue;
            }

            if (array_key_exists($method, self::CAPABILITY_INTERFACES)) {
                $capability = self::CAPABILITY_INTERFACES[$method];
                self::assertTrue(
                    interface_exists($capability),
                    sprintf(
                        'Capability interface %s for %s() does not exist.',
                        $capability,
                        $method,
                    ),
                );
                continue;
            }

            self::fail(sprintf(
                'Kernel calls $provider->%s() at [%s] but the method is not on '
                    . 'ServiceProviderInterface, the abstract ServiceProvider base, '
                    . 'or any capability interface in the allowlist. Add it to one of: '
                    . 'INTERFACE_METHODS, ABSTRACT_BASE_ONLY, or CAPABILITY_INTERFACES.',
                $method,
                implode(', ', $sites),
            ));
        }
    }

    #[Test]
    public function interfaceMethodsHaveNoOptionalParameters(): void
    {
        // DIR-003: "No optional parameters that change behavior — use
        // capability objects or separate interfaces."
        $iface = new ReflectionClass(ServiceProviderInterface::class);
        foreach ($iface->getMethods() as $method) {
            foreach ($method->getParameters() as $param) {
                self::assertFalse(
                    $param->isOptional(),
                    sprintf(
                        '%s::%s() has optional parameter $%s — DIR-003 forbids '
                            . 'optional parameters that change behavior.',
                        ServiceProviderInterface::class,
                        $method->getName(),
                        $param->getName(),
                    ),
                );
            }
        }
    }

    #[Test]
    public function interfaceMethodsDeclareConcreteReturnTypes(): void
    {
        $iface = new ReflectionClass(ServiceProviderInterface::class);
        foreach ($iface->getMethods() as $method) {
            self::assertTrue(
                $method->hasReturnType(),
                sprintf(
                    '%s::%s() is missing an explicit return type.',
                    ServiceProviderInterface::class,
                    $method->getName(),
                ),
            );

            $returnType = $method->getReturnType();
            self::assertInstanceOf(
                ReflectionNamedType::class,
                $returnType,
                sprintf(
                    '%s::%s() return type must be a single named type, '
                        . 'not a union or intersection.',
                    ServiceProviderInterface::class,
                    $method->getName(),
                ),
            );

            self::assertNotSame(
                'mixed',
                $returnType->getName(),
                sprintf(
                    '%s::%s() returns mixed — tighten to a concrete type (DIR-005).',
                    ServiceProviderInterface::class,
                    $method->getName(),
                ),
            );
        }
    }

    /**
     * Scan kernel source for `$provider->methodName(` invocations.
     *
     * @return array<string, list<string>> map of method name → relative paths
     */
    private function discoverProviderInvocations(): array
    {
        $kernelDir = dirname(__DIR__, 2) . '/src/Kernel';
        self::assertDirectoryExists($kernelDir, 'Foundation kernel source dir not found.');

        $invocations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($kernelDir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($entry->getPathname());
            if ($contents === false) {
                continue;
            }

            $found = preg_match_all('/\$provider->(\w+)\s*\(/', $contents, $matches);
            if ($found === false || $found === 0) {
                continue;
            }

            $relative = 'Kernel' . substr($entry->getPathname(), strlen($kernelDir));
            foreach ($matches[1] as $method) {
                $invocations[$method] ??= [];
                if (!in_array($relative, $invocations[$method], true)) {
                    $invocations[$method][] = $relative;
                }
            }
        }

        self::assertNotEmpty(
            $invocations,
            'Found no $provider->...() invocations in kernel — scan is broken.',
        );

        return $invocations;
    }
}
