#!/usr/bin/env bash
# Tests the CLI installer.
# This must be run from the repository root.

export PLATFORMSH_CLI_MANIFEST_URL=./dist/manifest.json
cat ./dist/installer.php | php
