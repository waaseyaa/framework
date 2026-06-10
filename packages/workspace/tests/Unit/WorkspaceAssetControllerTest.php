<?php

declare(strict_types=1);

namespace Waaseyaa\Workspace\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workspace\WorkspaceAssetController;

final class WorkspaceAssetControllerTest extends TestCase
{
    #[Test]
    public function serves_every_allowlisted_asset_with_its_mime_type(): void
    {
        $controller = new WorkspaceAssetController();

        foreach ([
            'workspace-chat.js' => 'application/javascript',
            'workspace-md.js' => 'application/javascript',
            'workspace-chat.css' => 'text/css',
        ] as $file => $mime) {
            $response = $controller->serve($file);
            $this->assertSame(200, $response->getStatusCode(), $file);
            $this->assertSame($mime, $response->headers->get('Content-Type'), $file);
            $this->assertNotSame('', trim((string) $response->getContent()), $file);
        }
    }

    #[Test]
    public function refuses_anything_outside_the_allowlist(): void
    {
        $controller = new WorkspaceAssetController();

        foreach (['../composer.json', '..%2F..%2Fcomposer.json', 'evil.php', 'workspace-chat.js.bak', ''] as $bad) {
            $this->assertSame(404, $controller->serve($bad)->getStatusCode(), $bad);
        }
    }
}
