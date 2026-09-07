<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\WorkflowScaffoldHandler;
use Waaseyaa\CLI\Provider\OtherScaffoldsServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Validation\WorkflowValidator;
use Waaseyaa\Workflows\Workflow;

/**
 * #2848: `scaffold:workflow` renders through
 * `WorkflowDefinitionEmitter::toDefinitionArray()`, the same derivation
 * rules the blueprint compiler uses, so these assertions describe the real
 * `Workflow::__construct()` hydration contract and the real
 * `workflows.assignments` key shape — not the pre-#2848 flat/positional
 * JSON, which could not hydrate a real `Workflow` or form a valid
 * assignment key at all.
 */
#[CoversClass(WorkflowScaffoldHandler::class)]
final class WorkflowScaffoldHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new OtherScaffoldsServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:workflow') {
                return $cmd;
            }
        }

        throw new \RuntimeException('scaffold:workflow command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === WorkflowScaffoldHandler::class) {
                    return new WorkflowScaffoldHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === WorkflowScaffoldHandler::class;
            }
        };
    }

    #[Test]
    public function duplicateTransitionIdentityCannotSilentlyReplacePermission(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute([
            '--id=review', '--entity-type=node', '--bundle=article',
            '--state=draft', '--state=published',
            '--transition=publish:draft:published:admin publish',
            '--transition=publish:draft:published:basic publish',
        ]);
        self::assertSame(2, $tester->getExitCode());
        self::assertSame('', $tester->getStdout());
        self::assertStringContainsString('Duplicate transition', $tester->getStderr());
    }

    #[Test]
    public function itGeneratesACanonicalWorkflowDefinitionAndAssignment(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=article_editorial',
            '--entity-type=node',
            '--bundle=article',
            '--state=draft',
            '--state=review',
            '--state=published',
            '--transition=publish:review:published:publish article content',
            '--transition=submit_review:draft:review:submit article for review',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('article_editorial', $decoded['workflow']['id']);
        self::assertSame('draft', $decoded['workflow']['initial_state']);
        self::assertSame(
            ['label' => 'Draft', 'published' => false, 'default_revision' => false],
            $decoded['workflow']['states']['draft'],
        );
        self::assertSame(
            ['label' => 'Published', 'published' => true, 'default_revision' => true],
            $decoded['workflow']['states']['published'],
        );
        self::assertSame(
            ['label' => 'Publish', 'from' => ['review'], 'to' => 'published', 'permission' => 'publish article content'],
            $decoded['workflow']['transitions']['publish'],
        );
        self::assertSame(['node.article' => 'article_editorial'], $decoded['assignment']);
    }

    #[Test]
    public function itReturnsErrorForMalformedTransition(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=article_editorial',
            '--entity-type=node',
            '--bundle=article',
            '--transition=broken',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('Invalid --transition format', $tester->getStderr());
    }

    #[Test]
    public function itReturnsErrorForMissingRequiredOptions(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute(['--id=only-id']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('required', $tester->getStderr());
    }

    #[Test]
    public function itUsesDefaultStatesAndTransitionsWhenNoneProvided(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=my_workflow',
            '--entity-type=node',
            '--bundle=article',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($decoded['workflow']['states']);
        self::assertNotEmpty($decoded['workflow']['transitions']);
        self::assertSame('draft', $decoded['workflow']['initial_state']);
    }

    #[Test]
    public function itAcceptsAMultiStateFromListInATransition(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=my_workflow',
            '--entity-type=node',
            '--bundle=article',
            '--state=draft',
            '--state=review',
            '--state=published',
            '--transition=publish:draft,review:published:publish article content',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['draft', 'review'], $decoded['workflow']['transitions']['publish']['from']);
    }

    #[Test]
    public function itRejectsAnInitialStateNotAmongTheDeclaredStates(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=my_workflow',
            '--entity-type=node',
            '--bundle=article',
            '--state=draft',
            '--initial-state=nonexistent',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('--initial-state', $tester->getStderr());
    }

    #[Test]
    public function itRejectsATransitionReferencingAnUndeclaredState(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=my_workflow',
            '--entity-type=node',
            '--bundle=article',
            '--state=draft',
            '--transition=publish:draft:nonexistent:publish article content',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('undeclared state', $tester->getStderr());
    }

    /**
     * Real access evaluation, workflow side: the emitted `workflow` payload
     * hydrates a real `Workflow` that passes the real `WorkflowValidator`,
     * and the emitted `assignment` resolves through the real
     * `WorkflowBindingResolver` (same in-memory fixture shape
     * `WorkflowBindingResolverTest` already uses) to that exact workflow.
     */
    #[Test]
    public function theEmittedDefinitionAndAssignmentResolveThroughRealWorkflowServices(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=article_editorial',
            '--entity-type=node',
            '--bundle=article',
            '--state=draft',
            '--state=review',
            '--state=published',
            '--transition=publish:review:published:publish article content',
            '--transition=submit_review:draft:review:submit article for review',
        ]);
        self::assertSame(0, $tester->getExitCode());

        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);

        $workflow = new Workflow($decoded['workflow']);
        self::assertSame([], new WorkflowValidator()->validate($workflow));
        self::assertSame('draft', $workflow->getInitialState());

        $publish = $workflow->getTransition('publish');
        self::assertNotNull($publish);
        self::assertSame('publish article content', $workflow->permissionFor($publish));

        [$assignmentKey, $workflowId] = [array_key_first($decoded['assignment']), $decoded['assignment'][array_key_first($decoded['assignment'])]];
        self::assertSame('node.article', $assignmentKey);
        self::assertSame('article_editorial', $workflowId);

        $resolver = new WorkflowBindingResolver(
            $this->configFactory([$assignmentKey => $workflowId]),
            $this->entityTypeManager(
                ['node' => new EntityType(id: 'node', label: 'Content', class: \stdClass::class, keys: ['id' => 'nid', 'revision' => 'vid'], revisionable: true)],
                [$workflowId => $workflow],
            ),
        );

        $resolved = $resolver->resolve('node', 'article');
        self::assertSame($workflow, $resolved);
    }

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

    private function entityTypeManager(array $definitions, array $workflows): EntityTypeManagerInterface
    {
        return new class ($definitions, $workflows) implements EntityTypeManagerInterface {
            public function __construct(
                private readonly array $definitions,
                private readonly array $workflows,
            ) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return $this->definitions[$entityTypeId];
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return $this->definitions; }
            public function hasDefinition(string $entityTypeId): bool { return isset($this->definitions[$entityTypeId]); }

            public function getStorage(string $entityTypeId): \Waaseyaa\Entity\Storage\EntityStorageInterface { throw new \LogicException('not needed'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $workflows = $this->workflows;

                return new class ($workflows) implements EntityRepositoryInterface {
                    public function __construct(private readonly array $workflows) {}

                    public function create(array $values = []): \Waaseyaa\Entity\EntityInterface { throw new \LogicException('not needed'); }

                    public function find(int|string $id, ?string $langcode = null, bool $fallback = false): ?\Waaseyaa\Entity\EntityInterface
                    {
                        return $this->workflows[$id] ?? null;
                    }

                    public function loadWorkingCopy(int|string $id): ?\Waaseyaa\Entity\EntityInterface
                    {
                        return $this->find($id);
                    }

                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(\Waaseyaa\Entity\EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(\Waaseyaa\Entity\EntityInterface $entity): void {}
                    public function exists(int|string $id): bool { return isset($this->workflows[$id]); }
                    public function count(array $criteria = []): int { return \count($this->workflows); }
                    public function loadRevision(int|string $entityId, int $revisionId): ?\Waaseyaa\Entity\EntityInterface { return null; }
                    public function rollback(int|string $entityId, int $targetRevisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): \Waaseyaa\Entity\EntityInterface { throw new \LogicException('not needed'); }
                    public function listRevisions(int|string $entityId): array { return []; }
                    public function setCurrentRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): \Waaseyaa\Entity\EntityInterface { throw new \LogicException('not needed'); }
                    public function loadPublishedRevision(int|string $entityId): ?\Waaseyaa\Entity\EntityInterface { return null; }
                    public function setPublishedRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): \Waaseyaa\Entity\EntityInterface { throw new \LogicException('not needed'); }
                    public function saveMany(array $entities, bool $validate = true): array { return []; }
                    public function deleteMany(array $entities): int { return 0; }
                    public function findTranslations(\Waaseyaa\Entity\EntityInterface $entity): array { return []; }
                    public function saveTranslation(int|string $entityId, string $langcode, array $values, ?string $log = null, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): int { return 0; }
                    public function loadTranslation(int|string $entityId, string $langcode): ?\Waaseyaa\Entity\EntityInterface { return null; }
                    public function listTranslationRevisions(int|string $entityId, string $langcode): array { return []; }
                };
            }
        };
    }
}
