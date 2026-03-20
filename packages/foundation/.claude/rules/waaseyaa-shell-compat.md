# Shell Compatibility

When writing bash commands via the Bash tool, follow these rules to avoid platform-specific failures.

---

## Reserved Variable Names (Never Use)

| Avoid | Use Instead | Why |
|-------|-------------|-----|
| `status` | `result`, `exit_status` | zsh read-only |
| `path` | `file_path`, `target_path` | Conflicts with `$PATH` |
| `prompt` | `user_prompt` | zsh reserved |

## Safe Patterns

- **Command substitution:** `$(command)` not `` `command` ``
- **Quote all paths:** `"$file"` not `$file`
- **Conditionals:** `[[ ]]` not `[ ]`

## When Parallel Commands Fail

If multiple parallel Bash tool calls fail with "sibling tool call errored":
1. The error means one command failed and the others were **never attempted**
2. Re-run each failed command individually
