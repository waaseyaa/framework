#!/usr/bin/env python3
"""Build compact qualification evidence from check-engagement-consumer output."""

from __future__ import annotations

import hashlib
import argparse
import json
import re
import shutil
import sys
from pathlib import Path
from typing import Any


class EvidenceError(Exception):
    pass


def fail(message: str) -> None:
    raise EvidenceError(message)


def read_text(root: Path, name: str) -> str:
    path = root / name
    if not path.is_file() or path.is_symlink():
        fail(f"missing expected evidence file: {name}")
    return path.read_text(encoding="utf-8")


def read_json(root: Path, name: str) -> Any:
    try:
        return json.loads(read_text(root, name))
    except (json.JSONDecodeError, UnicodeDecodeError) as exc:
        fail(f"invalid JSON in {name}: {exc}")


def json_line(root: Path, name: str) -> dict[str, Any]:
    line = read_text(root, name).splitlines()
    if not line:
        fail(f"empty probe output: {name}")
    try:
        value = json.loads(line[0])
    except json.JSONDecodeError as exc:
        fail(f"first line of {name} is not JSON: {exc}")
    if not isinstance(value, dict):
        fail(f"first JSON value in {name} is not an object")
    return value


def require_pass(data: dict[str, Any], name: str) -> None:
    if data.get("result") != "pass":
        fail(f"expected result=pass in {name}")


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def archive_hash(root: Path, name: str) -> str:
    raw = read_text(root, name).strip()
    match = re.match(r"^([0-9a-f]{64})(?:\s+.*)?$", raw)
    if not match:
        fail(f"invalid SHA-256 record in {name}")
    return match.group(1)


def int_value(data: dict[str, Any], key: str, where: str) -> int:
    value = data.get(key)
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        fail(f"{where}.{key} must be a nonnegative integer")
    return value


def validate_file(root: Path, name: str, signature: str | None = None) -> None:
    body = read_text(root, name)
    if signature and signature not in body:
        fail(f"expected successful completion marker absent from {name}")
    if re.search(r"(?im)^(?:fatal error|uncaught exception|error:|failed:)", body):
        fail(f"failure marker found in {name}")


def normal_profile(root: Path, label: str) -> dict[str, Any]:
    for suffix, marker in (("composer", None), ("install", "Installation is complete."),
                           ("field-preflight", None), ("upgrade", "Engagement upgrade complete:"),
                           ("seed", "engagement consumer fixture seeded"),
                           ("boot", "engagement consumer runtime boot OK")):
        validate_file(root, f"{label}-{suffix}.out", marker)
    field = read_json(root, f"{label}-field-preflight.out")
    if field.get("ready") is not True or field.get("conflicts") != []:
        fail(f"field preflight did not report ready with no conflicts for {label}")
    journey = read_json(root, f"{label}-journey.out")
    require_pass(journey, f"{label}-journey.out")
    performance = read_json(root, f"{label}-performance.out")
    require_pass(performance, f"{label}-performance.out")
    concurrency = read_json(root, f"{label}-concurrency.out")
    require_pass(concurrency, f"{label}-concurrency.out")
    quota = read_json(root, f"{label}-default-quota.out")
    require_pass(quota, f"{label}-default-quota.out")
    for key in ("default_like_attempts_admitted", "next_status", "retry_after"):
        if key not in quota or isinstance(quota[key], bool) or not isinstance(quota[key], (int, float)):
            fail(f"quota output lacks numeric {key} for {label}")
    summary = performance.get("summary")
    fixture = performance.get("fixture")
    sql = performance.get("sql")
    plans = performance.get("query_plans")
    if not isinstance(summary, dict) or not isinstance(fixture, dict) or not isinstance(sql, dict) or not isinstance(plans, dict):
        fail(f"performance output has incomplete summary, fixture, SQL queries, or query plans for {label}")
    if not sql or any(not isinstance(query, str) or not query.strip() for query in sql.values()):
        fail(f"performance SQL queries are missing or invalid for {label}")
    for key in ("iterations", "p50_ms", "p95_ms", "max_ms", "exact_comment_count_per_iteration"):
        if key not in summary or not isinstance(summary[key], (int, float)):
            fail(f"performance summary lacks numeric {key} for {label}")
    if not plans or any(not isinstance(rows, list) or not rows for rows in plans.values()):
        fail(f"performance query plans are missing for {label}")
    if not isinstance(concurrency.get("phases"), dict) or not concurrency["phases"]:
        fail(f"concurrency phases are missing for {label}")
    return {
        "complete": True,
        "journey": {"assertions": int_value(journey, "assertions", label),
                    "routes": int_value(journey, "routes", label)},
        "field_preflight": {"ready": True, "field_count": len(field.get("fields", [])),
                            "checksum": field.get("checksum")},
        "concurrency": {"parallel_children_per_phase": concurrency.get("parallel_children_per_phase"),
                        "phases": concurrency["phases"]},
        "quota": {key: quota[key] for key in ("default_like_attempts_admitted", "next_status", "retry_after")
                  if key in quota},
        "performance": {"fixture": fixture, "summary": summary, "sql": sql,
                        "query_plans": {key: {"row_count": len(rows), "rows": rows}
                                        for key, rows in plans.items()}},
    }


def upgrade_profile(root: Path, layout: str) -> dict[str, Any]:
    label = f"upgraded-{layout}"
    legacy = f"legacy-{layout}"
    for suffix, marker in (("composer", None), ("install", "Installation is complete."),
                           ("field-preflight", None), ("seed", "legacy engagement records seeded")):
        validate_file(root, f"{legacy}-{suffix}.out", marker)
    legacy_field = read_json(root, f"{legacy}-field-preflight.out")
    if legacy_field.get("ready") is not True or legacy_field.get("conflicts") != []:
        fail(f"legacy field preflight did not pass for {layout}")
    for suffix, marker in (("composer", None), ("install", "Installation is complete."),
                           ("legacy-payloads", None), ("field-preflight", None), ("upgrade", "Engagement upgrade complete:"),
                           ("seed", None)):
        validate_file(root, f"{label}-{suffix}.out", marker)
    field = read_json(root, f"{label}-field-preflight.out")
    if field.get("ready") is not True or field.get("conflicts") != []:
        fail(f"upgraded field preflight did not pass for {layout}")
    probe = read_json(root, f"{label}-probe.out")
    require_pass(probe, f"{label}-probe.out")
    assertions = int_value(probe, "legacy_upgrade_assertions", label)
    if assertions != 10:
        fail(f"expected 10 legacy upgrade assertions for {layout}, got {assertions}")
    batch = json_line(root, f"{label}-upgrade.out")
    if batch.get("event") != "engagement-upgrade-batch":
        fail(f"missing upgrade batch event for {layout}")
    for key in ("scanned", "migrated", "quarantined", "duplicate_likes_removed"):
        int_value(batch, key, f"{label}.upgrade_batch")
    replay = json_line(root, f"{label}-replay.out")
    if replay.get("event") != "engagement-upgrade-batch":
        fail(f"missing replay batch event for {layout}")
    replay_counts = {key: replay.get(key) for key in ("scanned", "migrated", "quarantined", "duplicate_likes_removed")}
    if any(value != 0 for value in replay_counts.values()):
        fail(f"upgrade replay was not a zero-change replay for {layout}")
    return {"complete": True, "probe_assertions": assertions,
            "upgrade_batch": {key: batch.get(key) for key in ("scanned", "migrated", "quarantined", "duplicate_likes_removed", "reasons")},
            "replay": replay_counts}


def main() -> int:
    parser = argparse.ArgumentParser(description="Assemble standard-Packagist or explicitly opted-in metadata-workaround evidence.")
    parser.add_argument("evidence_dir", type=Path)
    parser.add_argument("output_dir", type=Path)
    parser.add_argument("--allow-metadata-workaround", action="store_true",
                        help="Explicitly allow a metadata-workaround matrix, which is not standard Packagist qualification.")
    args = parser.parse_args()
    source = args.evidence_dir.expanduser().resolve(strict=True)
    output = args.output_dir.expanduser().resolve()
    if not source.is_dir() or source == output or source in output.parents:
        fail("evidence-dir must be a directory, and output-dir must be separate from it")
    if output.exists() and any(output.iterdir()):
        fail("output-dir must be absent or empty")

    qualification_mode = read_text(source, "qualification-mode.txt").strip()
    if qualification_mode not in {"standard-packagist", "metadata-workaround"}:
        fail("qualification-mode.txt must contain exactly 'standard-packagist' or 'metadata-workaround'")
    if qualification_mode == "metadata-workaround" and not args.allow_metadata_workaround:
        fail("metadata-workaround mode requires explicit --allow-metadata-workaround")
    if qualification_mode == "standard-packagist" and args.allow_metadata_workaround:
        fail("--allow-metadata-workaround is valid only when qualification-mode.txt selects metadata-workaround")

    candidate = read_text(source, "candidate.txt").strip()
    if not re.fullmatch(r"[0-9a-f]{40}", candidate):
        fail("candidate.txt must contain one full lowercase 40-character commit SHA")
    tag = read_text(source, "published-tag.txt").strip()
    if not re.fullmatch(r"v0\.1\.0-alpha\.[0-9]+", tag):
        fail("published-tag.txt must identify an exact published alpha tag")
    if qualification_mode == "metadata-workaround" and tag != "v0.1.0-alpha.309":
        fail("metadata-workaround mode is bounded to published tag v0.1.0-alpha.309")
    metadata_snapshot_sha256 = None
    if qualification_mode == "metadata-workaround":
        snapshot_path = source / "metadata-workaround.json"
        if not snapshot_path.is_file() or snapshot_path.is_symlink():
            fail("missing expected evidence file: metadata-workaround.json")
        snapshot_bytes = snapshot_path.read_bytes()
        try:
            snapshot = json.loads(snapshot_bytes)
        except json.JSONDecodeError as exc:
            fail(f"invalid JSON in metadata-workaround.json: {exc}")
        expected_refs = {
            "waaseyaa/publishing": "5da63931f33fe83100aca6b698c252f287b211d5",
            "waaseyaa/wayfinding": "2db31c587cc65012579f88c122507923d75b580c",
        }
        if snapshot.get("cohort") != "0.1.0-alpha.309" or not isinstance(snapshot.get("packages"), list):
            fail("metadata-workaround.json must declare alpha.309 and a package list")
        if {item.get("name") for item in snapshot["packages"] if isinstance(item, dict)} != set(expected_refs) or len(snapshot["packages"]) != 2:
            fail("metadata-workaround.json must contain exactly publishing and wayfinding")
        for item in snapshot["packages"]:
            name = item["name"]
            commit = expected_refs[name]
            if item.get("version") != tag or item.get("source", {}).get("reference") != commit or item.get("dist", {}).get("reference") != commit:
                fail(f"metadata-workaround snapshot has an unexpected version or source/dist reference for {name}")
            if item.get("source", {}).get("url") != f"https://github.com/{name}.git":
                fail(f"metadata-workaround snapshot has a noncanonical GitHub source for {name}")
            if item.get("dist", {}).get("url") != f"https://api.github.com/repos/{name}/zipball/{commit}":
                fail(f"metadata-workaround snapshot has a noncanonical GitHub dist URL for {name}")
        metadata_snapshot_sha256 = sha256(snapshot_bytes)
    public_source = read_text(source, "published-source.txt").strip()
    if not re.fullmatch(r"[0-9a-f]{40}", public_source):
        fail("published-source.txt must contain the public tag's full commit SHA")
    identity = {
        "candidate_sha": candidate,
        "published_tag": tag,
        "published_source_sha": public_source,
        "candidate_archive_sha256": archive_hash(source, "archive.sha256"),
        "published_archive_sha256": archive_hash(source, "published-archive.sha256"),
        "legacy_tag_source_sha": read_text(source, "legacy-candidate.txt").strip(),
        "legacy_archive_sha256": archive_hash(source, "legacy-archive.sha256"),
    }
    if not re.fullmatch(r"[0-9a-f]{40}", identity["legacy_tag_source_sha"]):
        fail("legacy-candidate.txt must contain a full commit SHA")

    profiles: dict[str, Any] = {}
    journey_total = {"assertions": 0, "routes": 0}
    for form in ("dev", "no-dev"):
        for layout in ("sql-blob", "sql-column"):
            label = f"{form}-{layout}"
            profiles[label] = normal_profile(source, label)
            for key in journey_total:
                journey_total[key] += profiles[label]["journey"][key]
    for layout in ("sql-blob", "sql-column"):
        profiles[f"upgraded-{layout}"] = upgrade_profile(source, layout)

    validate_file(source, "absent-composer.out")
    validate_file(source, "absent-install.out", "Installation is complete.")
    validate_file(source, "absent-field-preflight.out")
    absent_field = read_json(source, "absent-field-preflight.out")
    if absent_field.get("ready") is not True or absent_field.get("conflicts") != []:
        fail("optional-absence field preflight did not pass")
    absent = read_json(source, "absent-boot.out")
    if absent.get("profile") != "api-without-engagement" or absent.get("kernel_boot") is not True or absent.get("engagement_absent") is not True:
        fail("API-without-engagement boot did not prove optional absence")
    int_value(absent, "route_count", "absent")
    profiles["api-without-engagement"] = {"complete": True, "kernel_boot": True,
                                          "engagement_absent": True, "route_count": absent["route_count"]}
    if len(profiles) != 7 or not all(item.get("complete") is True for item in profiles.values()):
        fail("all seven expected qualification profiles must be complete")

    # Copy only resolved manifests and lockfiles. Identical locks share one
    # content-addressed output file; no process logs are retained.
    manifests: dict[str, str] = {}
    locks: dict[str, dict[str, str]] = {}
    for path in sorted(source.iterdir()):
        if not path.is_file() or path.is_symlink():
            continue
        if path.name == "composer.json" or path.name.endswith("-composer.json") or path.name.endswith("-manifest.json"):
            payload = path.read_bytes()
            json.loads(payload)
            manifests[path.name] = sha256(payload)
        elif path.name == "composer.lock" or path.name.endswith("-composer.lock"):
            payload = path.read_bytes()
            lock = json.loads(payload)
            if not isinstance(lock, dict) or not isinstance(lock.get("packages"), list):
                fail(f"solved lock has no packages array: {path.name}")
            digest = sha256(payload)
            locks[path.name] = {"sha256": digest, "file": f"locks/{digest}.composer.lock"}

    required_manifest_names = {"composer.json", "absent-manifest.json"}
    required_lock_names = {"composer.lock"}
    required_provenance_names = {"absent-provenance.json"}
    for form in ("dev", "no-dev"):
        for layout in ("sql-blob", "sql-column"):
            label = f"{form}-{layout}"
            required_manifest_names.update({f"{label}-manifest.json", f"{label}-composer.json"})
            required_lock_names.add(f"{label}-composer.lock")
            required_provenance_names.update({f"{label}-provenance.json", f"{label}-after-provenance.json"})
    for layout in ("sql-blob", "sql-column"):
        for prefix in ("legacy", "upgraded"):
            label = f"{prefix}-{layout}"
            required_manifest_names.update({f"{label}-manifest.json", f"{label}-composer.json"})
            required_lock_names.add(f"{label}-composer.lock")
            required_provenance_names.add(f"{label}-provenance.json")
        required_provenance_names.add(f"upgraded-{layout}-before-provenance.json")
    missing_solved = sorted((required_manifest_names - set(manifests)) |
                            (required_lock_names - set(locks)))
    if missing_solved:
        fail("missing solved manifest/lock or provenance input: " + ", ".join(missing_solved))

    if qualification_mode == "metadata-workaround":
        for layout in ("sql-blob", "sql-column"):
            historical_manifest = json.loads(read_text(source, f"legacy-{layout}-composer.json"))
            if "repositories" in historical_manifest:
                fail(f"historical alpha.308 consumer unexpectedly has repository overrides: legacy-{layout}-composer.json")

    provenance_inventories: dict[str, dict[str, Any]] = {}
    provenance_refs: dict[str, str] = {}
    provenance_modes: dict[str, dict[str, Any]] = {}
    for path in sorted(source.glob("*-provenance.json")):
        value = read_json(source, path.name)
        packages, third_party = value.get("packages"), value.get("third_party")
        if not isinstance(packages, list) or not isinstance(third_party, list):
            fail(f"provenance package inventory or third-party closure missing: {path.name}")
        if qualification_mode == "metadata-workaround":
            if value.get("qualification_mode") != "metadata-workaround":
                fail(f"missing metadata-workaround provenance label in {path.name}")
            if value.get("metadata_snapshot_sha256") != metadata_snapshot_sha256:
                fail(f"metadata snapshot SHA-256 mismatch in {path.name}")
            cohort_label = value.get("cohort_label")
            if path.name.startswith("legacy-"):
                if cohort_label != "0.1.0-alpha.308":
                    fail(f"historical provenance must remain on alpha.308 in {path.name}")
                override_state = "none; historical composer manifest has no repositories"
            else:
                if cohort_label != "0.1.0-alpha.309":
                    fail(f"current-cohort provenance must identify alpha.309 in {path.name}")
                override_state = "alpha.309 publishing and wayfinding metadata snapshot"
            provenance_modes[path.name] = {
                "qualification_mode": value["qualification_mode"],
                "cohort_label": cohort_label,
                "metadata_snapshot_sha256": value["metadata_snapshot_sha256"],
                "metadata_overrides": override_state,
            }
        else:
            provenance_modes[path.name] = {"qualification_mode": "published", "cohort_label": value.get("cohort_label")}
        inventory = {"packages": packages, "third_party": third_party}
        canonical = json.dumps(inventory, sort_keys=True, separators=(",", ":")).encode()
        key = sha256(canonical)
        provenance_inventories.setdefault(key, inventory)
        provenance_refs[path.name] = key
    if not provenance_refs:
        fail("no provenance package inventories found")
    missing_provenance = sorted(required_provenance_names - set(provenance_refs))
    if missing_provenance:
        fail("missing expected provenance input: " + ", ".join(missing_provenance))

    if qualification_mode == "metadata-workaround":
        schema = "engagement-metadata-workaround-matrix-summary/v1"
        status = "workaround-matrix-complete"
    else:
        schema = "engagement-published-qualification-summary/v1"
        status = "complete"
    qualification = {
        "schema": schema,
        "status": status,
        "qualification_mode": qualification_mode,
        "identity": identity,
        "profiles": profiles,
        "journey_totals": journey_total,
        "solved_manifests": manifests,
        "solved_locks": locks,
        "provenance_inventories": provenance_refs,
        "provenance_modes": provenance_modes,
        "deduplicated_package_inventories": provenance_inventories,
        "retention": {"included": ["qualification.json", "solved manifests", "content-addressed Composer locks", "deduplicated package and third-party provenance inventories", "full bounded performance SQL queries"],
                      "excluded": ["raw process logs", "work directories", "environment secrets"]},
    }
    if qualification_mode == "metadata-workaround":
        qualification["packagist_qualification"] = "not-established; P2 metadata is still required for standard qualification"
        qualification["metadata_snapshot"] = {
            "file": f"metadata-snapshots/{metadata_snapshot_sha256}.json",
            "sha256": metadata_snapshot_sha256,
            "source": "official public Packagist package JSON for alpha.309 publishing and wayfinding",
        }
        qualification["retention"]["included"].append("content-addressed public metadata workaround snapshot")

    output.mkdir(parents=True, exist_ok=True)
    (output / "manifests").mkdir()
    (output / "locks").mkdir()
    (output / "provenance-inventories").mkdir()
    if qualification_mode == "metadata-workaround":
        snapshot_dir = output / "metadata-snapshots"
        snapshot_dir.mkdir()
        shutil.copyfile(source / "metadata-workaround.json", snapshot_dir / f"{metadata_snapshot_sha256}.json")
    for name in manifests:
        shutil.copyfile(source / name, output / "manifests" / name)
    for name, record in locks.items():
        target = output / record["file"]
        if not target.exists():
            shutil.copyfile(source / name, target)
    for digest, inventory in provenance_inventories.items():
        (output / "provenance-inventories" / f"{digest}.json").write_text(
            json.dumps(inventory, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (output / "qualification.json").write_text(json.dumps(qualification, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if qualification_mode == "metadata-workaround":
        print(f"Wrote seven-profile metadata-workaround matrix summary, not standard Packagist qualification: {output / 'qualification.json'}")
    else:
        print(f"Wrote complete seven-profile qualification summary: {output / 'qualification.json'}")
    print(f"Candidate {candidate}; published {tag} at {public_source}; deduplicated {len(locks)} lock inputs into {len(set(v['sha256'] for v in locks.values()))} lock files")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except EvidenceError as exc:
        print(f"evidence error: {exc}", file=sys.stderr)
        raise SystemExit(1)
    except (OSError, json.JSONDecodeError) as exc:
        print(f"evidence error: {exc}", file=sys.stderr)
        raise SystemExit(1)
