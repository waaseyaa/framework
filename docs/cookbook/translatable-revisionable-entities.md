# Cookbook — Translatable + Revisionable Entities (Two-Axis Storage)

> **⚠️ Uses the retired M-004 `vid` model in places.** The live two-axis model
> unifies onto `EntityRepository`'s `revision_id` system (not the `vid` surrogate)
> — canonical doctrine is now
> [`../specs/revision-system-unified.md`](../specs/revision-system-unified.md).
> Declare the revision key as `'revision' => 'revision_id'` (not `'vid'`) and edit
> translations via `EntityRepository::saveTranslation()` / `loadTranslation()` /
> `listTranslationRevisions()`; the `vid` / `<entity>__revision` /
> `SaveContext::withTranslations` shapes below are the M-004 design, retained for
> context.
>
> **Operator and integrator guide for entity types that are BOTH revisionable
> AND translatable.** Charter linkage is §5.3.

This cookbook walks the full lifecycle: declaration → save (single + atomic
multi-language) → load → migrate → prune. The worked example is Minoo
`teaching` (Anishinaabemowin pedagogy with English gloss and editorial history
per language).

---

## When to opt in

Two-axis storage is the right choice **only when both flags apply to the same
entity type**:

- Editors need full per-language revision history (not just a "last updated
  per language" field).
- Editors may edit one language without bumping another language's revision
  count.
- Non-translatable fields (e.g. `featured`, `category`) need a single shared
  revision history across all languages.

If only one axis applies:

- **Revisionable only** — see [`migration-first-cut.md`](migration-first-cut.md) and ADR 016.
- **Translatable only** — see [`translating-an-entity-type.md`](translating-an-entity-type.md) and ADR 017.

---

## Step 1: Declare the entity type

```php
use Waaseyaa\Entity\EntityType;

$entityTypeManager->addEntityType(new EntityType(
    id: 'teaching',
    label: 'Teaching',
    class: Teaching::class,
    keys: [
        'id'               => 'tid',
        'uuid'             => 'uuid',
        'label'            => 'title',
        'revision'         => 'revision_id',
        'default_langcode' => 'default_langcode',  // required for translatable
    ],
    revisionable: true,
    translatable: true,
));
```

Both flags default to `false`. Setting **both** to `true` activates the
two-axis code path in `Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver`.

---

## Step 2: Declare translatable fields

```php
use Waaseyaa\Field\FieldDefinition;

return [
    (new FieldDefinition(name: 'title',    type: 'string'))->translatable(),
    (new FieldDefinition(name: 'body',     type: 'text'))->translatable(),
    (new FieldDefinition(name: 'featured', type: 'bool')),                // non-translatable
    (new FieldDefinition(name: 'category', type: 'string'))->storedIn('sql-column'),  // non-translatable
];
```

**Forbidden-backend guard.** Translatable fields routed to backends that cannot
host per-language storage (`vector`, `remote`, custom non-sql backends) raise
`StorageMigrationException::unsupportedTwoAxisField()` at boot. Only
`sql-column` and `sql-blob` are allowed. To embed a translatable field that
also needs a vector representation, split it into two FieldDefinitions and
denormalise on save.

---

## Step 3: Save — single language

```php
$teaching = new Teaching(['title' => 'Teaching about turtles', 'featured' => true]);
$repository->save($teaching, (new SaveContext())->withLangcode('en'));
```

Writes one row to `teaching__translation__revision` for `('en', vid=1)` and
one row to `teaching__revision` (for `featured`, which is non-translatable).

---

## Step 4: Save — atomic multi-language

When an editorial workflow finalises both languages together (e.g. translator
delivery + reviewer approval in the same step), use
`SaveContext::withTranslations()`:

```php
$repository->save($teaching, (new SaveContext())->withTranslations([
    'en' => ['title' => 'Teaching about turtles',         'body' => '...'],
    'oj' => ['title' => 'Mikinaak-gikinoo\'amaadiwin',    'body' => '...'],
]));
```

The driver opens a single transaction, writes one row per langcode, commits
atomically. If any langcode write fails the entire save rolls back —
**no partial state**.

Empty `withTranslations([])` calls are rejected with
`InvalidArgumentException`; use `withLangcode()` for single-language writes.

---

## Step 5: Load — tip and historical

```php
// Tip-load (default): hydrate from latest vid per (entity_id, langcode).
$teaching = $repository->find($tid);

// Specific revision: vid is global across languages.
$historic = $storage->loadRevision($vid);

// Translation list: every translation of an entity, keyed by langcode.
$translations = $repository->findTranslations($teaching);
```

**Non-translatable field fallback.** Asking for a langcode that has no
translation row returns non-translatable fields (`featured`, `category`) from
`teaching__revision`, and translatable fields fall back via the M-006
`translation.fallback_chain` config key.

---

## Step 6: List revisions per language

```php
foreach ($storage->listRevisions($teaching) as $rev) {
    if ($rev->langcode === 'en') {
        // English revisions only.
    }
}
```

Revisions are yielded in monotonic `vid` order across all languages; filter on
`langcode` in the consumer. The
`(entity_id, langcode, vid DESC)` index is optimised for this pattern.

---

## Step 7: Migrate an existing entity type to two-axis

If the entity type is already translatable-only:

```bash
bin/waaseyaa make:storage-migration --add-revisions teaching
```

If already revisionable-only:

```bash
bin/waaseyaa make:storage-migration --add-translations teaching
```

Both flags emit the same two-table output. A no-op promotion (already
two-axis) raises `StorageMigrationException::noOpPromotion()`.

The generated migration file backfills existing rows into the new tables and
preserves existing `vid` ordering. Review the diff before applying.

---

## Step 8: Access policy composition

Reuse the entity's existing `view` / `update` policy across both axes.
Revision-level operations (`view_revision`, `revert_revision`) compose via
`Waaseyaa\Access\Policy\RevisionPolicyComposition`:

```php
use Waaseyaa\Access\Policy\RevisionPolicyComposition;

$composedPolicy = RevisionPolicyComposition::wrap($entityPolicy);
```

For per-language access (e.g. Coordinator sees English history only,
Knowledge-Keeper sees both), implement `ContextAwareAccessPolicyInterface` and
inspect `$context['langcode']` in `access()`:

```php
public function access(string $operation, ?EntityInterface $entity, AccountInterface $account, array $context = []): AccessResult
{
    if ($operation === 'view_revision' && ($context['langcode'] ?? null) === 'oj') {
        return $account->hasPermission('view oj revisions')
            ? AccessResult::allowed()
            : AccessResult::forbidden();
    }
    return AccessResult::neutral();
}
```

Reference fixture: FR-044 in
`tests/Integration/Phase29/MinooTeachingTwoAxisE2ETest.php`.

---

## Performance guidance

### Non-translatable field fallback cost

Every two-axis load reads from `<entity>__revision` (non-translatable fields) AND
`<entity>__translation__revision` (translatable fields, latest-per-langcode).
This is a **fixed two-row read per language requested**. Use the listing
pipeline (M-007) for bulk queries — `TwoAxisFilterResolver` issues a single
joined SQL statement rather than N×2 row reads.

### Multi-language atomic save lock footprint

`SaveContext::withTranslations()` holds a single transaction across N langcodes.
For predictable latency under load:

- Keep multi-language writes to **< 5 langcodes per call** in interactive paths.
- For bulk import, prefer per-langcode `withLangcode()` writes — the eventual
  cross-language consistency window is acceptable for batch jobs.
- Avoid mixing `withTranslations()` with long-running listeners on
  `AfterSaveEvent` (they extend the transaction window).

### Pruning is near-mandatory

Without pruning, `<entity>__translation__revision` grows O(edits × langcodes).
For Minoo `teaching` with 2 languages and ~20 edits/year per entity, this is
manageable. For high-edit entities (e.g. `node`-like content with daily
edits), schedule a pruning job.

The two-axis pruning policy is
`Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy` (distinct from the M-001
`Waaseyaa\EntityStorage\RevisionPruningPolicy` single-axis policy — note the
`Revision\` subnamespace). It expresses per-language retention counts:

```php
use Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy;

$policy = new RevisionPruningPolicy(keepPerLanguage: 20);
$pruner->prune($entityType, $policy);  // keeps the last 20 vids per langcode
```

---

## Troubleshooting

### "unsupportedTwoAxisField" exception at boot

Cause: a translatable field is routed to a backend other than `sql-column` or
`sql-blob` (e.g. `vector`). The exception carries the field name and backend
id in its message. Fix: drop `->translatable()`, change the backend, or split
the field. See ADR 010 §"reserved backend ids" for the backend namespace.

### "historical_revision_write" exception on save

Cause: the save targets a historical (non-tip) revision. Fix: reload the
entity at tip before mutating, or use an explicit revision-restore workflow.
Stable `errorCode`: `'historical_revision_write'` (carried on
`EntityTranslationException::errorCode`).

### Listing returns rows from the wrong language

Cause: the listing query is missing `Filter::langcode($lc)`. The
`TwoAxisFilterResolver` does **not** auto-default to a langcode; consumers must
declare one explicitly. The active `language.content` cache context
auto-injects when the entity type is translatable, so cache tags are correct
even if the query is wrong — meaning stale wrong-language data may be served
from cache. Always declare the langcode filter.

---

## Cross-references

- Canonical spec: [`../specs/entity-storage-two-axis.md`](../specs/entity-storage-two-axis.md)
- Charter: [`../specs/stability-charter.md`](../specs/stability-charter.md) §5.3
- Upgrade notes: [`../upgrade-notes/two-axis-storage.md`](../upgrade-notes/two-axis-storage.md)
- ADRs: [016 — revisions first-class](../adr/016-revisions-first-class.md), [017 — per-field translation](../adr/017-per-field-translation.md)
- Substrates: [translatable](../specs/entity-storage-translations-v1.md), [listing pipeline](../specs/listing-pipeline-v1.md)
- Cache vocabulary: [cache tags and contexts](../conventions/cache-tags-and-contexts.md)
