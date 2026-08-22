#!/usr/bin/env python3
"""Assert 20 captured header files share one retained worker PID."""
from __future__ import annotations

import pathlib
import sys


def parse_headers(raw: str) -> tuple[str, str]:
    status = "000"
    pid = ""
    for line in raw.splitlines():
        if line.startswith("HTTP/"):
            parts = line.split()
            if len(parts) >= 2:
                status = parts[1]
        lower = line.lower()
        if lower.startswith("x-waaseyaa-worker-pid:"):
            pid = line.split(":", 1)[1].strip()
    return status, pid


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: assert-concurrent-pids.py <headers-dir> <expected-pid>", file=sys.stderr)
        return 2
    header_dir = pathlib.Path(sys.argv[1])
    expected = sys.argv[2]
    files = sorted(header_dir.glob("*.headers"))
    if len(files) != 20:
        print(f"expected 20 header captures, got {len(files)}", file=sys.stderr)
        return 1
    for path in files:
        status, pid = parse_headers(path.read_text(errors="replace"))
        if status != "200":
            print(f"{path.name}: expected 200, got {status}", file=sys.stderr)
            return 1
        if pid == "":
            print(f"{path.name}: missing X-Waaseyaa-Worker-Pid", file=sys.stderr)
            return 1
        if pid != expected:
            print(f"{path.name}: worker pid {pid} != retained {expected}", file=sys.stderr)
            return 1
    print("concurrent public 20 same worker", expected)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
