#!/usr/bin/env bash

# Script to update the files in this directory, from their GitHub sources.

dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
branch=master

set -e

curl -sfSL https://github.com/platformsh/platformsh-example-drupal7/raw/master/settings.php > "$dir"/settings.php.dist
curl -sfSL https://github.com/platformsh/platformsh-example-drupal7/raw/master/settings.platformsh.php > "$dir"/settings.platformsh.php.dist
