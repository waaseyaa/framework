<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry;
use Waaseyaa\Foundation\Kernel\Bootstrap\Exception\PolicyInstantiationException;
use Waaseyaa\Foundation\Kernel\Bootstrap\PolicyDependencyResolverInterface;
use Waaseyaa\Foundation\Log\NullLogger;

#[CoversClass(AccessPolicyRegistry::class)]
final class AccessPolicyRegistryTest extends TestCase
{
    #[Test]
    public function unresolvable_policy_dependency_throws_at_boot(): void
    {
        $resolver = new class implements PolicyDependencyResolverInterface {
            public function resolveParameter(string $policyClass, \ReflectionParameter $param, array $entityTypes): mixed
            {
                throw new PolicyInstantiationException(
                    sprintf('Cannot resolve %s for %s', $param->getName(), $policyClass),
                );
            }
        };

        // Register a policy class with a required constructor parameter.
        $policyClass = self::createUnresolvablePolicy();

        /** @var array<class-string, string[]> $policies */
        $policies = [$policyClass => ['test_entity']];
        $manifest = new PackageManifest(
            policies: $policies,
        );

        $registry = new AccessPolicyRegistry(new NullLogger(), $resolver);

        $this->expectException(PolicyInstantiationException::class);
        $registry->discover($manifest);
    }

    #[Test]
    public function policy_with_no_constructor_instantiates_successfully(): void
    {
        $policyClass = self::createNoDepsPolicy();

        $resolver = new class implements PolicyDependencyResolverInterface {
            public function resolveParameter(string $policyClass, \ReflectionParameter $param, array $entityTypes): mixed
            {
                throw new PolicyInstantiationException('Should not be called');
            }
        };

        /** @var array<class-string, string[]> $policies */
        $policies = [$policyClass => ['test_entity']];
        $manifest = new PackageManifest(
            policies: $policies,
        );

        $registry = new AccessPolicyRegistry(new NullLogger(), $resolver);
        $handler = $registry->discover($manifest);

        self::assertInstanceOf(EntityAccessHandler::class, $handler);
    }

    #[Test]
    public function policy_with_array_param_receives_entity_types(): void
    {
        $policyClass = self::createArrayParamPolicy();

        $resolver = new class implements PolicyDependencyResolverInterface {
            /** @var array<string> */
            public array $received = [];

            public function resolveParameter(string $policyClass, \ReflectionParameter $param, array $entityTypes): mixed
            {
                $type = $param->getType();
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                if ($typeName === 'array') {
                    $this->received = $entityTypes;

                    return $entityTypes;
                }
                throw new PolicyInstantiationException('Unexpected param');
            }
        };

        $entityTypes = ['node', 'taxonomy_term'];
        /** @var array<class-string, string[]> $policies */
        $policies = [$policyClass => $entityTypes];
        $manifest = new PackageManifest(
            policies: $policies,
        );

        $registry = new AccessPolicyRegistry(new NullLogger(), $resolver);
        $registry->discover($manifest);

        self::assertSame($entityTypes, $resolver->received);
    }

    #[Test]
    public function missing_policy_class_fails_boot_loudly(): void
    {
        $resolver = new class implements PolicyDependencyResolverInterface {
            public function resolveParameter(string $policyClass, \ReflectionParameter $param, array $entityTypes): mixed
            {
                throw new PolicyInstantiationException('Should not be called');
            }
        };

        // Use a class-string for a non-existent class (never loaded) to verify
        // the registry cannot silently weaken the access-policy set.
        $nonExistentClass = self::nonExistentPolicyClass();
        $manifest = new PackageManifest(
            policies: [$nonExistentClass => ['test_entity']],
        );

        $registry = new AccessPolicyRegistry(new NullLogger(), $resolver);
        $this->expectException(PolicyInstantiationException::class);
        $this->expectExceptionMessage('Access policy class not found');

        $registry->discover($manifest);
    }

    /**
     * Creates a named policy class with a required constructor parameter (no default, not nullable).
     *
     * @return class-string
     */
    private static function createUnresolvablePolicy(): string
    {
        $className = 'UnresolvableTestPolicy_' . uniqid();
        eval(sprintf(
            'final class %s implements %s {
                public function __construct(private readonly %s $dep) {}
                public function access(%s $entity, string $op, %s $account): %s {
                    return %s::neutral();
                }
                public function createAccess(string $entityTypeId, string $bundle, %s $account): %s {
                    return %s::neutral();
                }
                public function appliesTo(string $entityTypeId): bool { return true; }
            }',
            $className,
            AccessPolicyInterface::class,
            EntityAccessHandler::class,
            EntityInterface::class,
            AccountInterface::class,
            AccessResult::class,
            AccessResult::class,
            AccountInterface::class,
            AccessResult::class,
            AccessResult::class,
        ));

        return $className;
    }

    /**
     * Creates a named policy class with no constructor parameters.
     *
     * @return class-string
     */
    private static function createNoDepsPolicy(): string
    {
        $className = 'NoDepsTestPolicy_' . uniqid();
        eval(sprintf(
            'final class %s implements %s {
                public function access(%s $entity, string $op, %s $account): %s {
                    return %s::neutral();
                }
                public function createAccess(string $entityTypeId, string $bundle, %s $account): %s {
                    return %s::neutral();
                }
                public function appliesTo(string $entityTypeId): bool { return true; }
            }',
            $className,
            AccessPolicyInterface::class,
            EntityInterface::class,
            AccountInterface::class,
            AccessResult::class,
            AccessResult::class,
            AccountInterface::class,
            AccessResult::class,
            AccessResult::class,
        ));

        return $className;
    }

    /**
     * Creates a named policy class with an array constructor parameter.
     *
     * @return class-string
     */
    private static function createArrayParamPolicy(): string
    {
        $className = 'ArrayParamTestPolicy_' . uniqid();
        eval(sprintf(
            'final class %s implements %s {
                public function __construct(private readonly array $entityTypes) {}
                public function access(%s $entity, string $op, %s $account): %s {
                    return %s::neutral();
                }
                public function createAccess(string $entityTypeId, string $bundle, %s $account): %s {
                    return %s::neutral();
                }
                public function appliesTo(string $entityTypeId): bool { return true; }
            }',
            $className,
            AccessPolicyInterface::class,
            EntityInterface::class,
            AccountInterface::class,
            AccessResult::class,
            AccessResult::class,
            AccountInterface::class,
            AccessResult::class,
            AccessResult::class,
        ));

        return $className;
    }

    /**
     * Returns a class-string FQCN for a class that does not exist in the autoloader.
     * Used to verify the registry rejects missing classes.
     *
     * @return class-string
     */
    private static function nonExistentPolicyClass(): string
    {
        /** @var class-string */
        return 'Waaseyaa\\Tests\\NonExistent\\PhantomPolicy_' . uniqid();
    }
}
