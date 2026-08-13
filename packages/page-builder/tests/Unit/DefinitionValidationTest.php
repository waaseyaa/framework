<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Definition\BlockDefinition;
use Waaseyaa\PageBuilder\Definition\LayoutDefinition;
use Waaseyaa\PageBuilder\Definition\TemplateDefinition;

final class DefinitionValidationTest extends TestCase
{
    /** @return iterable<string, array{\Closure(): object, string}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'invalid id' => [fn(): object => new BlockDefinition('Rich Text', 1, 'Rich text', 'content.rich_text', ['type' => 'object']), 'Invalid block id'];
        yield 'invalid version' => [fn(): object => new BlockDefinition('rich_text', 0, 'Rich text', 'content.rich_text', ['type' => 'object']), 'positive integer'];
        yield 'empty label' => [fn(): object => new BlockDefinition('rich_text', 1, ' ', 'content.rich_text', ['type' => 'object']), 'label'];
        yield 'invalid renderer' => [fn(): object => new BlockDefinition('rich_text', 1, 'Rich text', 'Content\\RichText', ['type' => 'object']), 'renderer'];
        yield 'empty schema' => [fn(): object => new BlockDefinition('rich_text', 1, 'Rich text', 'content.rich_text', []), 'schema'];
        yield 'duplicate regions' => [fn(): object => new LayoutDefinition('one_column', 1, ['main', 'main'], ['main'], ['rich_text']), 'Duplicate layout region'];
        yield 'undeclared required region' => [fn(): object => new LayoutDefinition('one_column', 1, ['main'], ['sidebar'], ['rich_text']), 'not declared'];
        yield 'duplicate allowed layouts' => [fn(): object => new TemplateDefinition('standard', 1, ['one_column', 'one_column'], ['rich_text']), 'Duplicate allowed layout'];
    }

    #[Test]
    #[DataProvider('invalidDefinitions')]
    public function invalidDefinitionAuthorityFailsClosed(\Closure $create, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $create();
    }
}
