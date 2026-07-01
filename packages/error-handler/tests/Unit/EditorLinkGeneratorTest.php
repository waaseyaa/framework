<?php

declare(strict_types=1);

namespace Waaseyaa\ErrorHandler\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\ErrorHandler\EditorLinkGenerator;

#[CoversClass(EditorLinkGenerator::class)]
final class EditorLinkGeneratorTest extends TestCase
{
    #[Test]
    public function default_editorId_produces_vscode_link(): void
    {
        $gen = new EditorLinkGenerator();
        self::assertStringStartsWith('vscode://', $gen->link('/var/www/file.php', 42));
    }

    #[Test]
    public function phpstorm_editorId_produces_phpstorm_link(): void
    {
        $gen = new EditorLinkGenerator('phpstorm');
        self::assertStringStartsWith('phpstorm://', $gen->link('/var/www/file.php', 42));
    }

    #[Test]
    public function sublime_editorId_produces_subl_link(): void
    {
        $gen = new EditorLinkGenerator('subl');
        self::assertStringStartsWith('subl://', $gen->link('/var/www/file.php', 1));
    }

    #[Test]
    public function constructor_does_not_read_getenv_directly(): void
    {
        // EditorLinkGenerator must be a pure value object — no env-side-effects.
        // Verify: constructing it with an explicit editorId always wins, regardless
        // of what EDITOR env var is set to.
        $original = getenv('EDITOR');
        putenv('EDITOR=phpstorm');

        try {
            $gen = new EditorLinkGenerator('subl');
            // If getenv() was called and used, this would be a phpstorm link.
            self::assertStringStartsWith('subl://', $gen->link('/f.php', 1),
                'EditorLinkGenerator must use the injected editorId, not getenv()');
        } finally {
            // Restore env
            if ($original === false) {
                putenv('EDITOR');
            } else {
                putenv('EDITOR=' . $original);
            }
        }
    }
}
