<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Generation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\CLI\Site\Scaffold\SearchProjectionScaffoldCompiler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Search\Access\EntitySearchCandidateResolver;
use Waaseyaa\Search\Projection\EntitySearchProjectionRegistry;
use Waaseyaa\Search\Projection\EntitySearchProjectorInterface;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Tests\Integration\Generation\Fixtures\ScaffoldedStory;

/**
 * #2849: what `make:search-projection` generates, exercised against a
 * **registered** entity type and the real authorization path.
 *
 * The generated projector is compiled here rather than hand-copied, so this is
 * a regression on the emitted bytes and not on a paraphrase of them. It asks
 * the projector to read three fields spanning the classification space and
 * proves that only the Public one survives — first at index time, where no
 * account scope is active, and then at query time through
 * {@see EntitySearchCandidateResolver}, which is the component that actually
 * sequences {@see EntityAccessHandler} and {@see AccountFieldReadScope} in
 * production. Neither collaborator is stubbed; only the entity-type manager
 * and repository, which merely hand back the entity, are stubs.
 */
#[CoversNothing]
final class SearchProjectionScaffoldRuntimeTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'scaffold_story';

    private const string PUBLIC_TEXT = 'Wild rice camp opens Monday.';

    private const string INTERNAL_TEXT = 'INTERNAL-do-not-index';

    private const string RESTRICTED_TEXT = 'RESTRICTED-do-not-index';

    public static function setUpBeforeClass(): void
    {
        // Load the projector exactly as the compiler emits it. The generated
        // namespace is App\Search, which no autoloader here maps, so a plain
        // require is both sufficient and honest about what is under test.
        if (class_exists(\App\Search\ScaffoldStorySearchProjector::class, false)) {
            return;
        }
        $plan = new SearchProjectionScaffoldCompiler()->compile(
            self::ENTITY_TYPE_ID,
            'ScaffoldStory',
            ['body', 'internal_note', 'restricted_note'],
        );
        foreach ($plan->artifacts as $artifact) {
            if ($artifact->path !== 'src/Search/ScaffoldStorySearchProjector.php') {
                continue;
            }
            $file = tempnam(sys_get_temp_dir(), 'waaseyaa_projector_') . '.php';
            file_put_contents($file, $artifact->content);
            require $file;
            unlink($file);

            return;
        }
        self::fail('The compiled plan did not contain the generated projector.');
    }

    #[Test]
    public function anExplicitlyPublicFieldProducesTheExpectedSearchableContent(): void
    {
        $document = $this->projector()->project($this->story());

        self::assertNotNull($document);
        self::assertSame(self::ENTITY_TYPE_ID . ':1', $document->getSearchDocumentId());
        self::assertSame('Fall harvest', $document->toSearchDocument()['title']);
        self::assertSame(
            self::PUBLIC_TEXT,
            $document->toSearchDocument()['body'],
            'A field declared read: FieldReadLevel::Public must reach the index.',
        );
    }

    #[Test]
    public function internalAndProtectedFieldsAreExcludedAtIndexTime(): void
    {
        // Index time runs with no account scope active, which is the strictest
        // context: only Public-classified fields release a value.
        $document = $this->projector()->project($this->story());

        self::assertNotNull($document);
        $this->assertCarriesNoRestrictedText($document->toSearchDocument());
    }

    #[Test]
    public function internalAndProtectedFieldsStayExcludedForAnAuthorizedPrincipalAtQueryTime(): void
    {
        $projection = $this->resolveThroughTheRealAuthorizationPath(AccessResult::allowed());

        self::assertNotNull($projection, 'An allowed principal must still resolve a candidate.');
        self::assertSame('Fall harvest', $projection->title);
        self::assertSame(self::PUBLIC_TEXT, $projection->body);
        $this->assertCarriesNoRestrictedText(['title' => $projection->title, 'body' => $projection->body]);
    }

    #[Test]
    public function anUnauthorizedPrincipalResolvesNoCandidateAtAll(): void
    {
        self::assertNull(
            $this->resolveThroughTheRealAuthorizationPath(AccessResult::forbidden()),
            'Entity-level denial must drop the candidate before any projection is released.',
        );
    }

    /** @param array<string, string> $fields */
    private function assertCarriesNoRestrictedText(array $fields): void
    {
        $rendered = implode(' ', $fields);
        self::assertStringNotContainsString(
            self::INTERNAL_TEXT,
            $rendered,
            'A registered entity type defaults an undeclared field to FieldReadLevel::Internal; it must never reach a search document.',
        );
        self::assertStringNotContainsString(
            self::RESTRICTED_TEXT,
            $rendered,
            'FieldReadLevel::Protected is deny-unless-granted: with no policy granting it, it must never reach a search document.',
        );
    }

    private function resolveThroughTheRealAuthorizationPath(AccessResult $entityAccess): mixed
    {
        $story = $this->story();

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($story);

        $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
        $entityTypeManager->method('hasDefinition')->willReturn(true);
        $entityTypeManager->method('getRepository')->willReturn($repository);

        $resolver = new EntitySearchCandidateResolver(
            $entityTypeManager,
            new EntityAccessHandler([$this->policy($entityAccess)]),
            new AccountFieldReadScope(),
            new EntitySearchProjectionRegistry([$this->projector()]),
        );

        return $resolver->resolve(
            new SearchCandidateReference(self::ENTITY_TYPE_ID . ':1', self::ENTITY_TYPE_ID),
            new AuthorizationPrincipal(
                accountId: 7,
                authenticated: true,
                roles: ['authenticated'],
                permissions: [],
                claimsGeneration: 'scaffold-runtime-test',
            ),
        );
    }

    private function policy(AccessResult $result): AccessPolicyInterface
    {
        return new class ($result, self::ENTITY_TYPE_ID) implements AccessPolicyInterface {
            public function __construct(
                private readonly AccessResult $result,
                private readonly string $entityTypeId,
            ) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $this->result;
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }
        };
    }

    private function projector(): EntitySearchProjectorInterface
    {
        return new \App\Search\ScaffoldStorySearchProjector();
    }

    private function story(): ScaffoldedStory
    {
        return new ScaffoldedStory([
            'id' => 1,
            'title' => 'Fall harvest',
            'body' => self::PUBLIC_TEXT,
            'internal_note' => self::INTERNAL_TEXT,
            'restricted_note' => self::RESTRICTED_TEXT,
        ]);
    }
}
