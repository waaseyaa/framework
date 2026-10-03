# Usage: python THIS_SCRIPT ARTIFACT_ROOT candidate|baseline [OUTPUT_JSON]
# Retain caller-owned disposable databases/logs and stop every owned server.
import argparse
import concurrent.futures
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser(description="Concurrent synthetic HTTP window probe")
parser.add_argument("artifact_root")
parser.add_argument("mode", choices=["candidate", "baseline"])
parser.add_argument("output_json", nargs="?")
args = parser.parse_args()
php = shutil.which("php")
if php is None:
    raise SystemExit("The artifact host requires PHP on PATH.")
flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
router = str(Path(__file__).with_name("FW-SQLITE-CONTENTION-01-http-window.php"))
results = []


def wait_ready(port):
    deadline = time.monotonic() + 10
    while True:
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{port}/ready", timeout=1) as response:
                if response.read() != b"ready":
                    raise RuntimeError("Unexpected fixture readiness response.")
            return
        except (OSError, urllib.error.URLError):
            if time.monotonic() > deadline:
                raise
            time.sleep(0.025)


def request(port):
    message = urllib.request.Request(
        f"http://127.0.0.1:{port}/fixture-image",
        headers={"Authorization": "Bearer synthetic-fixture"},
    )
    try:
        response = urllib.request.urlopen(message, timeout=20)
    except urllib.error.HTTPError as failure:
        response = failure
    with response:
        return {
            "status": response.status,
            "cache_control": response.headers.get("Cache-Control"),
            "remaining": response.headers.get("X-RateLimit-Remaining"),
            "body_bytes": len(response.read()),
        }


for window in ["new-window", "expiry"]:
    root = Path(tempfile.mkdtemp(prefix="fw3183-http-"))
    database = root / "window.sqlite"
    environment = os.environ.copy()
    environment.update(FW3183_ARTIFACT_ROOT=args.artifact_root, FW3183_DATABASE=str(database))
    subprocess.run(
        [php, router, "prepare"], env=environment, check=True,
        capture_output=True, creationflags=flags, timeout=20,
    )
    if window == "expiry":
        with sqlite3.connect(database) as connection:
            connection.execute(
                "INSERT INTO rate_limit_windows VALUES (?, ?, ?)",
                ("127.0.0.1", 99, int(time.time()) - 700),
            )
    servers, logs, ports = [], [], []
    try:
        for index in range(8):
            with socket.socket() as bound_socket:
                bound_socket.bind(("127.0.0.1", 0))
                port = bound_socket.getsockname()[1]
            log = (root / f"{index}.log").open("wb")
            logs.append(log)
            process = subprocess.Popen(
                [php, "-S", f"127.0.0.1:{port}", router], env=environment,
                stdout=log, stderr=log, creationflags=flags,
            )
            servers.append(process)
            ports.append(port)
        for port in ports:
            wait_ready(port)
        responses = []
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
            for wave in range(10):
                responses.extend(executor.map(request, ports))
        with sqlite3.connect(database) as connection:
            count = connection.execute(
                'SELECT "count" FROM rate_limit_windows WHERE "key" = ?', ("127.0.0.1",),
            ).fetchone()[0]
        statuses = {
            str(code): sum(response["status"] == code for response in responses)
            for code in {response["status"] for response in responses}
        }
        results.append({
            "mode": window, "requests": 80, "workers": 8, "responses": responses,
            "statuses": statuses, "stored_count": count, "root": str(root),
        })
        if args.mode == "candidate" and (statuses != {"200": 3, "429": 77} or count != 80):
            raise RuntimeError(f"Accounting mismatch: {statuses}, count={count}; logs in {root}")
        for response in responses:
            if response["status"] == 200:
                cache = response["cache_control"] or ""
                if "private" not in cache or "no-store" not in cache:
                    raise RuntimeError("Fixture privacy headers changed.")
    finally:
        for process in servers:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
        for log in logs:
            log.close()

output = Path(args.output_json) if args.output_json else root / "results.json"
output.write_text(json.dumps(results, indent=2), encoding="utf-8")
print(json.dumps([{key: value for key, value in run.items() if key != "responses"} for run in results], indent=2))
