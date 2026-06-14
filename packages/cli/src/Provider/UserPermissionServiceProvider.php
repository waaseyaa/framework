<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\ArgumentDefinition;
use Waaseyaa\CLI\ArgumentMode;
use Waaseyaa\CLI\CommandDefinition;
use Waaseyaa\CLI\Handler\PermissionListHandler;
use Waaseyaa\CLI\Handler\UserAssignRoleHandler;
use Waaseyaa\CLI\Handler\UserCreateHandler;
use Waaseyaa\CLI\Handler\UserRoleHandler;
use Waaseyaa\CLI\OptionDefinition;
use Waaseyaa\CLI\OptionMode;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class UserPermissionServiceProvider extends ServiceProvider implements HasNativeCommandsInterface
{
    public function register(): void {}

    public function nativeCommands(): iterable
    {
        yield new CommandDefinition(
            name: 'user:create',
            description: 'Create a new user account',
            arguments: [
                new ArgumentDefinition(
                    name: 'username',
                    mode: ArgumentMode::Required,
                    description: 'The username for the new account',
                ),
            ],
            options: [
                new OptionDefinition(
                    name: 'email',
                    mode: OptionMode::Required,
                    description: 'Email address for the user',
                ),
                new OptionDefinition(
                    name: 'password',
                    mode: OptionMode::Required,
                    description: 'Password for the user (will be hashed)',
                ),
                new OptionDefinition(
                    name: 'role',
                    mode: OptionMode::Required,
                    description: 'Role to assign to the user',
                ),
            ],
            handler: [UserCreateHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'user:role',
            description: 'Add or remove a role from a user',
            arguments: [
                new ArgumentDefinition(
                    name: 'user_id',
                    mode: ArgumentMode::Required,
                    description: 'The user ID',
                ),
                new ArgumentDefinition(
                    name: 'role',
                    mode: ArgumentMode::Required,
                    description: 'The role to add or remove',
                ),
            ],
            options: [
                new OptionDefinition(
                    name: 'remove',
                    mode: OptionMode::None,
                    description: 'Remove the role instead of adding it',
                ),
            ],
            handler: [UserRoleHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'user:assign-role',
            description: 'Assign a registered role to a user and stamp its permissions onto the account',
            arguments: [
                new ArgumentDefinition(
                    name: 'user_id',
                    mode: ArgumentMode::Required,
                    description: 'The user ID',
                ),
                new ArgumentDefinition(
                    name: 'role',
                    mode: ArgumentMode::Required,
                    description: 'The registered role id to assign or remove',
                ),
            ],
            options: [
                new OptionDefinition(
                    name: 'remove',
                    mode: OptionMode::None,
                    description: 'Remove the role and recompute permissions instead of assigning it',
                ),
            ],
            handler: [UserAssignRoleHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'permission:list',
            description: 'List all registered permissions',
            handler: [PermissionListHandler::class, 'execute'],
        );
    }
}
