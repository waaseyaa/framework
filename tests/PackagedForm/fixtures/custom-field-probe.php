<?php

declare(strict_types=1);

/**
 * Packaged downstream custom field admission proof (#2786 / #2809).
 *
 * This runs only inside a disposable consumer whose installed bytes come from
 * the candidate tree. It exercises the real manifest compiler, boot-scoped
 * FieldTypeManager, entity admission, API schema presenter, and GraphQL wire
 * adapter. The same probe is run once with Composer dev dependencies and once
 * with an optimized --no-dev install.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\GraphQL\GraphQlEndpoint;

try {
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);

    $manifest = $kernel->getManifest();
    if (($manifest->fieldTypes['fixture_custom_money'] ?? null) !== 'Fixture\CustomField\CustomMoneyFieldType') {
        throw new RuntimeException('The compiled package manifest did not discover the downstream #[FieldType].');
    }
    if (!is_file(__DIR__ . '/storage/framework/packages.php')) {
        throw new RuntimeException('The compiled package manifest cache is missing.');
    }
    $cachedManifest = require __DIR__ . '/storage/framework/packages.php';
    if (($cachedManifest['field_types']['fixture_custom_money'] ?? null) !== 'Fixture\CustomField\CustomMoneyFieldType') {
        throw new RuntimeException('The persisted package manifest did not retain the downstream field type.');
    }

    $fieldTypes = $kernel->getFieldTypeManager();
    if (!$fieldTypes->hasDefinition('fixture_custom_money')) {
        throw new RuntimeException('The boot-scoped field-type manager did not admit the downstream field type.');
    }

    $entityTypes = $kernel->getEntityTypeManager();
    if (!$entityTypes->hasDefinition('fixture_custom_product')) {
        throw new RuntimeException('The downstream provider did not register the attributed entity type.');
    }
    $productType = $entityTypes->getDefinition('fixture_custom_product');
    $fields = $entityTypes->resolveFieldDefinitions('fixture_custom_product');
    $price = $fields['price'] ?? null;
    if ($price === null || $price->getType() !== 'fixture_custom_money') {
        throw new RuntimeException('Entity admission did not retain the explicit downstream field type.');
    }
    $registry = $entityTypes->getFieldRegistry();
    if (!isset($registry->coreFieldsFor('fixture_custom_product')['price'])) {
        throw new RuntimeException('The canonical field-definition registry did not admit the downstream field.');
    }

    $fieldSchemaAuthority = $kernel->getHttpServiceResolver()->resolve(\Waaseyaa\Field\FieldSchemaAuthority::class);
    if (!$fieldSchemaAuthority instanceof \Waaseyaa\Field\FieldSchemaAuthority) {
        throw new RuntimeException('The boot-scoped field schema authority was not resolvable.');
    }
    $canonicalSchema = $fieldSchemaAuthority->entitySchema($productType, $fields);
    if (($canonicalSchema['properties']['price']['type'] ?? null) !== 'string'
        || ($canonicalSchema['properties']['price']['pattern'] ?? null) !== '^-?[0-9]+\\.[0-9]{2}$') {
        throw new RuntimeException('Canonical entity schema did not project the downstream field-type JSON schema.');
    }
    $apiSchema = new SchemaPresenter(
        fieldDefinitionRegistry: $registry,
        fieldSchemas: $fieldSchemaAuthority,
    )->present($productType, $fields);
    $priceSchema = $apiSchema['properties']['price'] ?? null;
    if (!is_array($priceSchema) || ($priceSchema['type'] ?? null) !== 'string') {
        throw new RuntimeException('API schema did not expose the admitted downstream field.');
    }

    $endpoint = new GraphQlEndpoint(
        entityTypeManager: $entityTypes,
        accessHandler: $kernel->getAccessHandler(),
        account: new AuthorizationPrincipal(1, true, ['administrator'], [], 'packaged-field-proof'),
        fieldTypes: $fieldTypes,
    );
    $result = $endpoint->handle('POST', json_encode(['query' => '{ __type(name: "FixtureCustomProduct") { fields { name type { name kind } } } }'], JSON_THROW_ON_ERROR));
    if ($result['statusCode'] !== 200 || isset($result['body']['errors'])) {
        throw new RuntimeException('GraphQL introspection failed: ' . json_encode($result['body'], JSON_THROW_ON_ERROR));
    }
    $graphqlFields = $result['body']['data']['__type']['fields'] ?? [];
    $priceGraphql = null;
    foreach ($graphqlFields as $field) {
        if (($field['name'] ?? null) === 'price') {
            $priceGraphql = $field;
            break;
        }
    }
    if (($priceGraphql['type']['name'] ?? null) !== 'String' || ($priceGraphql['type']['kind'] ?? null) !== 'SCALAR') {
        throw new RuntimeException('GraphQL did not project the downstream field as String.');
    }

    fwrite(STDOUT, "packaged custom field admission OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '::error::packaged custom field admission FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
