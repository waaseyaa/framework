<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Command\AddBlock;
use Waaseyaa\PageBuilder\Command\ConfigureBlock;
use Waaseyaa\PageBuilder\Wire\EditCommandDecoder;
use Waaseyaa\PageBuilder\Wire\Exception\InvalidWireCommandException;

final class EditCommandDecoderTest extends TestCase
{
    #[Test]
    public function decodesTheClosedCommandVocabulary(): void
    {
        $decoder = new EditCommandDecoder();

        self::assertEquals(
            new ConfigureBlock('blk_body', ['html' => '<p>Updated</p>']),
            $decoder->decode([
                'type' => 'configure_block',
                'block_id' => 'blk_body',
                'config' => ['html' => '<p>Updated</p>'],
            ]),
        );
        self::assertEquals(
            new AddBlock('sec_main', 'main', 1, ['id' => 'blk_new', 'type' => 'rich_text']),
            $decoder->decode([
                'type' => 'add_block',
                'section_id' => 'sec_main',
                'region_id' => 'main',
                'position' => 1,
                'block' => ['id' => 'blk_new', 'type' => 'rich_text'],
            ]),
        );
    }

    #[Test]
    public function rejectsUnknownCommandsUnknownFieldsAndWrongTypes(): void
    {
        $decoder = new EditCommandDecoder();

        foreach ([
            ['type' => 'execute_php'],
            ['type' => 'remove_block', 'block_id' => 'blk_body', 'force' => true],
            ['type' => 'move_section', 'section_id' => 'sec_main', 'position' => '1'],
        ] as $payload) {
            try {
                $decoder->decode($payload);
                self::fail('Invalid command was accepted.');
            } catch (InvalidWireCommandException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
