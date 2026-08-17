#!/usr/bin/env bash
# Build and record an exact Framework release candidate without publishing or
# touching an external environment.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$repo_root"

candidate_sha="$(bin/git rev-parse HEAD)"
timestamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
tag="$(bin/git describe --tags --exact-match 2>/dev/null || printf 'untagged')"
required_node_major="$(tr -d '[:space:]v' < .nvmrc)"
node_version="$(node --version)"
node_major="${node_version#v}"
node_major="${node_major%%.*}"

if [ "$node_major" != "$required_node_major" ]; then
  printf 'ERROR: Node %s is required; found %s.\n' "$required_node_major" "$node_version" >&2
  exit 1
fi

printf '=== Build release candidate ===\n'
printf '  SHA: %s\n' "$candidate_sha"
printf '  Tag: %s\n' "$tag"
printf '  Time: %s\n' "$timestamp"

composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction \
  --no-progress

(
  cd packages/admin
  npm ci --no-audit --no-fund
  npm run build
)

mkdir -p build/release
CANDIDATE_SHA="$candidate_sha" \
CANDIDATE_TAG="$tag" \
CANDIDATE_TIMESTAMP="$timestamp" \
CANDIDATE_ACTOR="${RELEASE_ACTOR:-local}" \
CANDIDATE_RUN_ID="${RELEASE_RUN_ID:-local}" \
php <<'PHP'
<?php

declare(strict_types=1);

$metadata = [
    'schema_version' => 1,
    'kind' => 'framework-release-candidate',
    'sha' => getenv('CANDIDATE_SHA'),
    'tag' => getenv('CANDIDATE_TAG'),
    'timestamp' => getenv('CANDIDATE_TIMESTAMP'),
    'actor' => getenv('CANDIDATE_ACTOR'),
    'run_id' => getenv('CANDIDATE_RUN_ID'),
    'deployment_performed' => false,
];

file_put_contents(
    'build/release/candidate-metadata.json',
    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);
PHP

printf '=== Release candidate build complete ===\n'
printf 'Evidence: build/release/candidate-metadata.json\n'
