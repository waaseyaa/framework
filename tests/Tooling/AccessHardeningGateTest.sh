#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

php "${root}/bin/check-access-hardening" --self-test
php "${root}/bin/check-access-hardening" "${root}"
