# Changelog fragments

Normal pull requests do not edit `CHANGELOG.md`. Add one consumer-facing
fragment under `changes/unreleased/` instead:

```text
changes/unreleased/<issue>.<unique-slice>.<type>.md
```

Example: `1958.kernel-wiring.fixed.md`. The issue is a positive integer, the
slice is lowercase ASCII words separated by hyphens (and may use additional
dot-separated qualifiers), and the type is one of `added`, `changed`,
`deprecated`, `removed`, `fixed`, or `security`. That is also the canonical
release-section order. The issue plus slice is the fragment identity; it must
be unique even when the type differs. A slice is required so separate PRs for
one issue never compete for the same path.

Each file contains exactly one top-level Markdown list item. Continuation
paragraphs, nested lists, and code blocks are retained byte-for-byte and must be
indented by two spaces. Files must be valid UTF-8 with LF line endings and one
terminal newline:

```markdown
- **Fixed — concise consumer-facing outcome (#1958):** the first paragraph.

  A second paragraph remains part of the same release entry.
```

Run `php bin/changelog-fragments validate` and
`php bin/changelog-fragments render` locally. The governed release cut is the
only ordinary writer of `CHANGELOG.md`. It archives consumed fragments under
`changes/released/<version>/`, writes the versioned section, and uses the same
rendered bytes for the annotated tag.

A direct root-changelog edit is reserved for historical maintenance and
requires `changelog-maintenance: <specific reason>` in the PR body.
Public-surface changes may instead update `docs/upgrades/` or use the existing
`no-changelog: <reason>` override when release prose is genuinely unnecessary.
Those alternatives do not authorize public-surface removals or downgrades; the
exact directive must be in a newly added `removed` or `deprecated` fragment.

## Change examples

Normal feature or fix:

```text
changes/unreleased/2409.page-builder-status.fixed.md
```

Breaking removal: add both
`changes/unreleased/2600.legacy-contract.removed.md` and the migration recipe
under `docs/upgrades/`. A public-surface removal fragment contains the exact
directive required by the stability charter.

Security change:

```text
changes/unreleased/2601.token-oracle.security.md
```

Upgrade-guide-only change: update `docs/upgrades/<guide>.md`; this satisfies
release-note discipline for a public-surface PR, but cannot authorize a
public-surface removal or downgrade.

No release-note change: add a specific PR-body line, for example:

```text
no-changelog: internal test-fixture correction with no consumer-visible effect
```

## Open-PR migration

For an open branch such as Framework PR #2554, retain its product commits and
move only each line it added under root `[Unreleased]` into a uniquely named
fragment of the matching type. Restore `CHANGELOG.md` from the updated base,
rebase normally, validate, and push. No unrelated history rewrite is required.
