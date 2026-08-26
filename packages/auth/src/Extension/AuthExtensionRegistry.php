<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

use Waaseyaa\Auth\Event\AuthLifecycleEvent;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\AuthMailPresentation;
use Waaseyaa\User\RoleRepository;
use Waaseyaa\User\User;

/** Deterministic, fail-closed composition of consumer auth extension slots. @api */
final class AuthExtensionRegistry
{
    /** @param array<string, class-string> $owners */
    private function __construct(
        private readonly ?RegistrationPolicyInterface $registration,
        private readonly ?RegistrationProfileHandlerInterface $profile,
        private readonly ?AuthRedirectPolicyInterface $redirect,
        private readonly ?AuthMailContentPolicyInterface $mail,
        private readonly ?InitialRolePolicyInterface $initialRoles,
        private readonly RoleRepository $roles,
        private readonly ?EventDispatcherInterface $events,
        private readonly array $owners,
    ) {}

    public static function defaults(): self
    {
        return new self(null, null, null, null, null, new RoleRepository(), null, []);
    }

    /**
     * @param iterable<ServiceProvider> $providers
     */
    public static function fromProviders(
        iterable $providers,
        RoleRepository $roles,
        ?EventDispatcherInterface $events = null,
    ): self {
        $slots = [
            'registration' => null,
            'profile' => null,
            'redirect' => null,
            'mail' => null,
            'initial_roles' => null,
        ];
        $owners = [];

        foreach ($providers as $provider) {
            if (!$provider instanceof ProvidesAuthExtensionsInterface) {
                continue;
            }
            $contribution = $provider->authExtensions();
            foreach ([
                'registration' => $contribution->registration,
                'profile' => $contribution->profile,
                'redirect' => $contribution->redirect,
                'mail' => $contribution->mail,
                'initial_roles' => $contribution->initialRoles,
            ] as $slot => $policy) {
                if ($policy === null) {
                    continue;
                }
                if ($slots[$slot] !== null) {
                    throw new AuthExtensionConflictException(sprintf(
                        'Auth extension slot "%s" has conflicting providers %s and %s; retain exactly one owner.',
                        $slot,
                        $owners[$slot],
                        $provider::class,
                    ));
                }
                $slots[$slot] = $policy;
                $owners[$slot] = $provider::class;
            }
        }

        return new self(
            $slots['registration'],
            $slots['profile'],
            $slots['redirect'],
            $slots['mail'],
            $slots['initial_roles'],
            $roles,
            $events,
            $owners,
        );
    }

    public function registration(RegistrationContext $context): RegistrationDecision
    {
        return $this->registration?->decide($context) ?? RegistrationDecision::allow();
    }

    /** @param mixed $profile */
    public function validateProfile(mixed $profile): ?ValidatedRegistrationProfile
    {
        if ($profile === null || $profile === []) {
            return null;
        }
        if (!is_array($profile) || array_is_list($profile)) {
            throw new RegistrationProfileValidationException(['profile' => 'Profile must be an object.']);
        }
        if ($this->profile === null) {
            throw new RegistrationProfileValidationException(['profile' => 'This application does not accept registration profile fields.']);
        }

        return $this->profile->validate($profile);
    }

    public function storeProfile(RegisteredUserReference $user, ?ValidatedRegistrationProfile $profile): void
    {
        if ($profile !== null) {
            assert($this->profile !== null);
            $this->profile->store($user, $profile);
        }
    }

    public function applyInitialRoles(User $user, RegisteredUserReference $reference): void
    {
        if ($this->initialRoles === null) {
            return;
        }
        $roleIds = $this->initialRoles->roles($reference);
        if (array_values(array_unique($roleIds)) !== $roleIds) {
            throw new \LogicException('Initial-role policy returned duplicate role ids.');
        }
        $permissions = [];
        foreach ($roleIds as $roleId) {
            if (trim($roleId) === '') {
                throw new \LogicException('Initial-role policy must return non-empty string ids.');
            }
            $role = $this->roles->get($roleId);
            if ($role === null) {
                throw new \LogicException(sprintf('Initial-role policy returned unknown registered role "%s".', $roleId));
            }
            foreach ($role->permissions as $permission) {
                $permissions[$permission] = true;
            }
        }
        $user->setRoles($roleIds);
        $user->setPermissions(array_keys($permissions));
    }

    public function redirect(string $action, ?string $userId): AuthRedirect
    {
        if ($this->redirect !== null) {
            return $this->redirect->redirect(new AuthRedirectContext($action, $userId));
        }

        return new AuthRedirect(match ($action) {
            'registration', 'login' => '/admin',
            'logout' => '/',
            'verification' => '/login',
            default => throw new \LogicException('Unreachable auth redirect action.'),
        });
    }

    public function mail(string $kind, ?string $userId): ?AuthMailPresentation
    {
        return $this->mail?->presentation(new AuthMailContext($kind, $userId));
    }

    /** @param array<string, bool|int|string|null> $disposition */
    public function dispatch(string $action, string $userId, array $disposition = []): void
    {
        $this->events?->dispatch(new AuthLifecycleEvent($userId, $action, $disposition), AuthLifecycleEvent::NAME);
    }

    /** @return array<string, class-string> */
    public function owners(): array
    {
        return $this->owners;
    }
}
