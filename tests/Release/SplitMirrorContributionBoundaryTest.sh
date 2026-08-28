#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
EXPECTED="$(git -C "${ROOT_DIR}" rev-parse HEAD)"

first="$(cd "${ROOT_DIR}" && bash bin/build-split-contribution-boundary "${EXPECTED}")"
second="$(cd "${ROOT_DIR}" && bash bin/build-split-contribution-boundary "${EXPECTED}")"
[[ "${first}" == "${second}" ]]
[[ "$(git -C "${ROOT_DIR}" rev-parse "${first}^")" == "${EXPECTED}" ]]

mapfile -t changed < <(git -C "${ROOT_DIR}" diff-tree --no-commit-id --name-only -r "${first}")
expected_paths=(
  '.github/ISSUE_TEMPLATE/config.yml'
  '.github/PULL_REQUEST_TEMPLATE.md'
)
[[ "${changed[*]}" == "${expected_paths[*]}" ]]

for path in "${expected_paths[@]}"; do
  cmp \
    <(git -C "${ROOT_DIR}" show "${first}:${path}") \
    "${ROOT_DIR}/resources/split-mirror/${path}"
done

[[ "$(git -C "${ROOT_DIR}" show -s --format=%an "${first}")" == 'github-actions[bot]' ]]
[[ "$(git -C "${ROOT_DIR}" show -s --format=%ae "${first}")" == '41898282+github-actions[bot]@users.noreply.github.com' ]]
[[ "$(git -C "${ROOT_DIR}" show -s --format=%aI "${first}")" == "$(git -C "${ROOT_DIR}" show -s --format=%cI "${EXPECTED}")" ]]
[[ "$(git -C "${ROOT_DIR}" show -s --format=%cI "${first}")" == "$(git -C "${ROOT_DIR}" show -s --format=%cI "${EXPECTED}")" ]]

echo "OK: contribution routing overlay is deterministic and preserves the exact split parent."
