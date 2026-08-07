<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Routing\ParamConverter\EntityParamConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Route;

#[CoversClass(EntityParamConverter::class)]
final class EntityParamConverterTest extends TestCase
{
    #[Test]
    public function convertLoadsEntityAndReplacesParameter(): void
    {
        $entity = $this->createStub(EntityInterface::class);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('42')
            ->willReturn($entity);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->expects($this->once())
            ->method('getRepository')
            ->with('node')
            ->willReturn($repository);

        $converter = new EntityParamConverter($entityTypeManager);

        $route = new Route('/node/{node}');
        $route->setOption('parameters', [
            'node' => ['type' => 'entity:node'],
        ]);

        $parameters = ['node' => '42', '_route' => 'node.view'];
        $result = $converter->convert($parameters, $route);

        $this->assertSame($entity, $result['node']);
        $this->assertSame('node.view', $result['_route']);
    }

    #[Test]
    public function convertThrowsWhenEntityNotFound(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('999')
            ->willReturn(null);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->expects($this->once())
            ->method('getRepository')
            ->with('node')
            ->willReturn($repository);

        $converter = new EntityParamConverter($entityTypeManager);

        $route = new Route('/node/{node}');
        $route->setOption('parameters', [
            'node' => ['type' => 'entity:node'],
        ]);

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Entity "node" with ID "999" not found.');

        $converter->convert(['node' => '999'], $route);
    }

    #[Test]
    public function convertIgnoresNonEntityParameters(): void
    {
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->expects($this->never())->method('getRepository');

        $converter = new EntityParamConverter($entityTypeManager);

        $route = new Route('/search/{query}');
        $route->setOption('parameters', [
            'query' => ['type' => 'string'],
        ]);

        $parameters = ['query' => 'test'];
        $result = $converter->convert($parameters, $route);

        $this->assertSame('test', $result['query']);
    }

    #[Test]
    public function convertIgnoresParametersWithoutType(): void
    {
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->expects($this->never())->method('getRepository');

        $converter = new EntityParamConverter($entityTypeManager);

        $route = new Route('/node/{node}');
        $route->setOption('parameters', [
            'node' => ['converter' => 'some_converter'],
        ]);

        $parameters = ['node' => '42'];
        $result = $converter->convert($parameters, $route);

        $this->assertSame('42', $result['node']);
    }

    #[Test]
    public function convertSkipsMissingParameterValues(): void
    {
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->expects($this->never())->method('getRepository');

        $converter = new EntityParamConverter($entityTypeManager);

        $route = new Route('/node/{node}');
        $route->setOption('parameters', [
            'node' => ['type' => 'entity:node'],
        ]);

        // Parameter 'node' is declared but not in the matched parameters.
        $parameters = ['_route' => 'node.view'];
        $result = $converter->convert($parameters, $route);

        $this->assertSame(['_route' => 'node.view'], $result);
    }

    #[Test]
    public function convertHandlesRouteWithNoParameterOption(): void
    {
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->expects($this->never())->method('getRepository');

        $converter = new EntityParamConverter($entityTypeManager);

        $route = new Route('/about');
        // No 'parameters' option set at all.

        $parameters = ['_route' => 'about'];
        $result = $converter->convert($parameters, $route);

        $this->assertSame(['_route' => 'about'], $result);
    }

    #[Test]
    public function convertHandlesMultipleEntityParameters(): void
    {
        $nodeEntity = $this->createStub(EntityInterface::class);
        $userEntity = $this->createStub(EntityInterface::class);

        $nodeRepository = $this->createMock(EntityRepositoryInterface::class);
        $nodeRepository->expects($this->once())
            ->method('find')
            ->with('5')
            ->willReturn($nodeEntity);

        $userRepository = $this->createMock(EntityRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('find')
            ->with('10')
            ->willReturn($userEntity);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->expects($this->exactly(2))
            ->method('getRepository')
            ->willReturnCallback(function (string $entityTypeId) use ($nodeRepository, $userRepository) {
                return match ($entityTypeId) {
                    'node' => $nodeRepository,
                    'user' => $userRepository,
                };
            });

        $converter = new EntityParamConverter($entityTypeManager);

        $route = new Route('/node/{node}/author/{user}');
        $route->setOption('parameters', [
            'node' => ['type' => 'entity:node'],
            'user' => ['type' => 'entity:user'],
        ]);

        $parameters = ['node' => '5', 'user' => '10'];
        $result = $converter->convert($parameters, $route);

        $this->assertSame($nodeEntity, $result['node']);
        $this->assertSame($userEntity, $result['user']);
    }

    #[Test]
    public function convertHandlesMixedEntityAndNonEntityParameters(): void
    {
        $entity = $this->createStub(EntityInterface::class);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('42')
            ->willReturn($entity);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->expects($this->once())
            ->method('getRepository')
            ->with('node')
            ->willReturn($repository);

        $converter = new EntityParamConverter($entityTypeManager);

        $route = new Route('/node/{node}/tab/{tab}');
        $route->setOption('parameters', [
            'node' => ['type' => 'entity:node'],
            'tab' => ['type' => 'string'],
        ]);

        $parameters = ['node' => '42', 'tab' => 'edit'];
        $result = $converter->convert($parameters, $route);

        $this->assertSame($entity, $result['node']);
        $this->assertSame('edit', $result['tab']);
    }
}
