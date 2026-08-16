# Data Freshness

Supplemental Claude guidance. The authorization and precedence rules in
`docs/governance/agent-contract.md` remain authoritative. Follow this rule
silently during routine work, and identify it when the maintainer asks which
guidance applies.

---

## Core Principle: Source Over Summary

**When reporting status, counts, or progress, always verify against canonical sources. Never trust a summary without checking what it summarizes.**

---

## Canonical Source Hierarchy

When the same information exists in multiple places, trust it in this order:

| Tier | Source | Authority | Example |
|------|--------|-----------|---------|
| 1 | **Individual source files** | Highest | Package source code, test files, migration files |
| 2 | **Context/config files** | Medium | `composer.json`, package manifests, spec files |
| 3 | **Auto-memory** (MEMORY.md) | Lowest | Claude Code's cross-session notes |

**Rule:** When tiers disagree, the higher-numbered tier is wrong. Correct upward, never downward.

---

## What MUST NOT Go Into Summary Files

### Never store in MEMORY.md or README trackers:

- **Volatile counts** ("42 packages", "156 tests passing")
- **Status snapshots** ("Package X is in alpha", "Migration is 80% complete")
- **Derived metrics** ("12 entity types registered", "7 middleware classes")

### Instead, store pointers:

- **Where to find the data** ("Packages are in `packages/` directory")
- **How to count it** ("Run `vendor/bin/phpunit` for current test count")
- **What the source of truth is** ("Package list lives in `composer.json` workspace config")

---

## Verification Before Reporting

Before stating any count or status, ask: "Where does this number come from?" Then check the canonical source. If the summary and source disagree, report the source-of-truth value and mention the discrepancy.

---

*Freshness is not about having the latest data. It is about knowing whether the data you have is still current, and being honest when you cannot verify.*
