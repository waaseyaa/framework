# Principal-safe search read surface plan

Issue: #2192

Program: #2198

Prerequisites: #2188 and #2211

## Contract

`SearchProviderInterface::search()` takes the immutable
`AuthorizationPrincipalInterface` as a required argument separate from the
serializable `SearchRequest`. No ambient account context, nullable checker, or
anonymous default exists below HTTP/MCP adapters.

The FTS5 tables are candidate generators only. A raw candidate carries an
opaque document id and entity type, never a title, body, URL, facet, count, or
authorization decision. Before a candidate can affect any observable result,
`SearchCandidateResolverInterface` must produce an immutable
`SearchCandidateProjection` for the supplied principal.

The default resolver registry has two paths:

1. registered entity types use `EntitySearchCandidateResolver`, which validates
   the document id, reloads the canonical served entity, checks entity `view`
   with the supplied principal, binds `AccountFieldReadScopeInterface`, and
   regenerates `toSearchDocument()` / `toSearchMetadata()`; access-denied or
   malformed projections are dropped;
2. non-entity documents require an application-provided resolver registered by
   an exact document-id namespace. Unknown namespaces default-deny. Index
   metadata never selects publicness.

The provider conservatively confirms every query token against the safe title
and body, applies filters to safe metadata, derives facets and totals only from
safe matches, recomputes rank and stable ordering from safe values, and creates
plain-text bounded snippets from the safe body. Plain-text punctuation is
tokenized with the same Unicode word/apostrophe model as the index; caller FTS
syntax is never accepted. Raw FTS
rank, snippets, metadata, and pre-access counts are never returned or logged.

The scan remains bounded at 200 candidates; the entity resolver truncates safe
title/body text to 512 characters / 64 KiB. Hidden/raw candidates can consume the cap and cause an under-count;
they can never increase a returned count. No raw-cap or `hasMore` signal is
exposed, and server duration is omitted from `SearchResult`. End-to-end latency
and cap exhaustion remain documented weak channels of the protected index.
Authorized UTF-8 strings and benign metadata variance are normalized and
bounded before projection; malformed direct projections fail closed, and a
single candidate exception cannot fail the whole query.

## TDD sequence

1. Add red tests for the explicit principal method signature and production
   resolver wiring.
2. Add red entity tests proving restricted labels/bodies, missing or mismatched
   principal scope, view denial, stale rows, and forward drafts fail closed.
3. Add red provider tests proving hidden-only matches, protected metadata,
   counts, facets, rank, snippets, and pagination cannot influence output.
4. Add red resolver-registry tests proving unknown namespaces deny and an
   explicitly registered canonical source can resolve.
5. Implement the immutable reference/projection contracts, entity resolver,
   resolver registry, conservative matcher, safe sorter, and bounded provider.
6. Promote only the supported provider/request/result/projection contracts;
   keep the unused Twig adapter internal and require it to receive a principal.
7. Update the README/changelog and run focused, split, architecture, PHPStan,
   dead-code, package-layer, and full verification gates.

## Deferred

- #2193 owns the optional public HTTP adapter and anonymous-principal creation.
- #2194 owns the MCP `content.search` tool.
- #2197 owns bounded MCP content resources and templates.
- At-rest encryption is not claimed; #2211 classifies the index as protected
  derived storage and pins its raw-reader inventory.
