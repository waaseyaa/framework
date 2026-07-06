# Upgrade Notes: Unicode-Safe `ingest:run` Key Normalization

**Introduced in:** audit A7 finding F7 fix (branch `fix/ingest-normalize-key-unicode`, 2026-07).
**Subsystem:** `packages/cli/src/Handler/IngestRunHandler.php` (`ingest:run` command).
**Spec linkage:** [`../specs/ingestion-defaults.md`](../specs/ingestion-defaults.md) (envelope contract; key normalization itself is not spec-governed).

---

## Summary

`ingest:run` previously normalized record keys with a byte-level, ASCII-only
pipeline. A title written entirely in a non-Latin script (for example Canadian
Aboriginal Syllabics) collapsed to an empty key, and one empty-key record
failed the entire batch (exit 1, zero nodes emitted).

Key normalization is now Unicode-safe:

1. Pure-ASCII input runs through the original lowercase-and-collapse pass
   unchanged. Existing ASCII keys are byte-identical before and after this
   upgrade.
2. Non-ASCII input is first transliterated with ICU's
   `Any-Latin; Latin-ASCII` transform when the `intl` extension is available,
   so diacritic titles resolve to readable Latin keys.
3. Input that still normalizes to nothing (syllabics with no Latin mapping, or
   `intl` unavailable) falls back to a deterministic hash-derived key:
   `k` plus the first 16 hex characters of the SHA-256 of the NFC-normalized
   title. The same title always yields the same key.

Only a genuinely empty or whitespace-only source title/key still produces an
empty key.

**Net effect for pipelines that only ever ingested ASCII titles/keys:** zero.
No key changes, no action required.

---

## 1. Key drift for previously-mangled non-ASCII titles

A diacritic title that previously survived normalization in mangled form now
produces a DIFFERENT key after this upgrade:

| Title | Old key | New key (with `intl`) |
|---|---|---|
| `Wâsēyâa Ziibi` | `w_s_y_a_ziibi` | `waseyaa_ziibi` |
| `Café` | `caf` | `cafe` |
| `ᐊᓂᔑᓈᐯᒧᐎᓐ` | (empty, batch failed) | `k79e16c7e13db8628` |

If any downstream consumer keys on the emitted `key`, on relationship `key`
values (`<from>_to_<to>_<type>`), or on `source_ref` (`<source>#<key>`), a
re-ingest of the same input after upgrading will REKEY those records. Treat
the first post-upgrade ingest of a batch containing non-ASCII titles as a
rekey event: reconcile by title or by explicit `key`/`source_uri` fields, not
by the derived key.

To avoid derived-key drift entirely, provide explicit `key` (structured input)
or `source_uri` values; explicit values are used verbatim and are unaffected
by this change.

Note that the new key shape also depends on whether `intl` is available at
run time: with `intl`, `Wâsēyâa Ziibi` becomes `waseyaa_ziibi`; without it,
the same title becomes a `k<hash>` key. Run ingestion on a consistent PHP
build if derived keys feed downstream identity.

---

## 2. Dangling non-Latin relationship targets: warning becomes hard error

Before this fix, a relationship whose `to` target was a non-Latin string
normalized to an empty key and was skipped with a WARNING
(`relationship with missing target/type; skipped`), exiting 0.

After this fix, that target normalizes to a real key. If no node in the batch
carries that key, the relationship is now a hard ERROR
(`Relationship target key missing: <key>`) and the batch exits 1. This is the
same semantics ASCII targets have always had; non-Latin targets are no longer
silently dropped. Fix the input (add the missing record, or correct the
target) rather than relying on the old silent skip.

---

## 3. Refresh-baseline diffs on first post-upgrade run

The semantic refresh snapshot embeds item `source_uri` values, which for
records without explicit `source_uri`/`key` are derived from the normalized
title (`item://<key>`). Because non-ASCII titles now normalize differently,
the FIRST `--refresh-baseline` comparison after upgrading may flag
`refresh.provenance_change` / item-identity changes that reflect the new
normalization, not real content drift. Regenerate the baseline
(`--refresh-snapshot-output`) once after upgrading and use that as the new
reference point.

Related: when two distinct titles transliterate to the same fallback
`source_uri` (for example `Café` and `Cafe` both mapping to `cafe`), the
fallback is now salted deterministically by record order (`item://cafe`,
`item://cafe_2`) so the batch no longer fails with
`schema.duplicate_source_uri`. Explicit duplicate `source_uri` values remain
a schema error.

---

## Recommendation: install `ext-intl`

`waaseyaa/cli` now suggests `ext-intl`. Without it the pipeline still works
(hash-key fallback), but diacritic titles get opaque `k<hash>` keys instead of
readable Latin ones. Installing `intl` before the first post-upgrade ingest
gives the most stable and most readable derived keys.
