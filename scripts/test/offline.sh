#!/usr/bin/env bash
# Tests various offline CLI commands.
# This must be run from the repository root.

set -xe

# Ensure Composer dependencies.
if [ ! -d vendor ]; then
  composer install --no-interaction
fi

./bin/platform list >/dev/null
