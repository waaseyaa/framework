<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Fixtures;

use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

final readonly class RootApplicationV2Migration implements MigrationInterfaceV2
{
    public function migrationId(): string
    {
        return 'acme/application:v2:add-widget-profile';
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
                    'widgets',
                    'profile',
                    new ColumnSpec(type: 'text', nullable: true),
                ),
            ]),
        );
    }
}
