<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Extension;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Event\AuthLifecycleEvent;
use Waaseyaa\Auth\Extension\AuthExtensionConflictException;
use Waaseyaa\Auth\Extension\AuthExtensionContribution;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Extension\AuthMailContentPolicyInterface;
use Waaseyaa\Auth\Extension\AuthMailContext;
use Waaseyaa\Auth\Extension\AuthRedirect;
use Waaseyaa\Auth\Extension\AuthRedirectContext;
use Waaseyaa\Auth\Extension\AuthRedirectPolicyInterface;
use Waaseyaa\Auth\Extension\InitialRolePolicyInterface;
use Waaseyaa\Auth\Extension\ProvidesAuthExtensionsInterface;
use Waaseyaa\Auth\Extension\RegisteredUserReference;
use Waaseyaa\Auth\Extension\RegistrationContext;
use Waaseyaa\Auth\Extension\RegistrationDecision;
use Waaseyaa\Auth\Extension\RegistrationPolicyInterface;
use Waaseyaa\Auth\Extension\RegistrationProfileHandlerInterface;
use Waaseyaa\Auth\Extension\RegistrationProfileValidationException;
use Waaseyaa\Auth\Extension\ValidatedRegistrationProfile;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\AuthMailPresentation;
use Waaseyaa\User\Role;
use Waaseyaa\User\RoleRepository;
use Waaseyaa\User\User;

#[CoversClass(AuthExtensionRegistry::class)]
#[CoversClass(AuthExtensionContribution::class)]
#[CoversClass(AuthLifecycleEvent::class)]
#[CoversClass(AuthMailContext::class)]
#[CoversClass(AuthRedirect::class)]
#[CoversClass(AuthRedirectContext::class)]
#[CoversClass(RegisteredUserReference::class)]
#[CoversClass(RegistrationContext::class)]
#[CoversClass(RegistrationDecision::class)]
#[CoversClass(RegistrationProfileValidationException::class)]
#[CoversClass(ValidatedRegistrationProfile::class)]
final class AuthExtensionRegistryTest extends TestCase
{
    #[Test]
    public function distinct_slots_compose_in_provider_order_and_lifecycle_uses_existing_dispatcher(): void
    {
        $registration = new class implements RegistrationPolicyInterface {
            public function decide(RegistrationContext $context): RegistrationDecision
            {
                Assert::assertSame(['Alice', 'alice@example.com', 'open'], [$context->name, $context->mail, $context->configuredMode]);
                Assert::assertFalse(property_exists($context, 'password'));

                return RegistrationDecision::requireApproval();
            }
        };
        $redirect = new class implements AuthRedirectPolicyInterface {
            public function redirect(AuthRedirectContext $context): AuthRedirect
            {
                return new AuthRedirect('/after-' . $context->action);
            }
        };
        $providers = [
            new class extends ServiceProvider {
                public function register(): void {}
            },
            $this->provider(new AuthExtensionContribution(registration: $registration)),
            $this->provider(new AuthExtensionContribution(redirect: $redirect)),
        ];
        $events = new SymfonyEventDispatcherAdapter();
        $received = [];
        $events->addListener(AuthLifecycleEvent::NAME, static function (AuthLifecycleEvent $event) use (&$received): void {
            $received[] = $event->getPayload();
        });

        $registry = AuthExtensionRegistry::fromProviders($providers, new RoleRepository(), $events);

        self::assertTrue($registry->registration(new RegistrationContext('Alice', 'alice@example.com', 'open'))->requiresApproval);
        self::assertSame('/after-login', $registry->redirect('login', '7')->path);
        self::assertCount(2, $registry->owners());
        $registry->dispatch('login_succeeded', '7');
        self::assertSame([['action' => 'login_succeeded', 'disposition' => []]], $received);
    }

    #[Test]
    public function denial_and_unknown_lifecycle_actions_are_fail_closed(): void
    {
        $denial = RegistrationDecision::deny();
        self::assertFalse($denial->allowed);
        self::assertFalse($denial->requiresApproval);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown auth lifecycle action "credential_checked".');
        new AuthLifecycleEvent('7', 'credential_checked');
    }

    #[Test]
    public function a_duplicate_exclusive_slot_fails_with_both_provider_classes(): void
    {
        $policy = new class implements RegistrationPolicyInterface {
            public function decide(RegistrationContext $context): RegistrationDecision
            {
                return RegistrationDecision::allow();
            }
        };
        $first = $this->provider(new AuthExtensionContribution(registration: $policy));
        $second = $this->provider(new AuthExtensionContribution(registration: $policy));

        $this->expectException(AuthExtensionConflictException::class);
        $this->expectExceptionMessage('slot "registration" has conflicting providers');
        $this->expectExceptionMessage($first::class);
        $this->expectExceptionMessage($second::class);

        AuthExtensionRegistry::fromProviders([$first, $second], new RoleRepository());
    }

    #[Test]
    public function profile_input_without_a_handler_fails_closed(): void
    {
        $this->expectException(RegistrationProfileValidationException::class);
        AuthExtensionRegistry::defaults()->validateProfile(['community' => 'north']);
    }

    #[Test]
    public function profile_and_mail_policies_receive_typed_non_secret_context(): void
    {
        $stored = [];
        $profilePolicy = new class ($stored) implements RegistrationProfileHandlerInterface {
            /** @param array<string, mixed> $stored */
            public function __construct(private array &$stored) {}

            public function validate(array $profile): ValidatedRegistrationProfile
            {
                Assert::assertSame(['community' => 'North'], $profile);

                return new ValidatedRegistrationProfile(['community' => 'North']);
            }

            public function store(RegisteredUserReference $user, ValidatedRegistrationProfile $profile): void
            {
                $this->stored = [$user->userId, $user->name, $user->approvalRequired, $profile->values];
            }
        };
        $mailPolicy = new class implements AuthMailContentPolicyInterface {
            public function presentation(AuthMailContext $context): AuthMailPresentation
            {
                Assert::assertSame(['password_reset', '7'], [$context->kind, $context->userId]);
                Assert::assertFalse(property_exists($context, 'token'));

                return new AuthMailPresentation('Consumer reset', 'app/reset.html.twig', 'app/reset.txt.twig', ['brand' => 'Consumer']);
            }
        };
        $registry = AuthExtensionRegistry::fromProviders([
            $this->provider(new AuthExtensionContribution(profile: $profilePolicy, mail: $mailPolicy)),
        ], new RoleRepository());

        self::assertNull($registry->validateProfile(null));
        self::assertNull($registry->validateProfile([]));
        $validated = $registry->validateProfile(['community' => 'North']);
        self::assertNotNull($validated);
        $registry->storeProfile(new RegisteredUserReference('7', 'Alice', true), $validated);
        self::assertSame(['7', 'Alice', true, ['community' => 'North']], $stored);
        self::assertSame('Consumer reset', $registry->mail('password_reset', '7')?->subject);
    }

    #[Test]
    public function defaults_are_safe_and_malformed_profile_shapes_are_rejected(): void
    {
        $registry = AuthExtensionRegistry::defaults();

        self::assertTrue($registry->registration(new RegistrationContext('Alice', 'alice@example.test', 'open'))->allowed);
        self::assertSame('/admin', $registry->redirect('registration', null)->path);
        self::assertSame('/admin', $registry->redirect('login', '7')->path);
        self::assertSame('/', $registry->redirect('logout', '7')->path);
        self::assertSame('/login', $registry->redirect('verification', '7')->path);
        self::assertNull($registry->mail('welcome', '7'));
        $registry->applyInitialRoles(new User(['name' => 'Alice']), new RegisteredUserReference('7', 'Alice', false));
        $registry->storeProfile(new RegisteredUserReference('7', 'Alice', false), null);
        $registry->dispatch('login_succeeded', '7');

        try {
            $registry->validateProfile('not-an-object');
            self::fail('Scalar profile input should fail closed.');
        } catch (RegistrationProfileValidationException $exception) {
            self::assertSame(['profile' => 'Profile must be an object.'], $exception->errors);
        }

        $this->expectException(RegistrationProfileValidationException::class);
        $registry->validateProfile(['list item']);
    }

    #[Test]
    public function redirects_and_mail_reserved_values_cannot_escape_framework_ownership(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuthRedirect('https://attacker.example/path');
    }

    #[Test]
    public function protocol_relative_and_control_character_redirects_fail_closed(): void
    {
        foreach (['//attacker.example/path', "/safe\nSet-Cookie: injected=1"] as $path) {
            try {
                new AuthRedirect($path);
                self::fail('Unsafe redirect should have been rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Auth redirect must be a same-origin absolute path.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function mail_policy_cannot_replace_canonical_token_variables(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuthMailPresentation('Subject', 'app/reset.html.twig', 'app/reset.txt.twig', [
            'reset_url' => 'https://attacker.example/',
        ]);
    }

    #[Test]
    public function initial_roles_expand_only_registry_known_permissions(): void
    {
        $rolePolicy = new class implements InitialRolePolicyInterface {
            public function roles(RegisteredUserReference $user): array
            {
                return ['member'];
            }
        };
        $registry = AuthExtensionRegistry::fromProviders(
            [$this->provider(new AuthExtensionContribution(initialRoles: $rolePolicy))],
            new RoleRepository([new Role('member', 'Member', ['view dashboard', 'edit own profile'])]),
        );
        $user = new User(['name' => 'Alice']);

        $registry->applyInitialRoles($user, new RegisteredUserReference('7', 'Alice', false));

        $authorization = new UserInternalFieldReaderFixture()->maintenanceAuthorization($user);
        self::assertSame(['member'], $authorization->roles);
        self::assertSame(['view dashboard', 'edit own profile'], $authorization->permissions);
    }

    #[Test]
    public function unknown_initial_role_fails_closed(): void
    {
        $rolePolicy = new class implements InitialRolePolicyInterface {
            public function roles(RegisteredUserReference $user): array
            {
                return ['invented'];
            }
        };
        $registry = AuthExtensionRegistry::fromProviders(
            [$this->provider(new AuthExtensionContribution(initialRoles: $rolePolicy))],
            new RoleRepository(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unknown registered role "invented"');
        $registry->applyInitialRoles(new User(['name' => 'Alice']), new RegisteredUserReference('7', 'Alice', false));
    }

    #[Test]
    public function duplicate_and_empty_initial_roles_fail_closed(): void
    {
        foreach ([['member', 'member'], ['']] as $roleIds) {
            $rolePolicy = new class ($roleIds) implements InitialRolePolicyInterface {
                /** @param list<string> $roleIds */
                public function __construct(private readonly array $roleIds) {}

                public function roles(RegisteredUserReference $user): array
                {
                    return $this->roleIds;
                }
            };
            $registry = AuthExtensionRegistry::fromProviders(
                [$this->provider(new AuthExtensionContribution(initialRoles: $rolePolicy))],
                new RoleRepository([new Role('member', 'Member')]),
            );

            try {
                $registry->applyInitialRoles(new User(['name' => 'Alice']), new RegisteredUserReference('7', 'Alice', false));
                self::fail('Invalid initial role output should have failed closed.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('Initial-role policy', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function unknown_default_redirect_action_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unreachable auth redirect action.');
        AuthExtensionRegistry::defaults()->redirect('not-an-auth-action', null);
    }

    private function provider(AuthExtensionContribution $contribution): ServiceProvider&ProvidesAuthExtensionsInterface
    {
        return new class ($contribution) extends ServiceProvider implements ProvidesAuthExtensionsInterface {
            public function __construct(private readonly AuthExtensionContribution $contribution) {}

            public function register(): void {}

            public function authExtensions(): AuthExtensionContribution
            {
                return $this->contribution;
            }
        };
    }
}
