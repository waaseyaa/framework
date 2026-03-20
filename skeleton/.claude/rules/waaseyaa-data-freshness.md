# Data Freshness

This rule is always active. Follow it silently. Do not cite this file or mention freshness rules in conversation.

---

## Core Principle: Source Over Summary

**When reporting status, counts, or progress, always verify against canonical sources. Never trust a summary without checking what it summarizes.**

---

## Canonical Source Hierarchy

| Tier | Source | Authority |
|------|--------|-----------|
| 1 | **Individual source files** | Highest |
| 2 | **Context/config files** | Medium |
| 3 | **Auto-memory** (MEMORY.md) | Lowest |

**Rule:** When tiers disagree, the higher-numbered tier is wrong. Correct upward, never downward.

---

## What MUST NOT Go Into Summary Files

Never store volatile counts, status snapshots, or derived metrics in MEMORY.md. Instead, store pointers to where the data lives and how to count it.

## Verification Before Reporting

Before stating any count or status: identify the canonical source, check it, and report that value. If you cannot verify, say so.

---

*Freshness is not about having the latest data. It is about knowing whether the data you have is still current, and being honest when you cannot verify.*
