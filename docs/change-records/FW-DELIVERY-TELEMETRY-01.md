# FW-DELIVERY-TELEMETRY-01 — governed agent-event ledger

- Issue: `#2869`
- Contract: `docs/specs/delivery-telemetry.md`
- Authority: repository-tracked source changes; forge-neutral

## Slice

This slice moves the off-platform review and verifier-event authority out of
ignored Docker state and into a versioned, closed, append-only ledger. The JSON
Schema is executed rather than copied into an importer. A permanent gate proves
schema conformance, causal integrity, adjudication cardinality, temporal
ordering, and byte-prefix append-only history.

The source ledger contained 16 events and was normalized from CRLF to LF once.
One `GitHub Actions` verification event was deliberately excluded because
GitHub owns that fact; copying it here would create a second authority. The
resulting 15-event governed import has SHA-256
`d38391ce118ebf95b21f0832eaecb354abdfdf6fab7de2a388146a5a3f5e0117`.
Its source provenance is:

- original CRLF SHA-256: `cb3c1e87318dd4d90ab3e0b8ed9751e70b6ff9c97a4aa72d0c5874788ced51ce`;
- normalized LF SHA-256: `7acb51e782532462e3b7f95afbf930002ddc97672d5addd719e97474af7bbc25`;
- source schema SHA-256: `f3762726d7802a2a9853eafe6edc261f5e0901cc56165dcec95cf1c68ace4db0`.

The governed schema closes the source schema's optional-key and shape gaps and
has SHA-256 `f0fed595c426dd39014a295cfffc233d638894035291fa494cd5ddf0e8a4de05`.

DevLake, its database, Grafana, secrets, and runtime volumes remain outside this
authority. This record does not claim the projection or dashboard acceptance
criteria of #2869 complete.
