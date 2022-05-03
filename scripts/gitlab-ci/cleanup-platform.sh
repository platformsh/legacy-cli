#!/usr/bin/env bash

# Config.
if [ -z "$PF_PROJECT_ID" ]; then
  echo '$PF_PROJECT_ID is required'
  exit 1
fi
if [ -z "$PF_PARENT_ENV" ]; then
    PF_PARENT_ENV=${CI_MERGE_REQUEST_TARGET_BRANCH_NAME:-main}
fi

export PLATFORMSH_CLI_NO_INTERACTION=1

# Clean up environments if they are merged with their parent.
platform environment:delete --project="$PF_PROJECT_ID" --merged --no-wait --exclude-type production,staging --exclude "$PF_PARENT_ENV"
