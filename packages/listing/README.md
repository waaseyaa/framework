# Waaseyaa Listing

Declare access-filtered entity listings with deterministic sorting, exposed URL
filters, pagination and optional tagged caching. Application providers implement
`HasListingsInterface`; the listing provider discovers them through foundation's
live capability source and validates definitions after all provider boots.

```php
$definition = new \Waaseyaa\Listing\ListingDefinition(
    id: 'published_articles',
    entityType: 'article',
    filters: [\Waaseyaa\Listing\Filter::eq('status', 1)],
    sorts: [\Waaseyaa\Listing\Sort::desc('id')],
    pageSize: 20,
);
$values = $parser->parse($queryParams, $definition);
$result = $resolver->resolve($definition, $values);
foreach ($result->rows as $row) {
    // Application-owned presentation.
}
```

The result exposes `rows`, `pagination`, `cacheTags` and `cacheContexts` as
readonly properties. All filters are conjunctive. Text starts/contains use
Unicode case folding and literal percent/underscore characters. Typed date
overrides remain validated scalar strings in the application's ISO storage
format. Permissive parsing keeps declared defaults after invalid input; strict
parsing raises `ListingCoercionException`.

Cache backends are host supplied. Result caching requires an explicit
policy-independent default-view capability on the injected gate. Ordinary
policy-dependent listings evaluate current access before pagination each time.
Eligible cache hits use canonical batched repository reads, preserve ordering,
and refresh if an identifier is missing. Repository after-commit notifications
own save/delete cache eviction. The production `EntityAccessGate` uses current
per-row policy checks; setting a policy constant alone does not opt it in.

Listing owns read/query policy, not entity writes, schemas, routes or rendering.
See the framework's `docs/cookbook/listing-first-cut.md` and
`docs/specs/listing-pipeline-v1.md` for composition and contracts. Source suites
do not substitute for installed split-package or generated-application proof.

Translatable identifier projections require a single effective langcode equality.
Mixed-language scopes resolve fresh so translations sharing an identifier cannot
be substituted during cache reconstruction.
