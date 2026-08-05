# Search

## Scope

`waaseyaa/search` is a Layer 3 service package for full-text and structured
entity search. The write-side indexer is active for existing consumers. The
FTS5 read provider, request/result objects, access checker, and Twig helper are
internal and have no first-party HTTP, CLI, SSR, or admin caller on main.

## Read-surface activation boundary

Do not publish the parked read surface until a first-party endpoint supplies an
acting-account access boundary and tests access-filtered pagination. Count,
facets, page selection, and rank order must share one bounded ordered ID basis;
titles and snippets are fetched only for the approved page IDs. Asynchronous
indexing also requires a production queue consumer before a job is introduced.

## Index contract

SQLite FTS5 uses Unicode word boundaries without English stemming or diacritic
folding. ASCII apostrophe, U+2019, and U+02BC remain token characters so the
index preserves Indigenous orthographies. Changing the tokenizer requires a
full `search:reindex` because SQLite cannot alter an FTS5 tokenizer in place.
FTS5 operator characters must be stripped before terms are quoted.
