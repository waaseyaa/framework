<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\GroupsContentAssignHandler;
use Waaseyaa\CLI\Handler\GroupsContentUnassignHandler;
use Waaseyaa\CLI\Handler\GroupsCreateHandler;
use Waaseyaa\CLI\Handler\GroupsMemberAddHandler;
use Waaseyaa\CLI\Handler\GroupsMemberRemoveHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the `groups:*` operator CLI commands (CW-v1 WP-4, #1920, design
 * decision 7): a minimal membership write surface so department routing
 * (WP-3's group constraint) is operable end-to-end.
 *
 * Named `GroupsCliServiceProvider`, not `GroupsServiceProvider`, to avoid a
 * same-basename collision with {@see \Waaseyaa\Groups\GroupsServiceProvider}
 * (different namespaces, so not a PHP fatal — this is purely for
 * readability, per the WP-4 plan's naming note).
 *
 * Mirrors {@see WorkflowsServiceProvider} exactly: an empty `register()`
 * (every handler's constructor dependencies —
 * {@see \Waaseyaa\Entity\EntityTypeManagerInterface},
 * {@see \Waaseyaa\Groups\Membership\GroupMembershipService} — are
 * container-autowireable, since `waaseyaa/groups`'s own
 * `GroupsServiceProvider` binds `GroupMembershipService` as a singleton) and
 * `HandlerCommand`s yielded from `consoleCommands()`.
 *
 * @api
 */
final class GroupsCliServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'groups:create',
            description: 'Create a group, ensuring its group_type bundle config entity exists first.',
            arguments: [
                new HandlerArgument(
                    name: 'type',
                    mode: HandlerArgumentMode::Required,
                    description: 'The group_type bundle id (e.g. department). Created automatically if it does not exist.',
                ),
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The label for the new group.',
                ),
            ],
            handler: [GroupsCreateHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'groups:member-add',
            description: 'Add a user as a member of a group.',
            arguments: [
                new HandlerArgument(
                    name: 'uid',
                    mode: HandlerArgumentMode::Required,
                    description: 'The user ID.',
                ),
                new HandlerArgument(
                    name: 'group',
                    mode: HandlerArgumentMode::Required,
                    description: 'The group ID (gid).',
                ),
            ],
            handler: [GroupsMemberAddHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'groups:member-remove',
            description: 'Remove a user from a group (soft-revoke; never deletes the underlying edge).',
            arguments: [
                new HandlerArgument(
                    name: 'uid',
                    mode: HandlerArgumentMode::Required,
                    description: 'The user ID.',
                ),
                new HandlerArgument(
                    name: 'group',
                    mode: HandlerArgumentMode::Required,
                    description: 'The group ID (gid).',
                ),
            ],
            handler: [GroupsMemberRemoveHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'groups:content-assign',
            description: 'Assign a content entity to a group.',
            arguments: [
                new HandlerArgument(
                    name: 'entity_type',
                    mode: HandlerArgumentMode::Required,
                    description: 'The content entity type ID (e.g. node).',
                ),
                new HandlerArgument(
                    name: 'id',
                    mode: HandlerArgumentMode::Required,
                    description: 'The content entity ID.',
                ),
                new HandlerArgument(
                    name: 'group',
                    mode: HandlerArgumentMode::Required,
                    description: 'The group ID (gid).',
                ),
            ],
            handler: [GroupsContentAssignHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'groups:content-unassign',
            description: 'Unassign a content entity from a group (soft-revoke; never deletes the underlying edge).',
            arguments: [
                new HandlerArgument(
                    name: 'entity_type',
                    mode: HandlerArgumentMode::Required,
                    description: 'The content entity type ID (e.g. node).',
                ),
                new HandlerArgument(
                    name: 'id',
                    mode: HandlerArgumentMode::Required,
                    description: 'The content entity ID.',
                ),
                new HandlerArgument(
                    name: 'group',
                    mode: HandlerArgumentMode::Required,
                    description: 'The group ID (gid).',
                ),
            ],
            handler: [GroupsContentUnassignHandler::class, 'execute'],
        );
    }
}
