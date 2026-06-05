<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Audit\AuditEventResource;
use Waaseyaa\Api\Audit\AuditQueryDto;
use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;
use Waaseyaa\Api\Controller\AuditQueryController;

#[CoversClass(AuditQueryController::class)]
final class AuditQueryControllerTest extends TestCase
{
    #[Test]
    public function nullReadModelReturnsEmptyShape(): void
    {
        $controller = new AuditQueryController(null);
        $request = Request::create('/api/audit/events', 'GET');

        $result = $controller->index($request);

        self::assertSame([], $result['data']);
        self::assertSame(0, $result['meta']['total']);
        self::assertSame(50, $result['meta']['limit']);
        self::assertSame(0, $result['meta']['offset']);
    }

    #[Test]
    public function indexReturnsMappedResourcesFromReadModel(): void
    {
        $resource = new AuditEventResource(
            id: 1,
            uuid: 'aaaaaaaa-0000-0000-0000-000000000001',
            eventKind: 'entity.read',
            accountUid: 1,
            entityType: 'node',
            entityUuid: 'bbbbbbbb-0000-0000-0000-000000000001',
            subjectUri: '/api/node/1',
            outcome: 'allowed',
            severity: 'info',
            attributes: ['test' => true],
            createdAt: '2026-05-25T00:00:00+00:00',
        );

        $readModel = new class ($resource) implements AuditQueryReadModelInterface {
            public function __construct(private readonly AuditEventResource $resource) {}

            public function findBy(AuditQueryDto $query): iterable
            {
                yield $this->resource;
            }

            public function count(AuditQueryDto $query): int
            {
                return 1;
            }
        };

        $controller = new AuditQueryController($readModel);
        $request = Request::create('/api/audit/events', 'GET');

        $result = $controller->index($request);

        self::assertCount(1, $result['data']);
        self::assertSame('entity.read', $result['data'][0]['eventKind']);
        self::assertSame(1, $result['meta']['total']);
        self::assertSame(50, $result['meta']['limit']);
    }

    #[Test]
    public function indexParsesFilterKindAsCommaSeparatedList(): void
    {
        $capturedDto = null;

        $readModel = new class ($capturedDto) implements AuditQueryReadModelInterface {
            public ?AuditQueryDto $capturedDto = null;

            public function findBy(AuditQueryDto $query): iterable
            {
                $this->capturedDto = $query;

                return [];
            }

            public function count(AuditQueryDto $query): int
            {
                return 0;
            }
        };

        $controller = new AuditQueryController($readModel);
        $request = Request::create('/api/audit/events?filter[kind]=entity.read,entity.write', 'GET');

        $controller->index($request);

        self::assertNotNull($readModel->capturedDto);
        self::assertSame(['entity.read', 'entity.write'], $readModel->capturedDto->kinds);
    }

    #[Test]
    public function indexRespectsPageLimitAndOffset(): void
    {
        $capturedDto = null;

        $readModel = new class ($capturedDto) implements AuditQueryReadModelInterface {
            public ?AuditQueryDto $capturedDto = null;

            public function findBy(AuditQueryDto $query): iterable
            {
                $this->capturedDto = $query;

                return [];
            }

            public function count(AuditQueryDto $query): int
            {
                return 0;
            }
        };

        $controller = new AuditQueryController($readModel);
        $request = Request::create('/api/audit/events?page[limit]=25&page[offset]=50', 'GET');

        $controller->index($request);

        self::assertNotNull($readModel->capturedDto);
        self::assertSame(25, $readModel->capturedDto->limit);
        self::assertSame(50, $readModel->capturedDto->offset);
    }

    #[Test]
    public function indexCapsLimitAt500(): void
    {
        $capturedDto = null;

        $readModel = new class ($capturedDto) implements AuditQueryReadModelInterface {
            public ?AuditQueryDto $capturedDto = null;

            public function findBy(AuditQueryDto $query): iterable
            {
                $this->capturedDto = $query;

                return [];
            }

            public function count(AuditQueryDto $query): int
            {
                return 0;
            }
        };

        $controller = new AuditQueryController($readModel);
        $request = Request::create('/api/audit/events?page[limit]=9999', 'GET');

        $result = $controller->index($request);

        self::assertNotNull($readModel->capturedDto);
        self::assertSame(500, $readModel->capturedDto->limit);
        self::assertSame(500, $result['meta']['limit']);
    }
}
