<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Fixtures;

use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

/**
 * The #2701 shape: a root-application V2 evolution of an ENTITY base table.
 *
 * `account` is a registered entity type id, so entity schema synchronization
 * owns the table's existence. On an existing site the table is already present
 * and this plan is the only thing that can add `user_id`. On a fresh install
 * the table does not exist when stock migrations apply.
 */
final class EntityEvolutionV2Migration implements MigrationInterfaceV2
{
    /** ColumnSpec type token this fixture authors; set by the test for each logical type. */
    public static string $specType = 'text';

    public function migrationId(): string
    {
        return 'acme/application:v2:add-account-user-id';
    }

    public function package(): string
    {
        return 'acme/application';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function plan(): MigrationPlan
    {
        return new MigrationPlan(
            migrationId: $this->migrationId(),
            package: $this->package(),
            dependencies: [],
            root: new CompositeDiff([
                new AddColumn(
                    'account',
                    'user_id',
                    new ColumnSpec(type: self::$specType, nullable: true),
                ),
            ]),
        );
    }
}
