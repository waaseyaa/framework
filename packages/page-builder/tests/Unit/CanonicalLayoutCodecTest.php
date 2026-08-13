<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Document\Exception\InvalidLayoutDocumentException;
use Waaseyaa\PageBuilder\Document\LayoutDocument;

final class CanonicalLayoutCodecTest extends TestCase
{
    #[Test]
    public function encodingIsDeterministicAndPreservesEditorialOrder(): void
    {
        $first = LayoutDocument::fromArray($this->document([
            'tone' => 'calm',
            'html' => '<p>Boozhoo</p>',
        ]));
        $second = LayoutDocument::fromArray($this->document([
            'html' => '<p>Boozhoo</p>',
            'tone' => 'calm',
        ]));

        $codec = new CanonicalLayoutCodec();

        self::assertSame($codec->encode($first), $codec->encode($second));
        self::assertSame(
            ['sec_welcome', 'sec_services'],
            array_column($codec->decode($codec->encode($first))->sections(), 'id'),
        );
    }

    #[Test]
    public function unknownEnvelopeFieldsFailClosed(): void
    {
        $payload = $this->document([]);
        $payload['future_authority'] = true;

        $this->expectException(InvalidLayoutDocumentException::class);
        $this->expectExceptionMessage('Unknown layout document field: future_authority');

        LayoutDocument::fromArray($payload);
    }

    #[Test]
    public function unsupportedDocumentVersionFailsClosed(): void
    {
        $payload = $this->document([]);
        $payload['version'] = 2;

        $this->expectException(InvalidLayoutDocumentException::class);
        $this->expectExceptionMessage('Unsupported layout document version: 2');

        LayoutDocument::fromArray($payload);
    }

    #[Test]
    public function emptyBlockConfigurationRemainsAJsonObject(): void
    {
        $encoded = new CanonicalLayoutCodec()->encode(LayoutDocument::fromArray($this->document([])));

        self::assertStringContainsString('"config":{}', $encoded);
        self::assertStringNotContainsString('"config":[]', $encoded);
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): void, string}> */
    public static function invalidEnvelopes(): iterable
    {
        yield 'missing field' => [static function (array &$payload): void { unset($payload['sections']); }, 'Missing layout document field'];
        yield 'schema' => [static function (array &$payload): void { $payload['schema'] = 'other'; }, 'Unsupported layout document schema'];
        yield 'version type' => [static function (array &$payload): void { $payload['version'] = []; }, 'Unsupported layout document version'];
        yield 'template type' => [static function (array &$payload): void { $payload['template'] = 'standard'; }, 'template must be an object'];
        yield 'template fields' => [static function (array &$payload): void { $payload['template']['renderer'] = 'x'; }, 'only id and version'];
        yield 'template id' => [static function (array &$payload): void { $payload['template']['id'] = ''; }, 'non-empty string'];
        yield 'template version' => [static function (array &$payload): void { $payload['template']['version'] = 0; }, 'positive integer'];
        yield 'sections map' => [static function (array &$payload): void { $payload['sections'] = ['first' => []]; }, 'sections must be a list'];
        yield 'section scalar' => [static function (array &$payload): void { $payload['sections'] = ['bad']; }, 'section must be an object'];
    }

    #[Test]
    #[DataProvider('invalidEnvelopes')]
    public function malformedDocumentEnvelopeFailsClosed(\Closure $mutate, string $message): void
    {
        $payload = $this->document([]);
        $mutate($payload);
        $this->expectException(InvalidLayoutDocumentException::class);
        $this->expectExceptionMessage($message);
        LayoutDocument::fromArray($payload);
    }

    #[Test]
    public function invalidJsonAndScalarJsonFailClosed(): void
    {
        $codec = new CanonicalLayoutCodec();
        foreach (['{', 'null'] as $json) {
            try {
                $codec->decode($json);
                self::fail('Invalid JSON authority was accepted.');
            } catch (InvalidLayoutDocumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function document(array $config): array
    {
        return [
            'schema' => 'waaseyaa.layout',
            'version' => 1,
            'template' => ['id' => 'standard', 'version' => 1],
            'sections' => [
                [
                    'id' => 'sec_welcome',
                    'layout' => ['id' => 'one_column', 'version' => 1],
                    'regions' => [
                        'main' => [[
                            'id' => 'blk_welcome',
                            'type' => 'rich_text',
                            'version' => 1,
                            'config' => $config,
                        ]],
                    ],
                ],
                [
                    'id' => 'sec_services',
                    'layout' => ['id' => 'one_column', 'version' => 1],
                    'regions' => ['main' => []],
                ],
            ],
        ];
    }
}
