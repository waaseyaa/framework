<?php

declare(strict_types=1);
// Original-base audit reproducer, f2b2da6dd58092d611ad52f5a2bf0fb95712ffbc.
// Execute against that source root. Exit 0 reproduces the historical defects.
require getcwd() . '/vendor/autoload.php';
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\Gate\Gate;
use Waaseyaa\Cache\ContextRegistry;
use Waaseyaa\Cache\ContextResolver;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Listing\EntityRepositoryRegistry;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\Tests\Contract\Fixtures\AllowAllArticlePolicy;
use Waaseyaa\Listing\Tests\Contract\Fixtures\ArticleEntity;

$events = new EventDispatcher();
$type = new EntityType(id: 'article', label: 'Article', class: ArticleEntity::class, keys: ['id' => 'id', 'label' => 'title']);
$manager = new EntityTypeManager($events);
$manager->registerEntityType($type);
$driver = new InMemoryStorageDriver();
foreach ([['id' => '1','title' => 'Alpha','weight' => 10,'status' => 1],['id' => '2','title' => 'Beta','weight' => 20,'status' => 0]] as $row) {
    $driver->write('article', $row['id'], $row);
}
$repo = V2EntityRepositoryFactory::create($type, $driver, $events);
$resolver = new ListingResolver(new EntityRepositoryRegistry(['article' => $repo]), new Gate([new AllowAllArticlePolicy()]), new ContextResolver(new ContextRegistry()), $manager, new RequestContext());
$cases = [
    'duplicate EQ conjunction' => [[Filter::eq('weight', 10),Filter::eq('weight', 20)], ['2'], []],
    'case-insensitive CONTAINS' => [[Filter::contains('title', 'alpha')], [], ['1']],
    'case-insensitive STARTS_WITH' => [[Filter::startsWith('title', 'al')], [], ['1']],
];
$ok = true;
foreach ($cases as $label => [$filters,$defective,$expected]) {
    $result = $resolver->resolve(new ListingDefinition(id: 'probe', entityType: 'article', filters: $filters));
    $ids = array_map(static fn($row) => (string) $row->id(), $result->rows);
    echo json_encode(['case' => $label,'actual' => $ids,'contract' => $expected,'reproduced' => $ids === $defective]), "\n";
    $ok = $ok && $ids === $defective;
}
try {
    Filter::gte('starts_at', new DateTimeImmutable('2030-01-01'));
    $dateFails = false;
} catch (InvalidArgumentException $e) {
    $dateFails = true;
    echo json_encode(['case' => 'cookbook DateTime declaration','actual' => $e->getMessage()]), "\n";
}
$dateDef = new ListingDefinition(id: 'date_probe', entityType: 'article', filters: [Filter::exposed(Filter::gte('starts_at', '2025-01-01'), 'after')]);
$dateValues = \Waaseyaa\Listing\ExposedFilterParser::create()->withTypeResolver(static fn() => 'datetime')->parse(['after' => '2030-01-01'], $dateDef);
try {
    $resolver->resolve($dateDef, $dateValues);
    $dateBoundaryFails = false;
} catch (InvalidArgumentException $e) {
    $dateBoundaryFails = true;
    echo json_encode(['case' => 'supported parsed date override','parsedType' => get_debug_type($dateValues->get('after')),'resolverError' => $e->getMessage()]), "\n";
}
exit($ok && $dateFails && $dateBoundaryFails ? 0 : 1);
