<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Media\Http\MediaDownloadResponderInterface;
use Waaseyaa\Media\Http\MediaDownloadSourceReaderInterface;
use Waaseyaa\Media\Http\Router\MediaDownloadRouter;

final class MediaDownloadResponderTest extends TestCase
{
    #[Test]
    public function public_entry_chooses_download_internally_without_mutating_caller_context(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::never())->method('getRepository');
        $reader = $this->createMock(MediaDownloadSourceReaderInterface::class);
        $reader->expects(self::never())->method('sourceUri');
        $router = new MediaDownloadRouter($manager, new EntityAccessHandler([]), '/unused', $reader);
        self::assertInstanceOf(MediaDownloadResponderInterface::class, $router);
        $request = Request::create('/site/job/file');
        $request->attributes->set('_controller', 'untrusted.caller');
        $request->attributes->set('id', 'wrong-caller-id');
        $request->headers->set('Range', 'bytes=0-1');
        $principal = new AuthorizationPrincipal(7, true, [], [], 'media-service-test', 'tenant-a', 'community-a');
        $request->attributes->set('_authorization_principal', $principal);
        // Missing matching account refuses before repository/source authority.
        $response = $router->download($request, 'exact-media-uuid');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('untrusted.caller', $request->attributes->get('_controller'));
        self::assertSame('wrong-caller-id', $request->attributes->get('id'));
        self::assertSame($principal, $request->attributes->get('_authorization_principal'));
        self::assertSame('bytes=0-1', $request->headers->get('Range'));
    }
}
