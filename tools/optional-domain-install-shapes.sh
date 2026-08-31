#!/usr/bin/env bash

set -euo pipefail

framework_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
work=$(mktemp -d)
cleanup() { rm -rf "$work"; }
trap cleanup EXIT

install_shape() {
  local shape=$1
  local project_root="$work/$shape"

  cp -R "$framework_root/skeleton/." "$project_root/"
  composer config --working-dir="$project_root" repositories.framework path "$framework_root"
  composer config --working-dir="$project_root" repositories.packages path "$framework_root/packages/*"
  composer require --working-dir="$project_root" waaseyaa/framework:dev-main --no-update --no-interaction

  if [[ "$shape" == "full" ]]; then
    composer require --working-dir="$project_root" \
      waaseyaa/genealogy:dev-main \
      waaseyaa/ai-agent:dev-main \
      waaseyaa/oidc:dev-main \
      waaseyaa/mcp:dev-main \
      waaseyaa/wayfinding:dev-main \
      waaseyaa/messaging:dev-main \
      waaseyaa/engagement:dev-main \
      --no-update --no-interaction
  fi

  # PRODUCTION shapes, hence --no-dev. This fixture's whole claim is that a
  # consumer sees an optional domain only when it asked for one by name, and
  # `minimal` is the control: nothing optional, nothing discovered, no routes.
  #
  # Installing dev dependencies silently voided that control. The skeleton's
  # require-dev now names waaseyaa/ai-development (ADR-022 D-1.3), which
  # requires waaseyaa/ai-agent — so `minimal` acquired an optional domain
  # nobody had opted into, with its two entities, its discovery entries and
  # POST /api/ai/agent/run all present. That is not a bad assertion; it is the
  # fixture no longer installing the shape it claims to install.
  #
  # The developer install is separately owned by ci/skeleton-create-project's
  # "Fresh skeleton preserves the complete discovery set" step, which installs
  # WITH dev dependencies and asserts the plane's surface by name. Nothing is
  # lost by making this one production-shaped; the two steps now cover the two
  # install shapes that actually exist instead of both covering one.
  #
  # `full` is unaffected in kind: its seven optional domains are added to
  # `require` by the `composer require` above, not to require-dev, so --no-dev
  # keeps every one of them.
  COMPOSER_ROOT_VERSION=dev-main composer install --working-dir="$project_root" \
    --no-dev --no-interaction --no-scripts --no-plugins --quiet

  local database_path="$project_root/storage/waaseyaa.sqlite"
  (cd "$project_root" && APP_ENV=testing WAASEYAA_DB="$database_path" php vendor/bin/waaseyaa db:init)
  APP_ENV=testing WAASEYAA_DB="$database_path" php "$framework_root/tools/skeleton-smoke/smoke.php" "$project_root"
  APP_ENV=testing WAASEYAA_DB="$database_path" php "$framework_root/tools/optional-domain-install-smoke.php" "$project_root" "$shape"
}

install_shape minimal
install_shape full
