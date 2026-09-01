<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Waaseyaa\GraphQL\Schema\SchemaFactory;
use Waaseyaa\Tests\Integration\GraphQL\Entity\TestArticle;

/** Simulates two kernel compositions served sequentially by one PHP process. */
#[CoversClass(SchemaFactory::class)]
final class GraphQlSchemaCacheCompositionIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        SchemaFactory::resetCache();
    }

    #[Test]
    public function the_second_endpoint_observes_only_its_compositions_fields(): void
    {
        $first = $this->endpoint($this->managerWithFields(['first_only']));
        $second = $this->endpoint($this->managerWithFields(['second_only']));

        $firstFields = $this->introspectFields($first);
        $secondFields = $this->introspectFields($second);

        self::assertContains('first_only', $firstFields);
        self::assertNotContains('second_only', $firstFields);
        self::assertNotContains('first_only', $secondFields);
        self::assertContains('second_only', $secondFields);
    }

    private function endpoint(EntityTypeManager $manager): GraphQlEndpoint
    {
        return new GraphQlEndpoint(
            $manager,
            new EntityAccessHandler([]),
            new AuthorizationPrincipal('audit', true, ['authenticated'], [], 'audit'),
        );
    }

    /** @return list<string> */
    private function introspectFields(GraphQlEndpoint $endpoint): array
    {
        $body = json_encode([
            'query' => '{ __type(name: "AuditArticle") { fields { name } } }',
        ], JSON_THROW_ON_ERROR);
        $response = $endpoint->handle('POST', $body)['body'];

        self::assertArrayNotHasKey('errors', $response);

        return array_map(
            static fn(array $field): string => $field['name'],
            $response['data']['__type']['fields'],
        );
    }

    /** @param list<string> $fieldNames */
    private function managerWithFields(array $fieldNames): EntityTypeManager
    {
        $fields = [];
        foreach ($fieldNames as $fieldName) {
            $fields[$fieldName] = new FieldDefinition(name: $fieldName, type: 'string');
        }

        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerCoreEntityType(new EntityType(
            id: 'audit_article',
            label: 'Audit Article',
            class: TestArticle::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            _fieldDefinitions: $fields,
        ));

        return $manager;
    }
}
