# Cookbook: Your First Listing

**Audience:** application authors who need a paginated, filtered, cache-aware
list of entities (events, articles, members, anything) on a Waaseyaa app.
**Substrate:** `waaseyaa/listing` (M-007).
**Spec:** [`docs/specs/listing-pipeline-v1.md`](../specs/listing-pipeline-v1.md).
**Charter:** [`stability-charter.md`](../specs/stability-charter.md) §5.6 (listing) + §5.9 (cache tag/context).

This walk-through builds an "upcoming events" listing end-to-end: an
`EventEntity` content type, an `UpcomingEventsListing` `ListingDefinition`,
a controller that resolves it, and a Twig partial that renders the rows.
By the end you'll have:

- Declared a listing on your `ServiceProvider`.
- Resolved it through `ListingResolver`.
- Parsed an exposed `?status=` URL parameter into the query.
- Watched `AfterSaveEvent` invalidate the cache automatically.

The reference fixture this guide mirrors lives at
`packages/listing/tests/Fixtures/EventEntity.php` plus
`tests/Integration/Phase14/ListingPipelineIntegrationTest.php` — keep them
open while you read.

---

## Step 1 — Install `waaseyaa/listing`

Your application's `composer.json`:

```bash
composer require waaseyaa/listing:^0.1.0-alpha.305
```

`waaseyaa/listing` pulls in `waaseyaa/cache` (`TaggedCacheInterface` lives
there) and `waaseyaa/foundation` (`Http\RequestContext`) transitively. No
schema migrations are needed; listings read existing entity storage.

---

## Step 2 — Declare an entity type

Listings operate on entities. If you don't already have one, define an
`EventEntity` and register the entity type with `EntityTypeManager`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class EventEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'event', [
            'id'    => 'id',
            'uuid'  => 'uuid',
            'label' => 'title',
        ]);
    }
}
```

Register it in your `ServiceProvider::register()` (see
[`docs/specs/entity-system.md`](../specs/entity-system.md) for the full
checklist).

---

## Step 3 — Declare a `ListingDefinition`

Apps and packages expose listings by implementing `HasListingsInterface`
on a `ServiceProvider`. Mirror exactly the convention you already use for
`HasNativeCommandsInterface` and `HasMigrationsInterface`:

```php
<?php

declare(strict_types=1);

namespace App;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\Sort;

final class EventsServiceProvider extends ServiceProvider implements HasListingsInterface
{
    public function listings(): array
    {
        return [
            new ListingDefinition(
                id: 'upcoming_events',
                entityType: 'event',
                filters: [
                    Filter::gte('starts_at', (new \DateTimeImmutable('now'))->format('Y-m-d\\TH:i:sP')),
                    // Exposed filter — bound to ?status= in the URL.
                    Filter::exposed(Filter::eq('status', 'published'), 'status'),
                ],
                sorts: [Sort::asc('starts_at')],
                pageSize: 20,
                accessOps: ['view'],
            ),
        ];
    }
}
```

`PackageManifestCompiler` registers the provider classes. Listing's service
provider discovers `HasListingsInterface` providers through foundation's live
capability source and builds `ListingDefinitionRegistry` keyed by `'upcoming_events'`.

---

## Step 4 — Resolve in a controller

Inject `ListingResolver` and `ListingDefinitionRegistry`; resolve the
definition with the exposed values parsed from the request:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Listing\ExposedFilterParser;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\ListingResult;

final class EventsController
{
    public function __construct(
        private ListingDefinitionRegistry $registry,
        private ListingResolver $resolver,
        private ExposedFilterParser $parser,
    ) {
    }

    public function upcoming(Request $request): ListingResult
    {
        $def = $this->registry->get('upcoming_events');
        $values = $this->parser->parse($request->query->all(), $def);

        return $this->resolver->resolve($def, $values);
    }
}
```

`ListingResolver::resolve()` returns a typed `ListingResult` with:

- `rows` — entities matching filters, access-policy-filtered per row.
- `pagination` — `page`, `pageSize`, `totalRows`, `totalPages`,
  `hasPrev`, `hasNext`.
- `cacheTags` — `['entity:event', 'entity:event:42', ...]`.
- `cacheContexts` — `['user.roles', 'url.query.status', 'url.query.page']`.

`?page=N` clamps silently: out-of-range page numbers resolve to page 1 or
the last page. Coercion failures on exposed filters (e.g.
`?status=banana` against a typed boolean field) drop that filter silently
and log a `debug`-level event.

---

## Step 5 — Render in Twig

ADR 013 keeps display in app land — the framework returns rows; your app
renders. A minimal partial:

```twig
{# templates/events/upcoming.html.twig #}
<section class="listing listing--upcoming-events">
  <h1>Upcoming events</h1>

  {% if result.rows is empty %}
    <p>No upcoming events.</p>
  {% else %}
    <ul>
      {% for event in result.rows %}
        <li>
          <a href="/events/{{ event.uuid }}">{{ event.title }}</a>
          <time datetime="{{ event.starts_at|date('c') }}">
            {{ event.starts_at|date('M j, Y') }}
          </time>
        </li>
      {% endfor %}
    </ul>

    {% set pagination = result.pagination %}
    <nav class="pagination">
      {% if pagination.hasPrev %}
        <a href="?page={{ pagination.page - 1 }}">Previous</a>
      {% endif %}
      <span>Page {{ pagination.page }} of {{ pagination.totalPages }}</span>
      {% if pagination.hasNext %}
        <a href="?page={{ pagination.page + 1 }}">Next</a>
      {% endif %}
    </nav>
  {% endif %}
</section>
```

`result.cacheTags` flows into your response's `Surrogate-Control` /
`Cache-Tag` header if you use one — the resolver's own cache hits are
internal. A response cache must also honor all contexts and the application
authorization lifecycle before caching private output.

---

## Step 6 — Automatic cache invalidation

The optional resolver cache stores identifier projections only for listings
whose gate explicitly confirms policy-independent view membership. Other
listings resolve current access before pagination on every call. Eligible
projections are keyed by
`(definition hash, exposed values, context values)`. On every entity write,
`ListingCacheInvalidator` subscribes to `AfterSaveEvent` and
`AfterDeleteEvent` and calls
`TaggedCacheInterface::invalidateByTag('entity:event')` plus
`'entity:event:<id>'` (and `'entity:event:<id>:<langcode>'` for translatable
entities). Next resolution misses the cache, runs the query, and re-stores
the fresh result.

You don't subscribe to anything yourself. The cache stays correct so long
as your entity writes flow through `EntityRepository::save()` /
`delete()` — which is what every Waaseyaa app does anyway.

---

## Step 7 — Test

Listings are deterministic — same definition + same exposed values + same
context = same result. Test with an in-memory storage + `MemoryBackend`
cache:

```php
<?php

declare(strict_types=1);

use App\Entity\EventEntity;
use Waaseyaa\Cache\MemoryBackend;
use Waaseyaa\EntityStorage\InMemoryEntityStorage;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\Sort;

it('returns only upcoming events sorted by start time', function () {
    $storage = new InMemoryEntityStorage('event');
    $storage->save(new EventEntity(['id' => 1, 'title' => 'Past',     'starts_at' => '2020-01-01']));
    $storage->save(new EventEntity(['id' => 2, 'title' => 'Soon',     'starts_at' => '2030-01-01']));
    $storage->save(new EventEntity(['id' => 3, 'title' => 'Later',    'starts_at' => '2031-01-01']));

    $def = new ListingDefinition(
        id: 'upcoming_events',
        entityType: 'event',
        filters: [Filter::gte('starts_at', '2025-01-01T00:00:00+00:00')],
        sorts: [Sort::asc('starts_at')],
        pageSize: 10,
    );

    $result = $resolver->resolve($def);

    expect($result->rows)
        ->toHaveCount(2)
        ->and($result->rows[0]->title)->toBe('Soon');
});
```

---

## Common variations

- **Translatable entity types.** Declare your filter once; the resolver
  injects `Filter::langcode($req->language())` implicitly. Cache tags
  include `entity:<type>:<id>:<langcode>`. See
  `Filter::langcode()` to override.
- **Approximate totals.** Long listings on hot pages can opt out of the
  total count reporting with constructor argument `approximateTotal: true`.
  Current access filtering still scans the candidates before slicing.
  `Pagination::$totalRows` returns `null`; the rest stays correct.
- **Fast-path access.** If your policy is "view = always allow", set
  `public const bool SUPPORTS_LISTING_FAST_PATH = true` on your `AccessPolicy` and the
  per-row loop is short-circuited only when the injected gate implements
  `ListingFastPathProbeInterface` and confirms the capability. The production
  `EntityAccessGate` currently uses per-row checks; a policy constant alone
  does not enable its fast path.
- **Strict exposed filters in tests.** `ExposedFilterParser::strict()`
  raises on coercion failures instead of silently dropping — useful in
  integration tests where you want a `?status=banana` typo to fail loud.

---

## Where to learn more

- [`docs/specs/listing-pipeline-v1.md`](../specs/listing-pipeline-v1.md) —
  every FR, NFR, and behaviour spec.
- [`docs/conventions/cache-tags-and-contexts.md`](../conventions/cache-tags-and-contexts.md) —
  the tag vocabulary and context-name registry that this pipeline depends on.
- [ADR 015](../adr/015-listing-pipeline-views-equivalent.md) — why the
  contract is shaped this way.
- [`docs/specs/stability-charter.md`](../specs/stability-charter.md) §5.6,
  §5.9 — which symbols are stable surface and which are internal.

Typed `date`/`datetime` exposed filters validate date parsing and retain the
input as a scalar string. Use the same ISO storage format for declarations,
URL inputs and field values; the listing parser does not normalize timezones.
Strict mode raises `ListingCoercionException`; permissive mode retains the
declared default when input is invalid.

Translatable identifier projections require a single effective langcode equality.
Mixed-language scopes resolve fresh so translations sharing an identifier cannot
be substituted during cache reconstruction.
