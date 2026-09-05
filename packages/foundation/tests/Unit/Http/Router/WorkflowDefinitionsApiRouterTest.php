<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Http\Router\WorkflowDefinitionsApiRouter;
use Waaseyaa\Workflows\Read\ActiveWorkflows;
use Waaseyaa\Workflows\Workflow;

/**
 * #2835: proves the production route resolves workflow definitions from
 * active, verified `workflows.assignments` configuration through
 * {@see ActiveWorkflows} — not the retired `EditorialWorkflowPreset` the
 * controller used to fall back to when the router built it with no
 * provider.
 */
#[CoversClass(WorkflowDefinitionsApiRouter::class)]
final class WorkflowDefinitionsApiRouterTest extends TestCase
{
    private function configFactory(array $assignments): ConfigFactoryInterface
    {
        return new class ($assignments) implements ConfigFactoryInterface {
            public function __construct(private readonly array $assignments) {}

            public function get(string $name): ConfigInterface
            {
                $data = $this->assignments;

                return new class ($data) implements ConfigInterface {
                    public function __construct(private readonly array $data) {}

                    public function getName(): string { return 'workflows.assignments'; }
                    public function get(string $key = ''): mixed { return $key === '' ? $this->data : ($this->data[$key] ?? null); }
                    public function set(string $key, mixed $value): static { return $this; }
                    public function clear(string $key): static { return $this; }
                    public function delete(): static { return $this; }
                    public function save(): static { return $this; }
                    public function isNew(): bool { return $this->data === []; }
                    public function getRawData(): array { return $this->data; }
                };
            }

            public function getEditable(string $name): ConfigInterface { return $this->get($name); }
            public function loadMultiple(array $names): array { return []; }
            public function rename(string $oldName, string $newName): static { return $this; }
            public function listAll(string $prefix = ''): array { return []; }
        };
    }

    /** @param array<string, Workflow> $workflows */
    private function entityTypeManager(array $workflows): EntityTypeManagerInterface
    {
        return new class ($workflows) implements EntityTypeManagerInterface {
            public function __construct(private readonly array $workflows) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface { throw new \LogicException('not needed'); }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return false; }
            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $workflows = $this->workflows;

                return new class ($workflows) implements EntityRepositoryInterface {
                    public function __construct(private readonly array $workflows) {}

                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(int|string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflows[$id] ?? null; }
                    public function loadWorkingCopy(int|string $id): ?EntityInterface { return $this->find($id); }

                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
                    {
                        $found = [];
                        foreach ($ids as $id) {
                            if (isset($this->workflows[$id])) {
                                $found[] = $this->workflows[$id];
                            }
                        }

                        return $found;
                    }

                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(int|string $id): bool { return isset($this->workflows[$id]); }
                    public function count(array $criteria = []): int { return \count($this->workflows); }
                    public function loadRevision(int|string $entityId, int $revisionId): ?EntityInterface { return null; }
                    public function rollback(int|string $entityId, int $targetRevisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface { throw new \LogicException('not needed'); }
                    public function listRevisions(int|string $entityId): array { return []; }
                    public function setCurrentRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface { throw new \LogicException('not needed'); }
                    public function loadPublishedRevision(int|string $entityId): ?EntityInterface { return null; }
                    public function setPublishedRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface { throw new \LogicException('not needed'); }
                    public function saveMany(array $entities, bool $validate = true): array { return []; }
                    public function deleteMany(array $entities): int { return 0; }
                    public function findTranslations(EntityInterface $entity): array { return []; }
                    public function saveTranslation(int|string $entityId, string $langcode, array $values, ?string $log = null, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): int { return 0; }
                    public function loadTranslation(int|string $entityId, string $langcode): ?EntityInterface { return null; }
                    public function listTranslationRevisions(int|string $entityId, string $langcode): array { return []; }
                };
            }
        };
    }

    private function activeEditorialWorkflow(): Workflow
    {
        // The live canonical editorial definition (docs/specs/content-workflow.md,
        // packages/workflows/src/DefaultWorkflows.php) — deliberately distinct
        // from the retired EditorialWorkflowPreset both in transition ids
        // (reject/restore_to_published/revise vs. send_back/unpublish) and in
        // `publish`'s allowed source states (draft+review vs. review only).
        return new Workflow([
            'id' => 'editorial',
            'label' => 'Editorial',
            'states' => [
                'draft' => ['label' => 'Draft', 'weight' => 0],
                'review' => ['label' => 'In review', 'weight' => 1],
                'published' => ['label' => 'Published', 'weight' => 2],
                'archived' => ['label' => 'Archived', 'weight' => 3],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit for review', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['draft', 'review'], 'to' => 'published'],
                'reject' => ['label' => 'Send back', 'from' => ['review'], 'to' => 'draft'],
                'archive' => ['label' => 'Archive', 'from' => ['published'], 'to' => 'archived'],
                'restore' => ['label' => 'Restore to draft', 'from' => ['archived'], 'to' => 'draft'],
                'restore_to_published' => ['label' => 'Restore', 'from' => ['archived'], 'to' => 'published'],
                'revise' => ['label' => 'Create new draft', 'from' => ['published'], 'to' => 'draft'],
            ],
        ]);
    }

    private function listRequest(): Request
    {
        $request = Request::create('/api/workflow-definitions');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Workflow\\WorkflowDefinitionsController::list');

        return $request;
    }

    /** @return array<int, mixed> */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function supports_workflow_definitions_controller(): void
    {
        $router = new WorkflowDefinitionsApiRouter();
        self::assertTrue($router->supports($this->listRequest()));
    }

    #[Test]
    public function does_not_support_unrelated_controller(): void
    {
        $router = new WorkflowDefinitionsApiRouter();
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_list_serves_the_active_bound_workflow_and_excludes_retired_transition_ids(): void
    {
        $editorial = $this->activeEditorialWorkflow();
        $activeWorkflows = new ActiveWorkflows(
            $this->configFactory(['node.article' => 'editorial']),
            $this->entityTypeManager(['editorial' => $editorial]),
        );
        $router = new WorkflowDefinitionsApiRouter($activeWorkflows);

        $data = $this->decode($router->handle($this->listRequest()))['data'];

        self::assertCount(1, $data);
        self::assertSame('editorial', $data[0]['id']);

        $transitionIds = array_column($data[0]['transitions'], 'id');
        sort($transitionIds);
        $activeTransitionIds = ['archive', 'publish', 'reject', 'restore', 'restore_to_published', 'revise', 'submit_for_review'];
        sort($activeTransitionIds);
        self::assertSame($activeTransitionIds, $transitionIds, 'served transitions must equal the active bound workflow\'s');

        // Retired EditorialWorkflowPreset ids must not leak into the response.
        self::assertNotContains('send_back', $transitionIds);
        self::assertNotContains('unpublish', $transitionIds);

        $publish = $data[0]['transitions'][array_search('publish', array_column($data[0]['transitions'], 'id'), true)];
        self::assertSame(['draft', 'review'], $publish['from'], 'publish must permit both draft and review, per the active definition');
    }

    #[Test]
    public function handle_list_returns_well_formed_empty_result_when_no_workflow_is_bound(): void
    {
        $activeWorkflows = new ActiveWorkflows(
            $this->configFactory([]),
            $this->entityTypeManager([]),
        );
        $router = new WorkflowDefinitionsApiRouter($activeWorkflows);

        $payload = $this->decode($router->handle($this->listRequest()));

        self::assertSame(['data' => []], $payload);
    }

    #[Test]
    public function handle_list_returns_well_formed_empty_result_on_a_core_only_install_without_the_workflows_package(): void
    {
        // Mirrors a `core`-only install where `waaseyaa/workflows` is not even
        // present: HttpKernel resolves no ActiveWorkflows service and
        // constructs the router with the no-argument default.
        $router = new WorkflowDefinitionsApiRouter();

        $payload = $this->decode($router->handle($this->listRequest()));

        self::assertSame(['data' => []], $payload, 'no provider must yield a well-formed empty result, never a fictional preset');
    }

    #[Test]
    public function handle_unknown_action_returns_404(): void
    {
        $router = new WorkflowDefinitionsApiRouter();
        $request = Request::create('/api/workflow-definitions/1');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Workflow\\WorkflowDefinitionsController::show');

        $response = $router->handle($request);
        self::assertSame(404, $response->getStatusCode());
    }
}
