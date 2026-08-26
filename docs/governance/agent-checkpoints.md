# Bounded agent checkpoints

Long-running audits and repository tasks keep full evidence in files and use a
small checkpoint manifest for handoff. The contract is product-neutral: Claude
dynamic workflows, Cursor, Codex, and human operators can all invoke
`bin/agent-checkpoint` without relying on terminal metadata or UI activity.

## Evidence boundary

Choose an explicit task evidence directory. Put reports, probe output, and
large reconciliations there before generating the checkpoint. Every referenced
file is recorded with its byte count, SHA-256 digest, and one classification:

- `tracked-deliverable`: intended for repository review and commit;
- `disposable-local`: reproducible scratch evidence that must not be committed;
- `sensitive-private`: access-restricted reproduction evidence that must not be
  copied into a general-purpose report.

Never put secrets, credentials, member data, or actionable abuse recipes in a
general-purpose report or checkpoint. Failed checkpoint generation does not
delete, rewrite, or relocate evidence files.

## Generate a checkpoint

```bash
bin/agent-checkpoint \
  --task='environment audit' \
  --verdict=in-progress \
  --evidence-dir=build/evidence/environment-audit \
  --evidence=tracked-deliverable:build/evidence/environment-audit/report.md \
  --worktree=task-active-edit:/workspace/framework-audit \
  --worktree=read-only-evidence:/workspace/sheg \
  --pid=probe:12345 \
  --pin=framework:0123456789abcdef0123456789abcdef01234567 \
  --mutation=github:created-pr:waaseyaa/framework#123 \
  --blocker='independent review remains' \
  --next='resume inventory reconciliation; do not merge'
```

The command atomically writes `checkpoint.json` inside the evidence directory
and prints the same bounded JSON to standard output. It reports the real cwd,
repository top-level, branch or detached state, HEAD, staged/unstaged/untracked
and conflict counts, active Git operation, each named worktree, and
operating-system PID liveness. A stale terminal record therefore cannot make a
dead process appear active.

Use one `active-edit` or `task-active-edit` role at most. Read-only evidence
trees must be labelled explicitly. External mutations use
`system:action:target`, which makes interrupted work resumable without guessing
whether GitHub or another external system changed.

The manifest schema is `tools/agent-checkpoint.schema.json`. Inputs and arrays
are bounded and the serialized manifest may not exceed 16,384 bytes. Move any
larger material into a classified evidence file; the checkpoint stores its
path and hash rather than embedding it.
