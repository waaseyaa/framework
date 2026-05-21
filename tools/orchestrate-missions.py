#!/usr/bin/env python3
"""Drive Spec Kitty missions end-to-end with sonnet implement / opus review.

STATUS (2026-05-21): Skeleton — validated through the prework loop (M1 reaches
`implement` state) and `list-ready` parsing. The per-WP implement/review/
transition/accept/merge calls have additional required args (--actor, --policy
JSON, --to, evidence flags) that need verification before running end-to-end.
The sub-agents dispatched via `claude -p` must also handle the lane worktree
lifecycle (composer install, branch creation, transition emission) which is
encoded in the prompt but not battle-tested.

To complete the end-to-end loop, the remaining work is:
1. Verify accept-mission, merge-mission CLI signatures (`spec-kitty
   orchestrator-api accept-mission --help`).
2. Verify the `transition` call signature when a sub-agent emits it (the
   sub-agent prompt instructs it to call `spec-kitty orchestrator-api
   transition --to <lane> --policy '{}'` — confirm policy format).
3. End-to-end test on M1 (already past tasks-finalize) with one WP to ensure
   the sonnet implement → opus review → transition → next WP loop closes.

The skeleton handles: prework state advance through discovery/specify/research/
plan/tasks_outline/tasks_packages/tasks_finalize, list-ready parsing, and the
overall mission loop shape.

Wraps the spec-kitty CLI (`next`, `agent mission`, `orchestrator-api`) and
dispatches Claude Code sub-sessions via the `claude -p` CLI to do the actual
implementation and review work. Each phase's prompt is read from the file
spec-kitty emits at `/tmp/spec-kitty-next-<agent>-<mission>-<step>.md`.

Usage:
    tools/orchestrate-missions.py <mission-slug> [<mission-slug>...]
        [--max-rejection-cycles N] [--phase-timeout SECONDS]
        [--skip-prework] [--dry-run] [--log-dir DIR]

Examples:
    # M1 alone, full cycle:
    tools/orchestrate-missions.py bimaaji-wakeup-01KS5VEY

    # All 5, dep-respecting (M1+M4 first, then M2/M3/M5 after M1 ships):
    tools/orchestrate-missions.py \\
        bimaaji-wakeup-01KS5VEY \\
        agent-output-package-01KS5VX1 \\
        ai-agent-bimaaji-tools-01KS5VKR \\
        bimaaji-mcp-bridge-01KS5VS8 \\
        bimaaji-install-command-01KS5W0S

    # Skip planning, drive only implement/review/merge (M1 already past tasks-finalize):
    tools/orchestrate-missions.py bimaaji-wakeup-01KS5VEY --skip-prework

Long-running. Run in `tmux` or `screen`. Logs per mission at
`tools/orchestrator-logs/<mission-slug>.log`. Designed to be idempotent —
re-running picks up where it left off because spec-kitty state lives on disk.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import shlex
import subprocess
import sys
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_LOG_DIR = REPO_ROOT / "tools" / "orchestrator-logs"

# Mission ordering / dependency graph for the AI ecosystem cluster.
# Override on CLI by listing missions in the desired order; the orchestrator
# does not currently enforce a DAG across missions, it just processes them
# in argument order. Run M1 first, then M2/M3/M5 in parallel sessions.
KNOWN_DEPS = {
    "bimaaji-wakeup-01KS5VEY": [],
    "agent-output-package-01KS5VX1": [],
    "ai-agent-bimaaji-tools-01KS5VKR": ["bimaaji-wakeup-01KS5VEY"],
    "bimaaji-mcp-bridge-01KS5VS8": ["bimaaji-wakeup-01KS5VEY", "ai-agent-bimaaji-tools-01KS5VKR"],
    "bimaaji-install-command-01KS5W0S": ["bimaaji-wakeup-01KS5VEY"],
}


@dataclass
class Logger:
    path: Path
    silent: bool = False

    def __post_init__(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.fh = self.path.open("a")
        self._log(f"=== orchestrator session opened at {dt.datetime.now().isoformat()} ===")

    def _log(self, msg: str) -> None:
        line = f"[{dt.datetime.now().strftime('%H:%M:%S')}] {msg}"
        self.fh.write(line + "\n")
        self.fh.flush()
        if not self.silent:
            print(line, flush=True)

    def info(self, msg: str) -> None:
        self._log(msg)

    def warn(self, msg: str) -> None:
        self._log("WARN " + msg)

    def err(self, msg: str) -> None:
        self._log("ERR  " + msg)

    def close(self) -> None:
        self._log("=== orchestrator session closing ===")
        self.fh.close()


def run(cmd: list[str], *, capture: bool = True, cwd: Path | None = None,
        timeout: int | None = None) -> subprocess.CompletedProcess[str]:
    """Run a command; return CompletedProcess. Does not raise on non-zero."""
    return subprocess.run(
        cmd, capture_output=capture, text=True, cwd=cwd or REPO_ROOT,
        timeout=timeout, check=False,
    )


def sk_next(mission: str, *, agent: str = "sonnet", result: str | None = None) -> dict[str, Any]:
    """Wrap `spec-kitty next` and return the JSON decision."""
    cmd = ["spec-kitty", "next", "--agent", agent, "--mission", mission, "--json"]
    if result:
        cmd += ["--result", result]
    proc = run(cmd)
    if proc.returncode != 0:
        raise RuntimeError(f"spec-kitty next failed: {proc.stderr}")
    return json.loads(proc.stdout)


def sk_state(mission: str) -> dict[str, Any]:
    """Query mission state without advancing."""
    proc = run(["spec-kitty", "next", "--mission", mission, "--json"])
    return json.loads(proc.stdout) if proc.returncode == 0 else {}


def sk_finalize_tasks(mission: str) -> tuple[bool, str]:
    """Run finalize-tasks; return (ok, stderr_or_err_summary)."""
    proc = run(["spec-kitty", "agent", "mission", "finalize-tasks",
                "--mission", mission, "--json"])
    if proc.returncode == 0:
        return True, "ok"
    try:
        data = json.loads(proc.stdout)
        return False, data.get("error", proc.stdout)
    except json.JSONDecodeError:
        return False, proc.stderr or proc.stdout


def sk_orch(args: list[str]) -> dict[str, Any]:
    """Call spec-kitty orchestrator-api; return parsed `data` payload.

    The orchestrator-api wraps responses in
    {contract_version, command, success, data, error_code, ...}; we unwrap
    `data` for caller convenience. The CLI always emits JSON — never pass --json.
    """
    proc = run(["spec-kitty", "orchestrator-api", *args])
    if proc.returncode != 0:
        raise RuntimeError(f"orchestrator-api {' '.join(args)} failed: {proc.stderr or proc.stdout}")
    envelope = json.loads(proc.stdout)
    if not envelope.get("success", True):
        raise RuntimeError(f"orchestrator-api error: {envelope.get('error_code')} {envelope}")
    return envelope.get("data", envelope)


# --- Sub-agent dispatch via `claude -p` -------------------------------------


def dispatch_claude(prompt_text: str, *, model: str, cwd: Path,
                    log: Logger, label: str,
                    timeout: int = 3600) -> tuple[bool, str]:
    """Dispatch a Claude Code session with `claude -p`.

    `model` is one of: claude-opus-4-7, claude-sonnet-4-6, claude-haiku-4-5.
    Returns (success, stdout). Long-form prompts piped via stdin.
    """
    log.info(f"dispatching {label} ({model}) in {cwd}")
    cmd = ["claude", "-p", "--model", model,
           "--dangerously-skip-permissions"]
    proc = subprocess.run(cmd, input=prompt_text, text=True,
                          capture_output=True, timeout=timeout,
                          check=False, cwd=str(cwd))
    ok = proc.returncode == 0
    if not ok:
        log.err(f"{label} returned exit={proc.returncode}: {proc.stderr[:500]}")
    return ok, proc.stdout


def read_prompt_file(state: dict[str, Any]) -> str:
    pf = state.get("prompt_file")
    if not pf or not Path(pf).is_file():
        return ""
    return Path(pf).read_text()


# --- Pre-work phase (research/plan/tasks*/finalize) ------------------------

PREWORK_STEPS = {
    "discovery", "specify", "research", "plan",
    "tasks_outline", "tasks_packages", "tasks_finalize",
    "finalize_tasks",  # alternate spelling some spec-kitty versions emit
}
DONE_WITH_PREWORK = {
    "implement", "implementing", "review", "in_review",
    "accept", "accepting", "merged", "done", "complete",
}


def drive_prework(mission: str, log: Logger, max_retries: int = 3) -> bool:
    """Walk the state machine from current state up to tasks_finalize done.

    For each phase, dispatch sonnet to read the prompt and produce artifacts.
    Returns True when the mission state advances past tasks_packages and
    finalize-tasks succeeds.
    """
    for _ in range(40):  # safety cap on total state transitions
        state = sk_state(mission)
        s = state.get("mission_state")
        log.info(f"prework state={s} action={state.get('action')}")
        if s in DONE_WITH_PREWORK:
            return True
        if s in PREWORK_STEPS:
            # Try to advance via --result success first; if the guard fails,
            # dispatch a worker to produce the artifact, then retry.
            adv = sk_next(mission, result="success")
            new_s = adv.get("mission_state")
            guards = adv.get("guard_failures") or []
            if new_s != s and not guards:
                log.info(f"  advanced {s} -> {new_s}")
                continue
            # Stuck: dispatch sonnet to produce the artifact.
            prompt = read_prompt_file(adv)
            if not prompt:
                log.warn(f"  no prompt file for state {s}; advancing anyway")
                continue
            extra = (f"\n\nYou are working on mission `{mission}` at {REPO_ROOT}. "
                     f"Read the spec at kitty-specs/{mission}/spec.md and any plan.md, "
                     "wps.yaml, or per-WP files that already exist. Produce only the "
                     f"artifact this {s} phase requires. Do NOT invoke spec-kitty CLI; "
                     "the orchestrator will advance state after you finish.")
            for attempt in range(max_retries):
                ok, _ = dispatch_claude(
                    prompt + extra, model="claude-sonnet-4-6", cwd=REPO_ROOT,
                    log=log, label=f"{mission}/{s} attempt {attempt+1}",
                )
                if not ok:
                    log.warn(f"  worker failed at {s}; retrying")
                    continue
                # Special-case tasks_outline → run finalize-tasks explicitly
                if s == "tasks_outline" or s == "tasks_packages":
                    ok2, err = sk_finalize_tasks(mission)
                    if ok2:
                        log.info("  finalize-tasks ok")
                        break
                    log.warn(f"  finalize-tasks failed: {err}; sending feedback to worker")
                    extra += f"\n\nLast finalize-tasks error: {err}\nFix and re-emit artifacts."
                    continue
                break
            else:
                log.err(f"  exceeded retries at {s}; aborting prework")
                return False
        else:
            log.warn(f"  unknown state {s}; halting prework")
            return False
        time.sleep(1)
    log.err("  prework safety cap reached without convergence")
    return False


# --- Implement / review loop ------------------------------------------------


def list_ready_wps(mission: str) -> list[dict[str, Any]]:
    """Return list of WP dicts ready for implementation."""
    data = sk_orch(["list-ready", "--mission", mission])
    # API returns ready_work_packages: [{wp_id, lane, dependencies_satisfied}]
    return data.get("ready_work_packages", []) or data.get("ready_wps", []) or []


def implement_wp(mission: str, wp: dict[str, Any], log: Logger,
                 max_rejection_cycles: int) -> bool:
    wp_id = wp.get("wp_id") or wp.get("id")
    if not wp_id:
        log.err(f"  malformed WP entry: {wp}")
        return False
    log.info(f"--- WP {wp_id}: implement ---")
    # Transition to in_progress.
    sk_orch(["start-implementation", "--mission", mission, "--wp", wp_id,
             "--actor", "sonnet", "--policy", "{}"])
    # Find the WP worktree (spec-kitty creates `.worktrees/<slug>-<mid8>-lane-X/`)
    state = sk_state(mission)
    worktree = state.get("workspace_path") or REPO_ROOT
    wp_prompt_path = REPO_ROOT / "kitty-specs" / mission / "tasks"
    wp_file = next(iter(wp_prompt_path.glob(f"{wp_id}-*.md")), None)
    if not wp_file:
        log.err(f"  no prompt file found for {wp_id}")
        return False
    wp_prompt = wp_file.read_text()
    extra = (f"\n\nYou are implementing {wp_id} of mission `{mission}` in the worktree "
             f"at {worktree}. Follow the prompt's Subtasks section. Run `composer install` "
             "first if vendor/ is missing. Commit to the lane branch and push when done. "
             "When complete, transition the WP to for_review by running:\n"
             f"  spec-kitty orchestrator-api transition --mission {mission} --wp {wp_id} "
             "--to for_review")
    for cycle in range(max_rejection_cycles):
        ok, _ = dispatch_claude(
            wp_prompt + extra, model="claude-sonnet-4-6", cwd=Path(worktree),
            log=log, label=f"{mission}/{wp_id} impl cycle {cycle+1}",
            timeout=7200,  # 2h per cycle
        )
        if not ok:
            log.warn(f"  {wp_id} impl cycle {cycle+1} failed; retrying")
            continue
        # Review.
        sk_orch(["start-review", "--mission", mission, "--wp", wp_id,
                 "--actor", "opus", "--policy", "{}"])
        review_prompt = (
            f"You are reviewing {wp_id} of mission `{mission}` in the lane worktree at "
            f"{worktree}. The WP spec is at {wp_file}. Run `git diff main` to see the "
            "changes. Evaluate:\n"
            "1. Does the diff implement every subtask in the WP prompt?\n"
            "2. Are tests present and passing? Run `composer verify` to confirm.\n"
            "3. Does the diff respect ownership (no files outside owned_files)?\n"
            "4. Any obvious bugs, layer violations, or missing edge cases?\n"
            "Respond with either:\n"
            "  APPROVED — followed by a one-paragraph summary\n"
            "  REJECTED: <reason> — followed by a numbered list of required changes\n"
            "When done, transition the WP by running:\n"
            f"  spec-kitty orchestrator-api transition --mission {mission} --wp {wp_id} "
            "--to done   (for APPROVED)\n"
            f"  spec-kitty orchestrator-api transition --mission {mission} --wp {wp_id} "
            "--to in_progress   (for REJECTED)"
        )
        ok_r, review_out = dispatch_claude(
            review_prompt, model="claude-opus-4-7", cwd=Path(worktree),
            log=log, label=f"{mission}/{wp_id} review cycle {cycle+1}",
            timeout=3600,
        )
        if not ok_r:
            log.err(f"  reviewer failed on {wp_id}; halting")
            return False
        if "APPROVED" in review_out and "REJECTED" not in review_out:
            log.info(f"  {wp_id} APPROVED on cycle {cycle+1}")
            return True
        # Rejected: feed feedback into next impl cycle
        extra += f"\n\nReviewer feedback from cycle {cycle+1}:\n{review_out[-2000:]}"
    log.err(f"  {wp_id} exceeded {max_rejection_cycles} rejection cycles")
    return False


def drive_mission(mission: str, *, skip_prework: bool, max_rejection: int,
                  log: Logger) -> bool:
    log.info(f"=== mission {mission} ===")
    if not skip_prework:
        if not drive_prework(mission, log):
            log.err(f"prework failed for {mission}")
            return False
    # Per-WP implement/review.
    while True:
        ready = list_ready_wps(mission)
        if not ready:
            log.info("no more ready WPs")
            break
        for wp in ready:
            if not implement_wp(mission, wp, log, max_rejection):
                log.err(f"WP {wp.get('id')} failed; halting mission")
                return False
    # Accept + merge.
    log.info("accept-mission")
    sk_orch(["accept-mission", "--mission", mission])
    log.info("merge-mission")
    sk_orch(["merge-mission", "--mission", mission])
    log.info(f"=== {mission} merged ===")
    return True


# --- Entry point ------------------------------------------------------------


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("missions", nargs="+", help="mission slugs in execution order")
    p.add_argument("--max-rejection-cycles", type=int, default=3)
    p.add_argument("--skip-prework", action="store_true",
                   help="Skip research/plan/tasks phases; assume mission is past tasks-finalize")
    p.add_argument("--log-dir", type=Path, default=DEFAULT_LOG_DIR)
    p.add_argument("--dry-run", action="store_true", help="Print plan without executing")
    args = p.parse_args(argv)

    if args.dry_run:
        for m in args.missions:
            deps = KNOWN_DEPS.get(m, [])
            print(f"would drive {m} (deps: {deps or 'none'})")
        return 0

    for mission in args.missions:
        log = Logger(args.log_dir / f"{mission}.log")
        try:
            ok = drive_mission(
                mission,
                skip_prework=args.skip_prework,
                max_rejection=args.max_rejection_cycles,
                log=log,
            )
        finally:
            log.close()
        if not ok:
            print(f"mission {mission} did not complete; halting batch", file=sys.stderr)
            return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
