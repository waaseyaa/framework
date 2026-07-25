<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Maintenance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Maintenance\MaintenanceSettings;

#[CoversClass(MaintenanceSettings::class)]
final class MaintenanceSettingsTest extends TestCase
{
    /** @var list<string> */
    private const array ENV_KEYS = [
        'WAASEYAA_MAINTENANCE_FLAG',
        'WAASEYAA_MAINTENANCE_HEALTH_PATH',
        'WAASEYAA_MAINTENANCE_TRUST_LOCALHOST',
        'WAASEYAA_MAINTENANCE_PAGE',
    ];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
    }

    #[Test]
    public function defaults_are_storage_flag_health_path_and_trusted_localhost(): void
    {
        $settings = MaintenanceSettings::fromEnvironment('/srv/app');

        self::assertSame('/srv/app/storage/maintenance.flag', $settings->flagPath);
        self::assertSame(['/health'], $settings->healthPaths);
        self::assertTrue($settings->trustLocalhost);
        self::assertSame(120, $settings->defaultRetryAfter);
        self::assertNull($settings->customPagePath);
    }

    #[Test]
    public function trust_localhost_knob_can_be_disabled(): void
    {
        putenv('WAASEYAA_MAINTENANCE_TRUST_LOCALHOST=false');

        $settings = MaintenanceSettings::fromEnvironment('/srv/app');

        self::assertFalse(
            $settings->trustLocalhost,
            'Deployments behind a same-host reverse proxy must be able to disable the localhost exemption.',
        );
    }

    #[Test]
    public function health_paths_are_normalized_and_support_multiple(): void
    {
        putenv('WAASEYAA_MAINTENANCE_HEALTH_PATH=healthz, /readyz/');

        $settings = MaintenanceSettings::fromEnvironment('/srv/app');

        self::assertSame(['/healthz', '/readyz'], $settings->healthPaths);
    }

    #[Test]
    public function empty_health_path_disables_the_exemption(): void
    {
        putenv('WAASEYAA_MAINTENANCE_HEALTH_PATH=');

        $settings = MaintenanceSettings::fromEnvironment('/srv/app');

        self::assertSame([], $settings->healthPaths);
    }

    #[Test]
    public function explicit_flag_and_page_paths_are_honored(): void
    {
        putenv('WAASEYAA_MAINTENANCE_FLAG=/var/run/maint.flag');
        putenv('WAASEYAA_MAINTENANCE_PAGE=custom/down.html');

        $settings = MaintenanceSettings::fromEnvironment('/srv/app');

        self::assertSame('/var/run/maint.flag', $settings->flagPath);
        // Relative override is resolved against the project root.
        self::assertSame('/srv/app/custom/down.html', $settings->customPagePath);
    }
}
