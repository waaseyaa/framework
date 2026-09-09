<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\PermissionListHandler;
use Waaseyaa\CLI\Handler\UserAssignRoleHandler;
use Waaseyaa\CLI\Handler\UserCreateHandler;
use Waaseyaa\CLI\Handler\UserProvisionRegisteredHandler;
use Waaseyaa\CLI\Handler\UserRoleHandler;
use Waaseyaa\CLI\UserProvisioning\RegisteredRoleAccountProvisioner;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\RegisteredRoleAssignmentService;
use Waaseyaa\User\RoleRepository;

final class UserPermissionServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void
    {
        // The optional Closure clock is a deterministic-test seam, not a
        // container service. Construct the public command's provisioner from
        // the kernel's already validated role registry and audited user-field
        // services instead of reflection-autowiring that private seam.
        $this->singleton(RegisteredRoleAccountProvisioner::class, fn(): RegisteredRoleAccountProvisioner => new RegisteredRoleAccountProvisioner(
            new RegisteredRoleAssignmentService($this->resolve(RoleRepository::class)),
            $this->resolve(UserIdentityLookupInterface::class),
            $this->resolve(UserInternalFieldReaderInterface::class),
        ));
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'user:create',
            description: 'Create a new user account',
            arguments: [
                new HandlerArgument(
                    name: 'username',
                    mode: HandlerArgumentMode::Required,
                    description: 'The username for the new account',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'email',
                    mode: HandlerOptionMode::Required,
                    description: 'Email address for the user',
                ),
                new HandlerOption(
                    name: 'password',
                    mode: HandlerOptionMode::Required,
                    description: 'Password for the user (will be hashed)',
                ),
                new HandlerOption(
                    name: 'role',
                    mode: HandlerOptionMode::Required,
                    description: 'Role to assign to the user',
                ),
            ],
            handler: [UserCreateHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'user:role',
            description: 'Add or remove a role from a user',
            arguments: [
                new HandlerArgument(
                    name: 'user_id',
                    mode: HandlerArgumentMode::Required,
                    description: 'The user ID',
                ),
                new HandlerArgument(
                    name: 'role',
                    mode: HandlerArgumentMode::Required,
                    description: 'The role to add or remove',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'remove',
                    mode: HandlerOptionMode::None,
                    description: 'Remove the role instead of adding it',
                ),
            ],
            handler: [UserRoleHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'user:assign-role',
            description: 'Assign a registered role to a user and stamp its permissions onto the account',
            arguments: [
                new HandlerArgument(
                    name: 'user_id',
                    mode: HandlerArgumentMode::Required,
                    description: 'The user ID',
                ),
                new HandlerArgument(
                    name: 'role',
                    mode: HandlerArgumentMode::Required,
                    description: 'The registered role id to assign or remove',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'remove',
                    mode: HandlerOptionMode::None,
                    description: 'Remove the role and recompute permissions instead of assigning it',
                ),
            ],
            handler: [UserAssignRoleHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'user:provision-registered',
            description: 'Provision or verify one account and registered role from a private stdin document',
            handler: [UserProvisionRegisteredHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'permission:list',
            description: 'List all registered permissions',
            handler: [PermissionListHandler::class, 'execute'],
        );
    }
}
