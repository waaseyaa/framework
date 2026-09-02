<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Waaseyaa\GraphQL\Http\Router\GraphQlRouter;
use Waaseyaa\GraphQL\Schema\SchemaFactory;
use Waaseyaa\GraphQL\Tests\Fixtures\AttributeFirstEntities\ArticleSchemaFixture;

require_once __DIR__ . '/../Fixtures/AttributeFirstEntities/ArticleSchemaFixture.php';

/**
 * The GraphQL wire adapter consumes the kernel's boot-scoped field-type
 * registry (#2786 B1). An injected registry that admits nothing proves the
 * factory, endpoint, and router consult the instance they were handed rather
 * than the static built-in default.
 */
#[CoversClass(SchemaFactory::class)]
#[CoversClass(GraphQlEndpoint::class)]
#[CoversClass(GraphQlRouter::class)]
final class SchemaFactoryFieldTypeAuthorityTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        SchemaFactory::resetCache();
        EntityType::clearFromClassCache();
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerCoreEntityType(EntityType::fromClass(ArticleSchemaFixture::class));
    }

    #[Test]
    public function build_consults_the_injected_registry(): void
    {
        $factory = new SchemaFactory(
            entityTypeManager: $this->entityTypeManager,
            fieldTypes: new FieldTypeManager(directories: []),
        );

        // Object-type fields are lazy thunks; loading the type resolves them,
        // which is what reaches the wire-type mapper and therefore the registry.
        $schema = $factory->build();

        $this->expectException(UnknownFieldTypeException::class);
        $schema->getType('Article');
    }

    #[Test]
    public function endpoint_threads_the_registry_into_the_schema_factory(): void
    {
        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<string> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $endpoint = new GraphQlEndpoint(
            entityTypeManager: $this->entityTypeManager,
            accessHandler: new EntityAccessHandler(),
            account: new AuthorizationPrincipal(0, false, [], [], 'anonymous'),
            logger: $logger,
            fieldTypes: new FieldTypeManager(directories: []),
        );

        // Selecting an Article field resolves the type's field thunk inside
        // execution; the endpoint reports the refusal as a logged 500.
        $result = $endpoint->handle('POST', json_encode(['query' => '{ article(id: "1") { id } }'], JSON_THROW_ON_ERROR), []);

        self::assertSame(500, $result['statusCode']);
        self::assertStringContainsString(
            'No registered field-type schema authority exists for "string"',
            implode("\n", $logger->messages),
        );
    }

    #[Test]
    public function router_accepts_the_registry_for_its_endpoint(): void
    {
        $parameters = new \ReflectionMethod(GraphQlRouter::class, '__construct')->getParameters();
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $parameters);

        self::assertContains('fieldTypes', $names);
    }
}
