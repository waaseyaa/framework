<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\PageBuilder\Draft\AdvisoryAwareLayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\Exception\LayoutSaveAdvisoryException;
use Waaseyaa\PageBuilder\Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException;
use Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Draft\LayoutSaveAdvisoryAcknowledgementDispatcher;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurface;

/**
 * The layout-draft seam is a self-consistent public surface, and the shape an
 * existing gateway must keep loading against is frozen.
 *
 * Mirrors `Waaseyaa\Publishing\Tests\Unit\DraftMutationPublicSurfaceTest` for
 * the seam one step further out. See docs/specs/save-advisories.md §11.
 *
 * That the framework's own gateway implements the extension is asserted in
 * `Waaseyaa\Publishing\Tests\Unit\PublishingLayoutDraftAdvisoryTest`: this
 * package must not know that `waaseyaa/publishing` exists.
 */
#[CoversNothing]
final class LayoutDraftPublicSurfaceTest extends TestCase
{
    /**
     * Monorepo root. Dispositions are composed from the package-local declaration
     * plane (docs/specs/public-surface-declarations.md), never from the generated
     * docs/public-surface-map.php, which may lag until the next release cut.
     */
    private const string MONOREPO_ROOT = __DIR__ . '/../../../..';

    /** The five frozen parameters of the base gateway contract, in order. */
    private const array FROZEN_BASE_PARAMETERS = [
        ['actor', AuthorizationPrincipalInterface::class, false],
        ['entityId', 'string', false],
        ['encodedLayout', 'string', false],
        ['expectedRevisionId', 'int', false],
        ['idempotencyKey', 'string', false],
    ];

    #[Test]
    public function theEntryPointAndEveryContractItRequiresAreClassifiedPublic(): void
    {
        /** @var array<string, string> $map */
        $map = require self::MONOREPO_ROOT . '/tools/lib/compose-public-surface.php';

        foreach ([
            LayoutDraftGatewayInterface::class,
            AdvisoryAwareLayoutDraftGatewayInterface::class,
            LayoutSaveAdvisoryAcknowledgementDispatcher::class,
            LayoutSaveAdvisoryException::class,
            UnsupportedLayoutSaveAdvisoryAcknowledgementException::class,
        ] as $fqcn) {
            self::assertArrayHasKey($fqcn, $map, "{$fqcn} has no disposition in the public surface map.");
            self::assertSame(
                'public',
                $map[$fqcn],
                "{$fqcn} is reachable from an @api entry point and must be classified public.",
            );
        }
    }

    #[Test]
    public function theBaseGatewayUpdateSignatureIsFrozenAtFiveParameters(): void
    {
        $parameters = (new \ReflectionMethod(LayoutDraftGatewayInterface::class, 'update'))->getParameters();

        self::assertCount(
            5,
            $parameters,
            'Adding a parameter to the base gateway — even an optional one — is a load-time fatal for every implementor.',
        );
        $this->assertParametersMatch(self::FROZEN_BASE_PARAMETERS, $parameters);
    }

    #[Test]
    public function theAwareExtensionAddsExactlyOneTrailingOptionalParameter(): void
    {
        $parameters = (new \ReflectionMethod(AdvisoryAwareLayoutDraftGatewayInterface::class, 'update'))
            ->getParameters();

        self::assertCount(6, $parameters);
        $this->assertParametersMatch(self::FROZEN_BASE_PARAMETERS, array_slice($parameters, 0, 5));

        $receipts = $parameters[5];
        self::assertSame('saveAdvisoryAcknowledgements', $receipts->getName());
        self::assertSame('array', (string) $receipts->getType());
        self::assertTrue($receipts->isOptional(), 'The receipts parameter must be optional.');
        self::assertSame([], $receipts->getDefaultValue());
        self::assertTrue(
            is_subclass_of(AdvisoryAwareLayoutDraftGatewayInterface::class, LayoutDraftGatewayInterface::class),
            'The extension must extend the frozen base contract.',
        );
    }

    #[Test]
    public function theCallersThatForwardReceiptsAreFinalClassesSoTheAddedParameterIsSourceCompatible(): void
    {
        foreach ([LayoutDraftManager::class, PageBuilderSurface::class] as $fqcn) {
            self::assertTrue(
                (new \ReflectionClass($fqcn))->isFinal(),
                "{$fqcn} gained a trailing parameter; that is only safe because it cannot be extended.",
            );

            $receipts = (new \ReflectionMethod($fqcn, 'apply'))->getParameters();
            $last = $receipts[count($receipts) - 1];
            self::assertSame('saveAdvisoryAcknowledgements', $last->getName());
            self::assertTrue($last->isOptional(), "{$fqcn}::apply() receipts must be optional.");
            self::assertSame([], $last->getDefaultValue());
        }
    }

    #[Test]
    public function theRefusalCodeMatchesTheDraftMutationSeamVocabulary(): void
    {
        self::assertSame(
            'SAVE_ADVISORY_UNSUPPORTED',
            UnsupportedLayoutSaveAdvisoryAcknowledgementException::ERROR_CODE,
            'One refusal vocabulary across both seams; a transport should not have to branch on which seam refused.',
        );
        self::assertSame(
            'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
            LayoutSaveAdvisoryException::ERROR_CODE,
        );
    }

    /**
     * @param list<array{0:string,1:string,2:bool}> $expected
     * @param list<\ReflectionParameter> $actual
     */
    private function assertParametersMatch(array $expected, array $actual): void
    {
        foreach ($expected as $index => [$name, $type, $optional]) {
            self::assertSame($name, $actual[$index]->getName(), "parameter {$index} name");
            self::assertSame($type, (string) $actual[$index]->getType(), "parameter {$index} type");
            self::assertSame($optional, $actual[$index]->isOptional(), "parameter {$index} optionality");
        }
    }
}
