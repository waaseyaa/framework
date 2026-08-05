<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface;
use Waaseyaa\Wayfinding\WayfindingServiceProvider;

#[CoversClass(WayfindingServiceProvider::class)]
final class WayfindingApiCatalogContributionTest extends TestCase
{
    #[Test]
    public function contributes_only_the_public_machine_readable_anchor_catalog(): void
    {
        $provider = new WayfindingServiceProvider();
        self::assertInstanceOf(ProvidesApiCatalogEntriesInterface::class, $provider);

        $entries = $provider->apiCatalogEntries();

        self::assertCount(1, $entries);
        self::assertSame('/.well-known/waaseyaa-anchors.json', $entries[0]->endpoint->path);
        self::assertSame('application/json', $entries[0]->endpoint->type);
        self::assertStringNotContainsString('/api/wayfinding', json_encode($entries, JSON_THROW_ON_ERROR));
    }
}
