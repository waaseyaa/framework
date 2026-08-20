<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\SaveContext;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\SaveContext;

#[CoversClass(SaveContext::class)]
final class SaveContextAdvisoryAcknowledgementTest extends TestCase
{
    private const string TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string TOKEN_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[Test]
    public function builder_validates_deduplicates_sorts_and_preserves_immutability(): void
    {
        $original = SaveContext::default();
        $acknowledged = $original->withSaveAdvisoryAcknowledgements([
            self::TOKEN_B,
            self::TOKEN_A,
            self::TOKEN_B,
        ]);

        self::assertSame([], $original->saveAdvisoryAcknowledgements());
        self::assertSame([self::TOKEN_A, self::TOKEN_B], $acknowledged->saveAdvisoryAcknowledgements());
        self::assertTrue($acknowledged->acknowledgesSaveAdvisory(self::TOKEN_A));
        self::assertFalse($acknowledged->acknowledgesSaveAdvisory(str_repeat('c', 64)));
    }

    #[Test]
    public function malformed_or_oversized_sets_are_rejected(): void
    {
        foreach ([
            ['ABC'],
            [str_repeat('A', 64)],
            [str_repeat('a', 63)],
            [7],
            array_fill(0, 33, str_repeat('a', 64)),
        ] as $tokens) {
            try {
                SaveContext::default()->withSaveAdvisoryAcknowledgements($tokens);
                self::fail('Malformed acknowledgement input was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function every_other_builder_preserves_acknowledgements(): void
    {
        $base = SaveContext::default()->withSaveAdvisoryAcknowledgements([self::TOKEN_A]);

        foreach ([
            $base->withActorUid(42),
            $base->withExpectedRevisionId(7),
            $base->withoutNewRevision(),
            $base->withLangcode('oj'),
            $base->asImport(),
            $base->withTranslations(['en', 'oj']),
        ] as $derived) {
            self::assertSame([self::TOKEN_A], $derived->saveAdvisoryAcknowledgements());
        }
    }

    #[Test]
    public function acknowledgement_builder_preserves_every_other_context_field(): void
    {
        $context = SaveContext::default()
            ->withoutNewRevision()
            ->withLangcode('fr')
            ->asImport()
            ->withTranslations(['en', 'fr'])
            ->withActorUid(null)
            ->withExpectedRevisionId(9)
            ->withSaveAdvisoryAcknowledgements([self::TOKEN_A]);

        self::assertTrue($context->withoutNewRevision);
        self::assertSame('fr', $context->langcode);
        self::assertTrue($context->isImport);
        self::assertSame(['en', 'fr'], $context->translations);
        self::assertNull($context->actorUid());
        self::assertTrue($context->actorOverridden());
        self::assertSame(9, $context->expectedRevisionId());
    }
}
