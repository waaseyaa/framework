# Convention: Cache Tags and Contexts

## Overview

Waaseyaa caches listing results, render fragments, and (in time) HTTP
responses against a shared **tag vocabulary** and a fixed **context
registry**. This document codifies both. The strings here are
load-bearing — every consumer relies on them, and they are covered by
the stability charter's deprecation policy alongside the PHP types they
flank. For the corresponding stable surface, see
[`docs/specs/stability-charter.md`](../specs/stability-charter.md) §5.9.

The listing pipeline (mission `listing-pipeline-v1-01KRMN0B`, charter
§5.6) is the first consumer; future render-cache and surrogate-cache
work will share the same vocabulary unchanged.

---

## 1. Tag-string format

Every cache tag is a lowercase, colon-segmented string:

```
^[a-z][a-z0-9_:.-]*$
```

- First character: `[a-z]`.
- Subsequent characters: lowercase letters, digits, `_`, `:`, `.`, `-`.
- No whitespace. No uppercase. No leading digits.

`TaggedCacheInterface::setWithTags()` enforces this regex at insertion
time and throws `Waaseyaa\Cache\Exception\InvalidCacheTagException` on
violation. There is no silent normalisation — the contract is "valid
tag in, or no tag at all".

---

## 2. Canonical tag vocabulary

The framework reserves three tag shapes for entity invalidation. Apps
and extensions MAY add their own tag namespaces; the framework MUST
NOT silently change these.

| Tag shape | Meaning | Emitted by |
|---|---|---|
| `entity:<type>` | Any change to any entity of `<type>` | `ListingCacheInvalidator` on every `AfterSaveEvent` / canonical repository `POST_DELETE` |
| `entity:<type>:<id>` | A specific entity has changed | Same, scoped to the changed id |
| `entity:<type>:<id>:<langcode>` | A specific translation of a specific entity has changed | Same, when the entity type implements `TranslatableInterface` (M-006); langcodes come from `AfterSaveEvent::$affectedLangcodes` |

Examples:
- `entity:node` — every node-typed entry should be evicted.
- `entity:node:42` — entry for node #42 should be evicted.
- `entity:node:42:fr` — French translation of node #42 should be evicted.

`<type>` is the `EntityType::id` (e.g. `node`, `event`, `user`). `<id>`
is the entity primary key as a string. `<langcode>` is the BCP-47
fragment used by `LanguageManagerInterface` (`en`, `fr`, `oj-Cans`,
etc.).

### When to add a new tag namespace

Add a new namespace when the framework cannot reach the invalidation
trigger through `AfterSaveEvent` / canonical repository `POST_DELETE`. Examples:

- A search-index rebuild that no entity write captures (`search:index:reset`).
- A tenant-wide configuration flip (`tenant:<id>:config`).

Document the new namespace in this file in the same PR that introduces
it, and amend charter §5.9 if the tag becomes part of stable surface
(i.e. consumers will branch on it).

---

## 3. Context-name format

Context names are dot-segmented lowercase strings:

```
^[a-z][a-z0-9_.]*$
```

Unlike tags, contexts do not use `:` or `-` — the separator is `.`
because contexts are hierarchical name spaces (`user.roles` is a
sub-namespace of `user`), not composite keys.

---

## 4. Canonical context names

The `Waaseyaa\Cache\ContextNames` constants enumerate the framework's
whitelisted contexts. The `ContextRegistry` accepts these out of the
box; unknown context names appearing in a `ListingResult::$cacheContexts`
cause the resolver to bypass the cache for that resolution (and log a
warning), they do not throw.

| Constant | Value | Resolves to |
|---|---|---|
| `ContextNames::USER_ROLES` | `'user.roles'` | Sorted role IDs of the active account, joined with `,` (e.g. `editor,member`). Anonymous → empty string. |
| `ContextNames::USER_ID` | `'user.id'` | Integer account id as a decimal string. Anonymous → `0`. |
| `ContextNames::LANGUAGE_CONTENT` | `'language.content'` | Active content language code (e.g. `en`, `fr`, `oj-Cans`). |
| `ContextNames::LANGUAGE_INTERFACE` | `'language.interface'` | Active UI / interface language code (often equal to content language; may differ in multi-language admin SPAs). |
| `ContextNames::URL_QUERY_PREFIX` | `'url.query.'` | Prefix sentinel — concatenate the parameter name (e.g. `ContextNames::URL_QUERY_PREFIX . 'page'` → `'url.query.page'`). Each context resolves to the URL-decoded value of that single query parameter. |

`ContextResolver::resolve(string $context, RequestContext $request): string`
is the canonical resolution path. It returns a deterministic short
string per request; identical requests produce identical cache keys.

---

## 5. Resolver semantics

`ContextResolver` is a small, side-effect-free service. The contract:

- Pure function of `(context name, request context)`.
- Deterministic — same inputs always return the same output.
- Returns `string`, never `null`. Missing values resolve to empty string.
- Never throws on resolution-time inputs. Throws are reserved for
  definition-time validation (e.g. `LanguageManagerInterface` not bound).

The `RequestContext` interface (in `Waaseyaa\Foundation\Http`) is the
seam between the cache layer and the underlying HTTP stack. Concrete
implementations exist for the native HTTP kernel, PSR-7 adapters, and
CLI test fixtures — the resolver never reads `$_GET`, `$_SESSION`, or
PHP superglobals directly.

---

## 6. How to register a custom context name

Apps and extensions can register new context names through
`ContextRegistry::register()` during boot. Once registered, a listing
may declare the context in its `cacheContexts()` and the resolver will
delegate to a registered callable.

```php
<?php

declare(strict_types=1);

use Waaseyaa\Cache\ContextRegistry;
use Waaseyaa\Cache\ContextResolver;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class TenantContextServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var ContextRegistry $registry */
        $registry = $this->resolve(ContextRegistry::class);
        $registry->register('tenant.id');

        /** @var ContextResolver $resolver */
        $resolver = $this->resolve(ContextResolver::class);
        $resolver->extend(
            'tenant.id',
            fn (RequestContext $req): string => (string) ($req->attributes()['tenant_id'] ?? ''),
        );
    }
}
```

Choose names that are:

- Hierarchical — `tenant.id`, not `tenantId`.
- Stable — once a listing depends on the context, renaming it
  invalidates every cached entry tagged with the old name.
- Documented — list them in your package's README and reference this
  file.

---

## 7. How to invalidate (event listener pattern)

`ListingCacheInvalidator` (internal, mission-owned) is the reference
example. It subscribes to `AfterSaveEvent`, canonical repository `POST_DELETE`, and
standalone typed `AfterDeleteEvent` notifications,
computes the affected tags from the event's entity + `$affectedLangcodes`,
and calls `TaggedCacheInterface::invalidateByTag()` for each tag. The
listener is best-effort: failures log via `LoggerInterface` at warning
level and never raise.

To invalidate from your own subsystem, follow the same shape:

```php
<?php

declare(strict_types=1);

namespace App\Cache;

use Waaseyaa\Cache\TaggedCacheInterface;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

final class TenantConfigInvalidator
{
    public function __construct(
        private TaggedCacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function onTenantConfigSaved(TenantConfigSavedEvent $event): void
    {
        try {
            $this->cache->invalidateByTag(sprintf('tenant:%s:config', $event->tenantId));
        } catch (\Throwable $e) {
            $this->logger->warning('Tenant config invalidation failed', ['exception' => $e]);
        }
    }
}
```

---

## 8. Decision tree — when do I add a new tag or context?

1. **The data already invalidates on an entity write** → no new tag.
   Use `entity:<type>` or `entity:<type>:<id>`.
2. **The data invalidates on a translation write** → use
   `entity:<type>:<id>:<langcode>`. Make sure your entity type
   implements `TranslatableInterface` (M-006).
3. **The data varies per user** → declare `user.roles` and/or
   `user.id` as a *context*, not a tag. Contexts segment the cache key;
   tags invalidate it.
4. **The data varies per URL parameter** → declare
   `url.query.<param>` as a context. Add the parameter name to the
   listing's exposed filters so the parser populates it.
5. **The data is invalidated by a domain event with no entity tie-in**
   (e.g. a search index rebuild, a tenant-config flip) → add a new tag
   namespace under §2 and a listener that emits `invalidateByTag()`.
6. **The data varies on something the framework doesn't already
   expose** (e.g. an A/B test bucket, a feature flag) → register a new
   context name via §6.

---

## 9. References

- [`docs/specs/listing-pipeline-v1.md`](../specs/listing-pipeline-v1.md)
  §3.9 — `TaggedCacheInterface` contract.
- [`docs/specs/listing-pipeline-v1.md`](../specs/listing-pipeline-v1.md)
  §3.10 — invalidation flow on `AfterSaveEvent` / canonical repository `POST_DELETE`.
- [`docs/specs/stability-charter.md`](../specs/stability-charter.md)
  §5.9 — stable-surface declaration for this vocabulary.
- [`docs/cookbook/listing-first-cut.md`](../cookbook/listing-first-cut.md)
  — applied example of these conventions in a working listing.
- [ADR 015](../adr/015-listing-pipeline-views-equivalent.md)
  §Consequences — the decision that committed the framework to a
  cache-tags-and-contexts architecture.
