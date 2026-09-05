<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Publishing\AdvisoryAwareContentDraftMutationInterface;
use Waaseyaa\Publishing\ContentDraftMutationInterface;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\Exception\UnsupportedSaveAdvisoryAcknowledgementException;
use Waaseyaa\Publishing\SaveAdvisoryAcknowledgementDispatcher;

/**
 * The draft-mutation seam is a self-consistent public surface.
 *
 * A public entry point may not require a consumer to implement an internal
 * contract: `SaveAdvisoryAcknowledgementDispatcher` is `@api` and takes
 * `ContentDraftMutationInterface` as a parameter, so that interface — and the
 * extension a consumer must implement to carry receipts — are public too.
 * These assertions pin both the classifications and the exact frozen method
 * shapes described in docs/specs/save-advisories.md §10.
 */
#[CoversNothing]
final class DraftMutationPublicSurfaceTest extends TestCase
{
    /**
     * Monorepo root. Dispositions are composed from the package-local declaration
     * plane (docs/specs/public-surface-declarations.md), never from the generated
     * docs/public-surface-map.php, which may lag until the next release cut.
     */
    private const MONOREPO_ROOT = __DIR__ . '/../../../..';

    /** The five frozen parameters of the base contract, in order. */
    private const FROZEN_BASE_PARAMETERS = [
        ['actor', AuthorizationPrincipalInterface::class, false],
        ['id', 'string', false],
        ['values', 'array', false],
        ['expectedRevisionId', 'int', false],
        ['idempotencyKey', 'string', false],
    ];

    /** @return array<string, string> */
    private function surfaceMap(): array
    {
        /** @var array<string, string> $map */
        $map = require self::MONOREPO_ROOT . '/tools/lib/compose-public-surface.php';

        return $map;
    }

    #[Test]
    public function theEntryPointAndEveryContractItRequiresAreClassifiedPublic(): void
    {
        $map = $this->surfaceMap();

        foreach ([
            ContentDraftMutationInterface::class,
            AdvisoryAwareContentDraftMutationInterface::class,
            SaveAdvisoryAcknowledgementDispatcher::class,
            UnsupportedSaveAdvisoryAcknowledgementException::class,
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
    public function unrelatedRevisionAndPreviewSeamsStayInternal(): void
    {
        $map = $this->surfaceMap();

        foreach ([
            'Waaseyaa\Publishing\ContentRevisionHistoryInterface',
            'Waaseyaa\Publishing\ContentRevisionPreviewInterface',
        ] as $fqcn) {
            self::assertSame(
                'internal',
                $map[$fqcn] ?? null,
                "{$fqcn} is not taken by any public entry point and must stay internal.",
            );
        }
    }

    #[Test]
    public function theBaseUpdateDraftSignatureIsFrozenAtFiveParameters(): void
    {
        $parameters = (new \ReflectionMethod(ContentDraftMutationInterface::class, 'updateDraft'))->getParameters();

        self::assertCount(
            5,
            $parameters,
            'Adding a parameter to the base contract — even an optional one — is a load-time fatal for every implementor.',
        );
        $this->assertParametersMatch(self::FROZEN_BASE_PARAMETERS, $parameters);
    }

    #[Test]
    public function theAwareExtensionAddsExactlyOneTrailingOptionalParameter(): void
    {
        $parameters = (new \ReflectionMethod(AdvisoryAwareContentDraftMutationInterface::class, 'updateDraft'))
            ->getParameters();

        self::assertCount(6, $parameters);
        $this->assertParametersMatch(self::FROZEN_BASE_PARAMETERS, array_slice($parameters, 0, 5));

        $receipts = $parameters[5];
        self::assertSame('saveAdvisoryAcknowledgements', $receipts->getName());
        self::assertSame('array', (string) $receipts->getType());
        self::assertTrue($receipts->isOptional(), 'The receipts parameter must be optional.');
        self::assertSame([], $receipts->getDefaultValue());
        self::assertTrue(
            is_subclass_of(AdvisoryAwareContentDraftMutationInterface::class, ContentDraftMutationInterface::class),
            'The extension must extend the frozen base contract.',
        );
    }

    #[Test]
    public function theDispatcherAcceptsTheBaseContractSoLegacyImplementorsStaySupported(): void
    {
        $parameter = (new \ReflectionMethod(SaveAdvisoryAcknowledgementDispatcher::class, 'updateDraft'))
            ->getParameters()[0];

        self::assertSame('mutation', $parameter->getName());
        self::assertSame(
            ContentDraftMutationInterface::class,
            (string) $parameter->getType(),
            'The dispatcher must accept the base contract; requiring the extension would defeat the fail-closed path.',
        );
    }

    #[Test]
    public function theFrameworkPublisherImplementsTheAwareExtension(): void
    {
        self::assertTrue(
            is_subclass_of(ContentPublisher::class, AdvisoryAwareContentDraftMutationInterface::class),
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
