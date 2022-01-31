#!/usr/bin/env bash
set -e

# Config.
if [ -z "$PF_PROJECT_ID" ]; then
  echo '$PF_PROJECT_ID is required'
  exit 1
fi
if [ -z "$PF_PARENT_ENV" ]; then
    PF_PARENT_ENV=${CI_MERGE_REQUEST_TARGET_BRANCH_NAME:-main}
fi

export PLATFORMSH_CLI_NO_INTERACTION=1

# Find a list of environments not to delete.
exclude="$(platform env --project="$PF_PROJECT_ID" --pipe --type production,staging)"

# Clean up environments if they are merged with their parent.
cmd="platform environment:delete --project="$PF_PROJECT_ID" --merged --no-wait"

for e in "$exclude"; do
    cmd="$cmd --exclude $e"
done

if [ "$PF_CLEANUP_DELETE_BRANCH" != 1 ]; then
    cmd="$cmd --no-delete-branch"
else
    cmd="$cmd --inactive --delete-branch"
fi

$cmd
