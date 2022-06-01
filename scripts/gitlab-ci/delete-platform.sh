#!/usr/bin/env bash
set -e

# Config.
if [ -z "$PF_PROJECT_ID" ]; then
  echo '$PF_PROJECT_ID is required'
  exit 1
fi
BRANCH=${PF_TARGET_BRANCH:-$CI_COMMIT_REF_SLUG}
if [ -z "$BRANCH" ]; then
  echo 'Branch name ($PF_TARGET_BRANCH or $CI_COMMIT_REF_SLUG) not defined'
  exit 1
fi

export PLATFORMSH_CLI_NO_INTERACTION=1

platform environment:delete --no-wait --project="$PF_PROJECT_ID" --environment="$BRANCH"
