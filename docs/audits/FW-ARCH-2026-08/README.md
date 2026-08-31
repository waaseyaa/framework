# Framework architectural integrity audit

Stable program: [FW-ARCH-2026-08](../../change-records/FW-ARCH-2026-08.md).
Tracking: [program #2719](https://github.com/waaseyaa/framework/issues/2719),
[A0–A7 #2720–#2727](https://github.com/waaseyaa/framework/issues/2720).

The frozen A0 census is the denominator. Later evidence is indexed by exact SHA in
the linked issue threads; it does not rewrite the frozen baseline. The audit's final
cross-workstream products are:

- [Synthesis and dispositions](synthesis.md)
- [Remediation dependency plan](remediation.md)
- [Framework assurance gates](assurance.md)

## Decision and limits

The census covers the complete repository, not a selected application's dependency
closure. It is the audit's navigation and evidence baseline. Inventory consistency
does not prove production correctness; the assurance matrix records partial and
unreviewed cells explicitly.

No code is removed, rewritten or declared safe by this candidate. No public API
classification, dependency, gate, runtime policy or release permission changes.

- [Authority map](authority-map.md): real composition seams, accountable workstream,
  carried evidence and explicit unqualified paths.
- [Historical reconciliation](history.md): separate completed audit/planning work
  from implemented fixes and current source observations.
- [Qualification and follow-up](qualification.md): both storage layouts, deployment,
  public testing-surface reachability, and the next bounded units.
- [Validation record](validation.md): commands, inputs, evidence limits.

## Frozen denominator

Source: `waaseyaa/framework` at
`50750231a8036ae7afc68416fed8ea271e47159f`.
Lock SHA256: `f660a1af9c1340c2bc99d76810db7b14ef8ed06f11a7263801a3c9f8ab333f80`.
The original and reproduced census ran natively on Windows, Node 24.13.1 / PHP 8.5.5.
Hashes describe checkout bytes; cross-platform byte parity is not claimed.

| Denominator | Count | What it proves |
|---|---:|---|
| Tracked paths | 7,818 | Every path has one primary A1–A6 allocation |
| Packages | 77 | 73 PHP libraries, 3 metapackages, 1 JavaScript admin package |
| Package `src` PHP files | 2,515 | Static source coverage denominator |
| Public-map declarations | 687 | 611 public / 76 internal; named declarations resolve |
| Internal dependency edges | 367 runtime / 109 dev / 19 suggested | Manifest relationships, not runtime calls |
| Manifest providers | 86 | Declarations resolve, not proof all boot correctly |
| Executable/support paths | 194 | Tooling, workflows and frontend surfaces inventoried |
| Baselines/allowlists/rosters | 30 | Explicit review scope, not presumed bypasses |
| Preflight roster entries | 39 | Static roster count, not evidence every gate was run |

All 73 PHP libraries appear in the executable layer map. The descriptive extension
compatibility table omits `site-contract`; this is a documentation reconciliation
item, not a missing executable layer rule. Four public-map entries resolve outside
`src`; see the qualification record before interpreting them as consumer APIs.

Metapackage/runtime dependency closures cover 21 (`core`), 43 (`cms`), 48 (`full`),
and 63 (root development application's runtime closure) libraries. Libraries outside
these closures remain in scope. A closure is not a successful installation test.

`data/packages.json` and `data/file-roster.json` assign every package/path to an
audit owner. Cross-audit assignments capture shared boundaries; A7 synthesizes all
areas and is not a substitute file owner. Lexical candidates such as fallback,
catch or normalization expressions are **not defect counts**.

## Baseline versus adoption branch

The initial portable-audit documentation was based at
`61dabd434df3beec04953a2297f11bc5771384f0`; this final synthesis candidate starts
at `e3ff47a403476f1d643906ac54c546ff58a8c841`. Both are after the PRE_DELETE
transaction fix [#2735](https://github.com/waaseyaa/framework/pull/2735). That fix
does not rewrite the frozen census or the original failing baseline evidence for
#2728. Source observations in this report apply to the frozen baseline unless
explicitly marked as post-baseline. Migration PRs #2706/#2712 are not folded into
this source baseline.

Boundary IDs **B01–B08** always mean the eight rows in the authority map. Earlier
behavioral checkpoint artifacts also used B-prefixed directory names; this report
calls those **CP01–CP05**. In particular, the old checkpoint `B05` was a schema
layout probe (now CP05, boundary B04), **not** an audit of async boundary B05.

## Reproduce the inventory

Use a clean checkout of the exact baseline, PHP and Node. The scripts live in the
documentation candidate; the source checkout must remain separate and untouched.
Run from this directory, replacing the two example absolute paths:

```sh
node tools/a0-inventory.mjs /absolute/baseline-checkout /absolute/scratch/census
node tools/a0-validate.mjs /absolute/scratch/census
node tools/a0-export.mjs /absolute/scratch/census /absolute/scratch/exported-data
diff -ru data /absolute/scratch/exported-data
```

On Windows the default Git Bash path is `C:/Program Files/Git/bin/bash.exe`;
override it with `A0_BASH` if necessary. All repository Git calls use `bin/git`.
The source clean-tree guard is mandatory. Use a new, real scratch directory, not
a symlink into the source. The census does not install dependencies or execute
framework classes; PHP tokenizes declarations.

The exported subset is committed as readable JSON (one row per line for large
arrays). Full token declarations, `@api` sites and lexical signals are generated
in scratch, not duplicated in this PR. The validator uses that full output.
`data/entrypoints.json` is deliberately marked lexical/manifest evidence; the
semantic map adds inspected seams without claiming a complete dynamic call graph.

## Completion boundary

For A0 closure: independently review allocations and semantic ownership; resolve
or explicitly waive the missing historical roster with the maintainer; ensure
every retained lead has an existing owner and falsifiable next check. Subsequent
A1–A6 qualification and A7 remediation remain separate work, not hidden conditions
for a blanket declaration that the framework is correct.
