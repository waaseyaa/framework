<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Host\AdminSurfaceUiPayload;

#[CoversClass(AdminSurfaceUiPayload::class)]
final class AdminSurfaceUiPayloadTest extends TestCase
{
    #[Test]
    public function fromArraysDropsInvalidRows(): void
    {
        $payload = AdminSurfaceUiPayload::fromArrays(
            headerLinks: [
                ['label' => ' OK ', 'href' => '/ok'],
                ['label' => '', 'href' => '/x'],
                ['label' => 'Bad', 'href' => ''],
                'not-array',
            ],
            sidebarItems: [
                ['id' => 'a', 'label' => 'A', 'href' => '/a'],
                ['id' => '', 'label' => 'B', 'href' => '/b'],
            ],
        );

        self::assertSame([['label' => 'OK', 'href' => '/ok']], $payload->headerLinks);
        self::assertSame([
            ['id' => 'a', 'label' => 'A', 'href' => '/a'],
        ], $payload->sidebarItems);
    }

    #[Test]
    public function fromArraysKeepsOptionalFields(): void
    {
        $payload = AdminSurfaceUiPayload::fromArrays(
            headerLinks: [
                ['label' => 'Docs', 'href' => 'https://ex', 'external' => true],
            ],
            sidebarItems: [
                ['id' => 'x', 'label' => 'X', 'href' => '/x', 'group' => 'nav_group_custom', 'weight' => 2],
            ],
        );

        self::assertSame([
            ['label' => 'Docs', 'href' => 'https://ex', 'external' => true],
        ], $payload->headerLinks);
        self::assertSame([
            ['id' => 'x', 'label' => 'X', 'href' => '/x', 'group' => 'nav_group_custom', 'weight' => 2],
        ], $payload->sidebarItems);
    }

    #[Test]
    public function toArrayOmitsEmptySections(): void
    {
        $onlyHeaders = AdminSurfaceUiPayload::fromArrays(
            headerLinks: [['label' => 'Home', 'href' => '/']],
        );
        self::assertSame(['headerLinks' => [['label' => 'Home', 'href' => '/']]], $onlyHeaders->toArray());

        $empty = AdminSurfaceUiPayload::fromArrays();
        self::assertSame([], $empty->toArray());
        self::assertTrue($empty->isEmpty());
    }

    #[Test]
    public function weightAcceptsNumericStrings(): void
    {
        $payload = AdminSurfaceUiPayload::fromArrays(
            sidebarItems: [
                ['id' => 'n', 'label' => 'N', 'href' => '/n', 'weight' => '10'],
            ],
        );
        self::assertSame(10, $payload->sidebarItems[0]['weight'] ?? null);
    }

    #[Test]
    public function navigation_mode_is_closed_and_defaults_to_full(): void
    {
        self::assertSame([], AdminSurfaceUiPayload::fromArrays()->toArray());
        self::assertSame(
            ['navigationMode' => 'catalog-only'],
            AdminSurfaceUiPayload::fromArrays(navigationMode: 'catalog-only')->toArray(),
        );
        self::assertSame([], AdminSurfaceUiPayload::fromArrays(navigationMode: 'everything')->toArray());
    }
}
