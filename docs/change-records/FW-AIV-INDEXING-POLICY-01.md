# FW-AIV-INDEXING-POLICY-01: explicit embedding index policy

- Forge mirror: `waaseyaa/framework#3141`
- Program: `FW-PACKAGE-CONVERGENCE-01`, `waaseyaa/framework#3118`
- Base: `576c81b80efd881a267b6e925683a2e35fc01252`
- Package: `waaseyaa/ai-vector`
- Finding: `AIV-DOMAIN-001`

## Problem

`EntityEmbeddingListener` embeds every non-node entity type. It always selects
`title`, `name`, `body`, `description`, and the entity label, then sends the
result to whichever provider is composed. With OpenAI or a non-loopback Ollama
endpoint, undeclared application content can leave the host. The semantic
warmer carries a second indexability rule, so save-time indexing and operator
reconciliation can drift.

## Decision

- `ai.vector_index` is the explicit allowlist. Each entity-type entry declares
  `fields` and whether `allow_external` is true. Missing entity types and a
  missing policy are denied.
- `label` is an explicit field name. There is no implicit label, entity ID, or
  fallback text.
- Built-in providers report whether they may transmit content off-host. OpenAI
  is external. Ollama is local only for literal loopback endpoints. An unknown
  or unclassified provider is treated as external.
- One `EmbeddingIndexPolicy` owns field projection, public-node visibility,
  and provider-egress decisions for both lifecycle indexing and the semantic
  warmer.
- A denied or empty projection never calls the provider and removes the
  entity's existing vector. `semantic:refresh --type=<removed-type>` is the
  reconciliation path after a policy removes a previously indexed type,
  including after the provider itself has been removed.

Example:

```php
'ai' => [
    'vector_enabled' => true,
    'vector_index' => [
        'node' => [
            'fields' => ['label', 'title', 'body', 'description'],
            'allow_external' => false,
        ],
    ],
],
```

## Scope

This candidate adds the policy, provider-egress classification, shared
composition, documentation, and discriminating tests. It does not converge the
duplicate storage contracts or declare the search response surface; those stay
in the second #3141 candidate.

## Acceptance and evidence

- A user-like undeclared entity is deleted from storage and is never sent to
  an embedding provider.
- Only declared fields enter embedding text; undeclared sensitive fields and
  implicit labels do not.
- A provider that may transmit off-host is refused unless the entity-type rule
  explicitly allows external transmission.
- Save-time indexing and semantic refresh use the same policy. Refreshing a
  newly excluded type deletes its existing vectors.
- Invalid policy shapes fail startup with a named, non-sensitive refusal.
- Focused ai-vector unit and affected CLI/integration tests pass, followed by
  governed preflight and exact-candidate review.

## Residual work

- #3141 candidate 2 chooses the canonical storage contract, reconciles public
  declarations and documentation, and declares the search wire contract.
- #3142 owns the embedding execution model and orphaned queue message.
- #3143 owns public semantic-search cost bounds after its security sequencing.
