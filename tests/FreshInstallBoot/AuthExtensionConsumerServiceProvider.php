<?php

declare(strict_types=1);

namespace App\Provider;

use Waaseyaa\Auth\Event\AuthLifecycleEvent;
use Waaseyaa\Auth\Extension\AuthExtensionContribution;
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
use Waaseyaa\Auth\Extension\ValidatedRegistrationProfile;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\AuthMailPresentation;
use Waaseyaa\User\Role;

/** Executable downstream example: every supported auth extension slot. */
final class AuthExtensionConsumerServiceProvider extends ServiceProvider implements ProvidesAuthExtensionsInterface, ProvidesRolesInterface
{
    /** @var array<string, mixed>|null */
    public static ?array $storedProfile = null;

    public static int $lifecycleEvents = 0;

    public function register(): void
    {
        $events = $this->resolve(EventDispatcherInterface::class);
        assert($events instanceof EventDispatcherInterface);
        $events->addListener(AuthLifecycleEvent::NAME, static function (AuthLifecycleEvent $event): void {
            ++self::$lifecycleEvents;
        });
    }

    public function authExtensions(): AuthExtensionContribution
    {
        return new AuthExtensionContribution(
            registration: new class implements RegistrationPolicyInterface {
                public function decide(RegistrationContext $context): RegistrationDecision
                {
                    return str_ends_with($context->mail, '@example.test')
                        ? RegistrationDecision::requireApproval()
                        : RegistrationDecision::deny();
                }
            },
            profile: new class implements RegistrationProfileHandlerInterface {
                public function validate(array $profile): ValidatedRegistrationProfile
                {
                    $community = $profile['community'] ?? null;
                    if (!is_string($community) || trim($community) === '') {
                        throw new \Waaseyaa\Auth\Extension\RegistrationProfileValidationException([
                            'profile.community' => 'Community is required.',
                        ]);
                    }

                    return new ValidatedRegistrationProfile(['community' => trim($community)]);
                }

                public function store(RegisteredUserReference $user, ValidatedRegistrationProfile $profile): void
                {
                    AuthExtensionConsumerServiceProvider::$storedProfile = [
                        'user_id' => $user->userId,
                        ...$profile->values,
                    ];
                }
            },
            redirect: new class implements AuthRedirectPolicyInterface {
                public function redirect(AuthRedirectContext $context): AuthRedirect
                {
                    return new AuthRedirect('/consumer-' . $context->action);
                }
            },
            mail: new class implements AuthMailContentPolicyInterface {
                public function presentation(AuthMailContext $context): AuthMailPresentation
                {
                    return new AuthMailPresentation(
                        'Consumer ' . $context->kind,
                        'consumer/auth.html.twig',
                        'consumer/auth.txt.twig',
                        ['brand_name' => 'Consumer'],
                    );
                }
            },
            initialRoles: new class implements InitialRolePolicyInterface {
                public function roles(RegisteredUserReference $user): array
                {
                    return ['member'];
                }
            },
        );
    }

    public function roles(): iterable
    {
        yield new Role('member', 'Member', ['access consumer dashboard']);
    }
}
