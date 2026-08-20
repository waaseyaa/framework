<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Exception\SaveAdvisoryAcknowledgementRequiredException;

#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostSaveAdvisoryTest extends TestCase
{
    #[Test]
    public function create_preserves_the_json_api_advisory_code_and_meta(): void
    {
        $entity = new TestEntity(['title' => 'News'], 'article');
        $advisory = SaveAdvisory::forEntityField(
            $entity,
            'RESERVED_ROUTE_VALUE',
            'title',
            'This title is route-backed; review the fallback URL.',
        );
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('create')->willReturn($entity);
        $repository->method('save')->willThrowException(
            new SaveAdvisoryAcknowledgementRequiredException([$advisory]),
        );

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn(true);
        $manager->method('getDefinition')->willReturn(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
        ));
        $manager->method('getRepository')->willReturn($repository);

        $access = $this->createStub(EntityAccessHandler::class);
        $access->method('checkCreateAccess')->willReturn(AccessResult::allowed('ok'));
        $access->method('checkFieldAccess')->willReturn(AccessResult::neutral('ok'));

        $host = new GenericAdminSurfaceHost($manager, $access);
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);
        $host->resolveSession($request);

        $result = $host->action('article', 'create', ['attributes' => ['title' => 'News']]);

        self::assertFalse($result->ok);
        self::assertSame(428, $result->error['status']);
        self::assertSame('SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED', $result->error['code']);
        self::assertSame([$advisory->payload()], $result->error['meta']['save_advisories']);
    }
}
