# Cookbook: Configuration Sync (CMI)

**Audience:** application authors and operators who need multi-environment
configuration promotion (dev → staging → production) for Waaseyaa apps.
**Substrate:** `waaseyaa/config` + `waaseyaa/cli` (M-003).
**Spec:** [`docs/specs/config-management.md`](../specs/config-management.md).
**Charter:** [`stability-charter.md`](../specs/stability-charter.md) §5.5.
**Governing ADR:** [ADR 018](../adr/018-configuration-management-sync.md).

This guide walks the end-to-end CMI workflow:

1. Set up a sync store in a new app.
2. Export the active store to YAML.
3. Edit, diff, and validate before importing.
4. Import in dependency order.
5. Roll back a bad import.
6. **Per-environment overrides** — the load-bearing pattern operators must
   internalise.

By the end you'll have a versioned `storage/config-sync/` desired-state bundle,
a provider-neutral CI gate that validates it before promotion, and a
deterministic path back to a known-good bundle. Git commands appear only as one
example; Waaseyaa requires no forge or source-hosting provider.

---

## Step 1 — Confirm the substrate is wired

CMI ships in `waaseyaa/config` (Layer 1) and `waaseyaa/cli` (Layer 6). If your
app uses `waaseyaa/framework` or `waaseyaa/cms`, both are already on your
classpath.

Verify:

```bash
# All six commands appear under bin/waaseyaa list
bin/waaseyaa list | grep config:
# Expected:
# config:diff
# config:export
# config:import
# config:reset
# config:status
# config:validate
```

If any are missing, run `bin/waaseyaa optimize:manifest` (or restart your dev
server) to refresh attribute discovery.

---

## Step 2 — Choose the sync-store location

The default is `storage/config-sync/` resolved relative to the project root.
Preserve this directory in your chosen source-control or immutable artifact
system so the team shares one reviewed desired state.

`config/waaseyaa.php`:

```php
return [
    'config' => [
        // Default: 'storage/config-sync/'. Override only if you have a strong reason.
        'sync_path' => 'storage/config-sync/',
    ],
    // ...
];
```

For a Git-based project, one possible setup is:

```bash
mkdir -p storage/config-sync
git add storage/config-sync/.gitkeep
git commit -m "chore: add config sync store"
```

> **Why version it?** The sync store is reviewed desired state. A change to
> `storage/config-sync/role.editor.yml` can be reviewed in any VCS or artifact
> workflow; the matching guarded import promotes it to the active generation.

---

## Step 3 — Export the active store

In development, edit config entities through the admin UI / API as usual.
When you're ready to promote, dump everything to YAML:

```bash
bin/waaseyaa config:export
# 12 created, 3 updated, 41 unchanged.
```

Each config entity becomes one file under `storage/config-sync/`:

```
storage/config-sync/
├── role.admin.yml
├── role.editor.yml
├── role.member.yml
├── taxonomy_vocabulary.tags.yml
├── taxonomy_vocabulary.community_categories.yml
└── …
```

Useful flags:

- `--diff` — write only files whose content would change (preserves stable,
  low-noise diffs in any versioning system).
- `--dry-run` — preview the writes without touching the filesystem.

The output format is stable; CI consumers can grep for the summary line.

---

## Step 4 — Inspect with `config:status` and `config:diff`

Before pushing, see what's actually different:

```bash
bin/waaseyaa config:status
#       in-sync : 51
#         drift : 3
#     sync-only : 0
#   active-only : 0
#
#   role.editor                drift
#   taxonomy_vocabulary.tags   drift
#   menu.main                  drift
```

Drill into a specific entity:

```bash
bin/waaseyaa config:diff role.editor
# ─── active ───┐
# ┌── sync ───
# @@ -3,7 +3,7 @@
#    entity_type: role
#    langcode: en
#    uuid: 0193-abc-...
# -label: Editor
# +label: Site Editor
#  permissions:
#    - edit content
#    - publish content
```

Machine-parseable output for CI:

```bash
bin/waaseyaa config:status --format=json | jq .
```

`config:diff` exits non-zero when differences exist; wire it into a pre-deploy
guard to refuse drift you didn't intend.

---

## Step 5 — Validate before importing

`config:validate` runs the same `FieldDefinition::validators()` pipeline the
runtime uses, but against the sync files:

```bash
bin/waaseyaa config:validate
# role.editor                  ok
# taxonomy_vocabulary.tags     ok
# menu.main                    FAILED
#   - links[3].title: must not be empty
#   - links[3].url:   must be a valid path
# Exit: 1
```

Run the same command in any CI or build system as a preflight gate:

```bash
bin/waaseyaa config:validate
```

A failed validate blocks the import. Don't let bad YAML reach staging.

---

## Step 6 — Import (successor-gated)

`config:import` walks the dependency graph (declared via
`ConfigDependencyInterface::configDependencies()`) and computes the intended
change set. In explicit test profiles the legacy apply hook can exercise that
path. Production import is deliberately refused until CFG-02 installs atomic
generation activation and CFG-03 installs schema, manifest, compatibility, and
drift authorization.

Dry run on staging first:

```bash
bin/waaseyaa config:import --dry-run
# Would update: role.editor
# Would update: taxonomy_vocabulary.tags
# Would update: menu.main
# 3 entities would change.
```

After those successor gates are installed and authorize the exact bundle, the
same command applies it:

```bash
bin/waaseyaa config:import
# Importing role.editor… ok
# Importing taxonomy_vocabulary.tags… ok
# Importing menu.main… ok
# 0 created, 3 updated, 0 deleted, 0 failed, 51 unchanged.
```

### Flags worth knowing

| Flag | Default | Use |
|---|---|---|
| `--dry-run` | off | Preview without writes; safe to run anywhere. |
| `--delete-orphans` | off | Include active-only entities as deletions in the guarded staged generation. Default is **warn-only** (see below). |
| `--halt-on-error` | off | Test-adapter compatibility option; production generation activation is all-or-nothing. |
| `--no-dependency-check` | off | Skip cycle + missing-dependency analysis only. It never bypasses schema, manifest, drift, activation, or authority gates; every invocation is logged to `config.audit` at `warning` level. |

### Orphan handling

When an entity is present in the active store but **not** in the sync store,
CMI calls it an orphan. The default behaviour is **warn** — log a line per
orphan to `config.audit`, do not delete. Reason: silent data loss after a
careless `config:export` of an incomplete environment is worse than the small
inconvenience of an unwanted entity persisting one extra deploy.

Operators who want Drupal-style "the sync store is authoritative" semantics
opt in:

```bash
bin/waaseyaa config:import --delete-orphans
```

The first run after enabling will remove every entity not represented in
sync. Audit `config:status` output first.

---

## Step 7 — Roll back a single entity (`config:reset`)

When a single entity drifted (manual edit in the admin UI, hot-fix in
production, etc.) and you want to snap it back to the sync-store value:

```bash
bin/waaseyaa config:reset role.editor
# This will overwrite role.editor in the active store with the sync value.
# Continue? [y/N]
```

Or skip the prompt in CI:

```bash
bin/waaseyaa config:reset role.editor --yes
```

Every reset logs to `config.audit` with the actor, the before-after summary,
and a timestamp. Wire that channel into your log aggregator so post-incident
reviews can replay manual interventions.

`config:reset` is per-entity. To reset everything, run `config:import`.

---

## Step 8 — Handle conflicts

When two developers edit the same entity in parallel, the sync-store YAML
file conflicts the same way any source file would. Resolve the YAML conflict
in your version-control or artifact workflow, then run:

```bash
bin/waaseyaa config:validate     # confirm the merged YAML parses + validates
bin/waaseyaa config:diff role.editor  # confirm the merged intent
git add storage/config-sync/role.editor.yml
git commit
```

The format is designed for human-readable diffs: keys are sorted
alphabetically, `_meta` is always first, empty maps render as `{}`. If your
merge produces a noisy diff, you probably mis-ordered keys — run
`config:export --diff` against a clean active store to regenerate canonical
YAML.

---

## Step 9 — Recover from a broken import

If a `config:import` partially applied and you need to back out:

1. **Identify the broken entities.** Read the per-entity error messages from
   the failed run (logged to `config.audit`).
2. **Restore prior YAML.** Materialize a verified prior bundle from your
   version-control or artifact system (for Git, for example,
   `git checkout HEAD~1 -- storage/config-sync/`). The active store still
   contains the partial writes; you are restoring desired intent.
3. **Re-apply.** `bin/waaseyaa config:import`. Per-entity transactions mean
   each entity rolls back independently on failure, so re-applying is
   idempotent.

If a cycle accidentally landed (rare; `ConfigDependencyCycleException` would
have blocked import) and you need to break the wedge to recover:

```bash
bin/waaseyaa config:import --no-dependency-check
```

This bypasses the DAG check, applies entities in filesystem order, and logs
a `warning` to `config.audit`. **Use once, then fix the cycle, then resume
normal imports.**

---

## §10 — Environment boundaries and secret references

CMI does **not** permit environment variables to override deployable active
configuration. Feature flags, workflow settings, endpoints, roles, menus, and
other application behavior belong in the reviewed sync bundle and become part
of one activated SQLite generation. This keeps the active generation complete,
hashable, and explainable.

The bootstrap environment allowlist is narrower. It may select environment
identity, database location, the sync-artifact path, and opaque secret
references. It never carries deployable values into the active store. A
credential-shaped field in a sync artifact must name a reference, not contain
secret bytes:

```yaml
_meta:
  entity_type: integration
  uuid: 0193abc...
  dependencies: []
  langcode: en
id: mail_provider
endpoint: https://api.example.invalid
api_key_env_var: SENDGRID_API_KEY
```

CFG-04 resolves `SENDGRID_API_KEY` through configured custody without exporting
its value into YAML, SQLite generations, manifests, logs, or evidence. A field
named `api_key` is refused; an explicitly typed reference such as
`api_key_env_var` is allowed.

### What goes where

| Value type | Active generation / sync artifact | Bootstrap or custody |
|---|---|---|
| Roles, permissions, menus, taxonomies | Yes | — |
| Workflow states, content types, field bundles | Yes | — |
| Feature flags and external endpoints | Yes | — |
| Database location and environment identity | — | Bootstrap |
| Sync-artifact path | — | Bootstrap |
| Secret reference identifier | Reference only | Bootstrap/custody mapping |
| Secret bytes, private keys, access tokens | Never | CFG-04 custody only |
| Debug posture | — | Bootstrap (`APP_DEBUG`, development profiles only) |

If staging and production intentionally need different deployable behavior,
promote and activate distinct reviewed generations. Do not hide the difference
in a runtime environment overlay.

---

## §11 — Provider-neutral CI gate recipe

Any CI, build runner, or local release process can execute the same shell gate;
Waaseyaa does not require GitHub Actions or any other hosted forge:

```bash
set -eu

composer install --prefer-dist --no-progress
bin/waaseyaa config:validate
bin/waaseyaa config:status --format=json > status.json

if [ "$(jq '.drift' status.json)" != "0" ]; then
    echo 'Unintended configuration drift detected' >&2
    cat status.json >&2
    exit 1
fi
```

Treat successful validation as preflight evidence, not deployment permission.
Production `config:import` remains refused until CFG-02 activation and CFG-03
schema, manifest, compatibility, and drift gates are installed. Your delivery
system invokes the same command only after those gates authorize the exact
bundle; it must not infer authorization from branch names or forge metadata.

---

## §12 — Common questions

### Should I version `storage/config-sync/`?

Yes. Preserve the desired-state bundle in your chosen source-control or
immutable artifact system. Git is common, but neither Git nor GitHub is a
Waaseyaa dependency. If you change `config.sync_path`, version that selected
bundle instead.

### What about secrets?

Never put secret values in the sync store. Assume every desired-state artifact
can be widely read. Store only opaque references and let CFG-04 custody resolve
them at use time. See
[`docs/specs/security-defaults.md`](../specs/security-defaults.md).

### Can I import config from a Drupal site?

Not directly — Drupal's CMI YAML has a different `_meta` shape and uses
different field-type vocabularies. Use the migration platform
([`docs/specs/migration-platform.md`](../specs/migration-platform.md)) instead.

### What about config translation?

Out of scope for M-003. The `_meta.langcode` field exists for forward
compatibility, but every shipped config entity defaults to `en` and CMI does
not yet support per-langcode config files. A future ADR will bridge ADR 017
(per-field translation) and ADR 018 (CMI).

### What happens if a `config:import` is interrupted mid-stream?

At the CFG-01 stage, production import is refused because the atomic activation
and schema/drift gates are not yet installed. CFG-02 replaces sequential
per-entity publication with staged-generation activation and compare-and-swap;
CFG-03 validates the complete manifest before activation. Test-only adapters
may exercise the legacy per-entity path, but that is not the certified
production recovery contract.

### How do I know which entities depend on which?

`bin/waaseyaa config:diff` shows per-entity differences but not the graph.
Inspect `_meta.dependencies` in each YAML file, or read the implementing
class's `configDependencies()` return value. A future ADR may add a
`config:graph` rendering command; today the YAML is the source.

---

## §13 — Pointers

- Spec (canonical): [`docs/specs/config-management.md`](../specs/config-management.md).
- Format conventions: [`docs/conventions/cmi-sync-format.md`](../conventions/cmi-sync-format.md).
- ADR: [`docs/adr/018-configuration-management-sync.md`](../adr/018-configuration-management-sync.md).
- Charter: [`docs/specs/stability-charter.md`](../specs/stability-charter.md) §5.5.
- Upgrade guide entry for the introducing alpha: see [`docs/upgrades/`](../upgrades/).
- Mission archive: [`kitty-specs/config-management-v1-01KRCDEC/`](../../kitty-specs/config-management-v1-01KRCDEC/).
