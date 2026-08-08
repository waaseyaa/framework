<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Community;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Community\CommunityContext;
use Waaseyaa\Foundation\Community\CommunityContextBootstrapper;

#[CoversClass(CommunityContextBootstrapper::class)]
final class CommunityContextBootstrapperTest extends TestCase
{
    public function testMissingConfigurationLeavesTheContextRequestScoped(): void
    {
        self::assertNull((new CommunityContextBootstrapper())->boot([]));
    }

    public function testFixedCommunityIsAvailableToHttpAndCliKernels(): void
    {
        $context = (new CommunityContextBootstrapper())->boot(['community_id' => ' sheguiandah ']);

        self::assertInstanceOf(CommunityContext::class, $context);
        self::assertSame('sheguiandah', $context->get());
        self::assertTrue($context->isActive());
    }

    public function testNonStringAndBlankConfigurationFailClosed(): void
    {
        foreach ([['community_id' => []], ['community_id' => '   ']] as $config) {
            try {
                (new CommunityContextBootstrapper())->boot($config);
                self::fail('Invalid community_id configuration was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
