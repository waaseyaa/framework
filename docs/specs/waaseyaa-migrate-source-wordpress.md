# WordPress Source Reader — `waaseyaa-migrate-source-wordpress`

**Status:** Draft mission spec (2026-05-11)
**Audience:** framework maintainers; input for Spec Kitty `specify` → `plan` → `tasks` flow
**Mission ID:** M-005
**Package target:** `waaseyaa-migrate-source-wordpress` (separate composer package, separate repository — NOT in `waaseyaa/framework` monorepo)
**Origin:** [ADR 012a](../adr/012a-migration-substrate-in-core.md) §"First-party priority order" — WordPress is the first first-party source-reader package.

**Governing ADR:** [ADR 012a](../adr/012a-migration-substrate-in-core.md).

**Charter linkage:** none directly. This mission ships a consumer of the migration platform substrate (M-002). It does not amend the framework charter.

**Sibling missions:**
- [`migration-platform-v1.md`](migration-platform-v1.md) — **hard prerequisite.** Substrate must be shipped and acceptance criterion 8 ("substrate ready for the WordPress source reader mission to start") must be satisfied before WP01 of this mission begins.
- Independent of M-001, M-003, M-004 (they don't gate this).

---

## 0. Origin

ADR 012a accepted the framework's commitment to ship a migration substrate and first-party source readers as separate packages. WordPress was identified as the highest user-acquisition lever — 40%+ of the web, single well-defined export format (WXR — WordPress eXtended RSS), large frustrated-WP population.

ADR 012a's strategic claim: "Migrate your WordPress site to Waaseyaa in one command" — credible marketing once this mission ships. This mission ships the WordPress reader that operationalizes the claim.

Package shape (per ADR 012a):
- Composer type: standard PHP package (not `waaseyaa-theme` or `waaseyaa-migrate-substrate`).
- Naming: `waaseyaa-migrate-source-wordpress`.
- Registers complete migration definitions via `HasMigrationsInterface`; plugin instances are composed directly into each definition.
- Depends on `waaseyaa/migration` (M-002 substrate) at a minimum tested version.

---

## 1. Goals / non-goals

### 1.1 Goals

1. Parse **WXR (WordPress eXtended RSS)** XML exports into source records via a streaming XML reader (memory-bounded for large dumps).
2. Ship five **source plugin classes** — one per WP entity type that maps cleanly to a Waaseyaa entity:
   - `WordPressPostSource` (posts, pages, custom post types)
   - `WordPressUserSource` (authors)
   - `WordPressCommentSource` (comments)
   - `WordPressMediaSource` (attachments)
   - `WordPressTaxonomySource` (categories, tags, custom taxonomies)
3. Ship three **WP-specific process plugins**:
   - `WordPressShortcodeStrip` — removes `[shortcode]` syntax or rewrites known shortcodes.
   - `WordPressOembedExpand` — resolves oEmbed URLs (YouTube, Vimeo, Twitter) to Waaseyaa media references.
   - `WordPressMediaRewriteUrl` — rewrites `wp-content/uploads/` URLs to Waaseyaa media paths.
4. Ship **default migration definitions** that consumer apps can extend:
   - `wp_users_to_accounts`
   - `wp_terms_to_taxonomy`
   - `wp_media_to_entities`
   - `wp_posts_to_<consumer-defined-entity-type>`
   - `wp_comments_to_engagement`
5. Validate the mission by **importing a real WordPress site** end-to-end into a Waaseyaa consumer app.
6. Ship operator documentation — the "Migrating your WordPress site to Waaseyaa" walkthrough, suitable as a public-facing marketing artifact.

### 1.2 Non-goals

- **Drupal 7 source reader.** Separate sibling mission; separate package.
- **Real-time WordPress sync.** One-shot only, per ADR 012a's incremental-sync deferral.
- **WP plugins' custom data import** (Advanced Custom Fields, WooCommerce, etc.). Out of scope for v1 of this package. Each extension warrants its own follow-up.
- **WordPress admin user authentication preservation** — passwords are bcrypt-hashed in WP; we import users but do NOT preserve passwords. Imported users must reset on first login.
- **Multisite WordPress.** v1 supports single-site only. Multisite is a follow-up.
- **Bidirectional sync.** Strictly inbound (WP → Waaseyaa).

---

## 2. Scope summary

### 2.1 In scope

- WXR XML format parser (streaming, memory-bounded).
- Five source plugin classes (Post, User, Comment, Media, Taxonomy).
- Three WP-specific process plugins (ShortcodeStrip, OembedExpand, MediaRewriteUrl).
- Five default migration definitions (users, taxonomies, media, posts, comments).
- Cross-migration ID resolution (post author → previously-imported user, comment parent → previously-imported post).
- Media file copying — actual asset copy from WP `wp-content/uploads/` source to the Waaseyaa media store; configurable source path (local filesystem or HTTP fetch).
- Configuration via `MigrationDefinition` overrides (consumer apps customize source paths, destination entity types, field mappings).
- Source-plugin conformance — all sources subclass `SourceConformanceTestCase` from M-002.
- Round-trip validation against a real WordPress site export.
- Operator documentation (the "Migrating your WordPress site to Waaseyaa" guide).

### 2.2 Out of scope

(See §1.2 non-goals.)

---

## 3. Functional requirements

Normative requirements use **MUST / SHOULD / MAY** per RFC 2119. Numbered for Spec Kitty tokenization.

### 3.1 WXR parser

- **FR-001** The package MUST provide a streaming WXR XML reader (`WxrReader`) that yields records without eager-loading the full XML into memory.
- **FR-002** The reader MUST support WXR versions 1.0, 1.1, and 1.2.
- **FR-003** The reader MUST recover gracefully from malformed entries (skip with warning) unless `--strict` flag is passed via the migration's source configuration.
- **FR-004** The reader MUST expose a per-record type discriminator (`post`, `user`, `comment`, `attachment`, `term`) so source plugins can filter.

### 3.2 Source plugins

- **FR-005** `WordPressPostSource` MUST implement `SourcePluginInterface` (M-002 FR-001). Yields one `SourceRecord` per WP post (including pages and custom post types — apps filter by `post_type` in process maps).
- **FR-006** `WordPressUserSource` MUST yield one `SourceRecord` per WP user. Each record carries: id, login, email, display_name, registered date, role.
- **FR-007** `WordPressCommentSource` MUST yield one `SourceRecord` per WP comment. Each record carries: id, post_id (parent), author, author_email, content, date, approved.
- **FR-008** `WordPressMediaSource` MUST yield one `SourceRecord` per WP attachment. Each record carries: id, file_path (relative to `wp-content/uploads/`), mime_type, alt_text, caption, parent_post_id.
- **FR-009** `WordPressTaxonomySource` MUST yield one `SourceRecord` per WP term. Each record carries: id, taxonomy_name, name, slug, description, parent_id, post_count.
- **FR-010** Each source MUST implement `sourceIdFor()` returning a deterministic `SourceId` (per M-002 FR-027). Hash inputs: WP entity id + entity type.
- **FR-011** Each source MUST report `supportsQuery(): false` — WXR is not queryable; consumers iterate.
- **FR-012** Each source MUST pass M-002's `SourceConformanceTestCase` without modification.

### 3.3 Process plugins

- **FR-013** `WordPressShortcodeStrip` MUST remove unknown WP shortcodes from text fields. Known shortcodes registered via constructor option are rewritten using user-provided callbacks. Defaults: strip silently.
- **FR-014** `WordPressOembedExpand` MUST resolve oEmbed URLs (YouTube, Vimeo, Twitter, Instagram patterns) embedded in post content. Resolution produces: oembed type, source URL, optional Waaseyaa media-entity reference if a corresponding media entity exists.
- **FR-015** `WordPressMediaRewriteUrl` MUST rewrite `wp-content/uploads/<path>` references in post content to point at the imported Waaseyaa media-entity URLs. Cross-migration ID lookup (M-002 FR-028) resolves the WP attachment id to the imported media UUID.
- **FR-016** All three process plugins MUST be composable in chains (M-002 FR-010).
- **FR-017** Each process plugin's id MUST follow the non-reserved-prefix convention: `wordpress_shortcode_strip`, `wordpress_oembed_expand`, `wordpress_media_rewrite_url`.

### 3.4 Migration definitions

- **FR-018** The package MUST provide a `HasMigrationsInterface` provider returning the five default migrations.
- **FR-019** `wp_users_to_accounts` MUST map WP users to the consumer's account entity. Default fields: login → username, email → email, display_name → display_name, registered → created_at. Password is NOT preserved; imported accounts require password reset on first login.
- **FR-020** `wp_terms_to_taxonomy` MUST map WP terms to taxonomy entities. Hierarchical terms (parent_id) preserved via `LookupProcessor` against the same migration.
- **FR-021** `wp_media_to_entities` MUST map WP attachments to media entities. Source files copied from a configurable path (`source.media_path`) to the Waaseyaa media store (`destination.media_store`). Default: local filesystem → local filesystem.
- **FR-022** `wp_posts_to_<entity_type>` MUST be a template that consumer apps parameterize with their target entity type. The package ships an example for `wp_posts_to_articles` and documents the customization path.
- **FR-023** `wp_comments_to_engagement` MUST map WP comments to the consumer's engagement entity (or skip if the consumer has none). Default destination: the consumer's `comment` entity type if one exists.
- **FR-024** Each migration MUST declare dependencies via `MigrationDefinition::dependencies`. Standard order: users → taxonomies → media → posts → comments.
- **FR-025** All default migrations MUST be overridable. Consumer apps register their own `MigrationDefinition` with the same id to override; alternatively, they fork by giving their migration a different id and ignoring the default.

### 3.5 Media handling

- **FR-026** Media file copying MUST be idempotent. Re-import of an already-copied file: no-op, no overwrite, no error.
- **FR-027** Media source path MUST support both local filesystem (`/path/to/wp-content/uploads/`) and HTTP URLs (`https://example.com/wp-content/uploads/`). HTTP source uses streaming download with retry.
- **FR-028** Failed media copies MUST be recorded as per-record errors (M-002 FR-046) but MUST NOT halt the run unless `--halt-on-error`.
- **FR-029** Source file integrity SHOULD be verified via size + optional hash if WXR exposes it (varies by WP version).

### 3.6 Cross-migration ID resolution

- **FR-030** `wp_posts_to_<entity_type>` MUST resolve `post_author` to an already-imported account via `LookupProcessor` against `wp_users_to_accounts`.
- **FR-031** `wp_comments_to_engagement` MUST resolve `comment_post_ID` and `comment_parent` via lookups against `wp_posts_to_<entity_type>` and itself.
- **FR-032** `wp_media_to_entities` MUST resolve `post_parent` (the attaching post) via lookup.
- **FR-033** Missing lookups (referenced WP id not found in id-map) MUST be recorded as per-record warnings and the field left null or zero, depending on destination-field nullability.

### 3.7 Error model

- **FR-034** The package MUST ship typed exceptions on its stable surface:
  - `WxrParseException` — XML parse failure.
  - `WordPressMediaCopyException` — media file copy failure.
  - `WordPressOembedResolutionException` — oEmbed resolution failure.
- **FR-035** Each carries a stable string `code` field per charter §4.4.
- **FR-036** All exceptions extend the substrate's exception types where possible (e.g. `WxrParseException extends SourceReadException` from M-002).

### 3.8 Conformance and testing

- **FR-037** Each source plugin MUST pass `SourceConformanceTestCase` from M-002.
- **FR-038** A WXR fixture corpus MUST ship: small-site, medium-site, and edge-case (malformed entries, large entries, unicode, RTL languages) fixtures.
- **FR-039** Integration tests MUST run a full small-site fixture through all five migrations end-to-end and verify expected entity counts + key field values.

### 3.9 Validation (mission-internal)

- **FR-040** WP09 MUST demonstrate import of a real (or realistic-fixture-equivalent) WordPress site into a Minoo or test-consumer app:
  1. Export WP site as WXR.
  2. Run `bin/waaseyaa import:run-all`.
  3. Verify: all expected entities created, all relationships preserved, all media files copied, all shortcodes handled, all oEmbeds resolved, all media URLs rewritten.
  4. Run again immediately — no duplicates, idempotent.
- **FR-041** A round-trip benchmark MUST measure throughput: target ≥ 100 records/second for posts on commodity hardware. Documented; perf is informational, not gating.

### 3.10 Documentation

- **FR-042** A `docs/migrating-from-wordpress.md` operator guide MUST ship — the canonical marketing-grade walkthrough. Audience: WordPress site owners considering migration to Waaseyaa.
- **FR-043** A `docs/customization.md` developer guide MUST ship — how to override migrations, add custom shortcode handlers, parameterize for non-default consumer entity types.
- **FR-044** A `README.md` at the package root MUST cover install, basic usage, link to operator + developer guides.
- **FR-045** Upgrade-guide entries MUST ship for any breaking change after first stable release.

---

## 4. Stable surface deliverables

This mission ships a separate composer package with its own stable surface (independent of the framework's `public-surface-map.php`). The package maintains its own `public-surface-map.md` per the charter's extension-author obligations.

| Symbol | Kind |
|---|---|
| `WxrReader` | Concrete class |
| `WordPressPostSource`, `WordPressUserSource`, `WordPressCommentSource`, `WordPressMediaSource`, `WordPressTaxonomySource` | Source plugin classes |
| `WordPressShortcodeStrip`, `WordPressOembedExpand`, `WordPressMediaRewriteUrl` | Process plugin classes |
| `wp_users_to_accounts`, `wp_terms_to_taxonomy`, `wp_media_to_entities`, `wp_posts_to_articles` (example), `wp_comments_to_engagement` | Default `MigrationDefinition` instances |
| `WxrParseException`, `WordPressMediaCopyException`, `WordPressOembedResolutionException` | Exception classes |

---

## 5. Work package decomposition

Ten WPs.

| WP | Title | Primary FRs | Depends on |
|---|---|---|---|
| **WP01** | Package scaffold, composer config, CI skeleton | — | M-002 acceptance criterion 8 satisfied |
| **WP02** | WXR streaming parser (`WxrReader`) | FR-001..FR-004 | WP01 |
| **WP03** | Source plugin: `WordPressUserSource` | FR-006, FR-010..FR-012 | WP02 |
| **WP04** | Source plugin: `WordPressTaxonomySource` | FR-009..FR-012 | WP02 |
| **WP05** | Source plugin: `WordPressMediaSource` + media copy primitive | FR-008, FR-026..FR-029 | WP02 |
| **WP06** | Source plugin: `WordPressPostSource` | FR-005, FR-010..FR-012 | WP02 |
| **WP07** | Source plugin: `WordPressCommentSource` | FR-007, FR-010..FR-012 | WP02 |
| **WP08** | Process plugins: `WordPressShortcodeStrip`, `WordPressOembedExpand`, `WordPressMediaRewriteUrl` | FR-013..FR-017 | WP05, WP06 |
| **WP09** | Default migrations: users, taxonomies, media, posts, comments + cross-migration ID resolution | FR-018..FR-025, FR-030..FR-033, FR-040..FR-041 (validation) | WP03..WP08 |
| **WP10** | Operator + developer documentation, README, upgrade-guide template | FR-042..FR-045 | WP09 |

### 5.1 Sequencing diagram

```
M-002 (substrate) shipped ──► WP01 ──► WP02 ──┬──► WP03 (users)
                                              ├──► WP04 (taxonomy)
                                              ├──► WP05 (media)
                                              ├──► WP06 (posts) ──┐
                                              └──► WP07 (comments)│
                                                                  │
                                              WP05 + WP06 ──► WP08 (process)
                                                                  │
                                              All sources + process ──► WP09 (migrations)
                                                                  │
                                                                  ▼
                                                                WP10 (docs, close)
```

### 5.2 Parallelizable WPs

After WP02: WP03, WP04, WP05, WP06, WP07 can run in parallel (independent source plugins). WP08 needs WP05 + WP06 (process plugins consume those source records). WP09 needs all source + process plugins. WP10 closes after WP09.

---

## 6. Acceptance criteria

The mission is complete when:

1. All 10 WPs are merged.
2. All FRs in §3 are covered by tests.
3. WP09's real-site import test passes in CI: small-fixture WordPress export imports end-to-end with expected entity counts and zero duplicates.
4. Idempotency proven: re-running the import is a no-op.
5. The package is **published to Packagist** as `waaseyaa-migrate-source-wordpress` (separate from this framework repo's CI).
6. Operator documentation (`docs/migrating-from-wordpress.md`) reads as a marketing-grade walkthrough — first-impression-quality.
7. README links to both operator and developer guides; installation steps verified on a clean machine.

---

## 7. Open questions

Mission-specific.

1. **Repository location.** Standalone repo at `github.com/waaseyaa/migrate-source-wordpress`? Or part of a `waaseyaa/extensions` monorepo? Recommend: standalone repo. Easier to release independently; clearer ownership.
2. **Versioning relative to framework.** This package depends on `waaseyaa/migration ^x.y`. Should the package version-track the framework, or version independently? Recommend: independent semver; declare framework compatibility ranges in `composer.json`.
3. **Default post-to-entity mapping.** §3.4 FR-022 specifies `wp_posts_to_articles` as an example. What's "articles" — a Waaseyaa contrib entity type, or a placeholder consumers replace? Recommend: ship as an example, prominently documented as "rename to your actual entity type." Provide a CLI generator (`bin/waaseyaa make:wp-import-migration <entity-type>`) in a follow-up.
4. **oEmbed network calls.** `WordPressOembedExpand` may need to resolve oEmbed URLs by HTTP request to oEmbed providers. Is this acceptable during migration? Recommend: opt-in via a `resolve_remote: true` flag; default off (just records the URL, no remote call). Operators with reliable network enable remote resolution.
5. **Multisite WP scope.** §1.2 defers multisite. If a consumer needs multisite, what's the workaround? Recommend: per-site WXR exports, run the migration per-site with separate destination communities/tenants.
6. **WP custom post types.** §3.2 FR-005 says `WordPressPostSource` yields all post types and apps filter in process maps. Confirm: this is the right level of generality vs. shipping per-CPT source plugins? Recommend: single source + filter pattern; cheaper to maintain.
7. **Password handling.** §3.4 FR-019 says passwords are NOT preserved. Should the framework offer a password-bridge package later (validates WP bcrypt on first login and re-hashes for Waaseyaa)? Recommend: separate v1.x mission; out of scope for v1 of this package.
8. **Comment threading.** WP comments are tree-structured via `comment_parent`. Does the Waaseyaa engagement entity support threaded comments natively? Recommend: confirm with consumer apps. If not, this migration flattens the tree and records `comment_parent` for downstream feature work.

---

## 8. References

- [ADR 012a](../adr/012a-migration-substrate-in-core.md) — governing decision; WordPress reader named as first first-party source.
- [`migration-platform-v1.md`](migration-platform-v1.md) — substrate mission; hard prerequisite.
- WXR specification — https://wordpress.org/documentation/article/wxr-files/ (and WordPress codex).
- Drupal contrib `migrate_source_wordpress` — prior art; this mission's design is heavily influenced.
- 2026-05-11 framework/app audit (`waaseyaa/minoo/docs/audits/2026-05-11-framework-app-audit.md`) — strategic context.

---

## 9. Mission metadata for Spec Kitty

```yaml
mission:
  id: M-005
  title: WordPress Source Reader — waaseyaa-migrate-source-wordpress
  status: draft-spec
  governing_adrs: [012a]
  related_adrs: [010, 011, 016, 017]
  charter_dependencies: []
  external_dependencies:
    - mission: M-002
      relation: hard-prerequisite
      gates_wp: WP01-WP10 (entire mission)
  validation_consumer: real-wordpress-export
  validation_scope: round-trip-import-of-wordpress-site
  work_packages: 10
  parallelizable_after_wp02: true
  estimated_breaking_change_count: 0
  package_target: waaseyaa-migrate-source-wordpress
  agent_assignments:
    implementer: sonnet
    reviewer: opus
    escalation_after_n_rejections: 2
    escalation_target: opus-as-implementer
```
