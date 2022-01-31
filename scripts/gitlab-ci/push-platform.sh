#!/usr/bin/env bash

# This script can be used from GitLab CI to push code to a Platform.sh environment.
# N.B. It always force-pushes, and it activates the environment.

set -e -o pipefail

# Config.
if [ -z "$PF_PROJECT_ID" ]; then
  echo >&2 '$PF_PROJECT_ID is required'
  exit 1
fi
BRANCH=${PF_TARGET_BRANCH:-$CI_COMMIT_REF_SLUG}
if [ -z "$BRANCH" ]; then
  echo >&2 'Target branch ($PF_TARGET_BRANCH or $CI_COMMIT_REF_SLUG) not defined'
  exit 1
fi
PF_PARENT_ENV=${PF_PARENT_ENV:-$CI_MERGE_REQUEST_TARGET_BRANCH_NAME}
if [ -z "$PF_PARENT_ENV" ]; then
  echo >&2 'Parent environment ($PF_PARENT_ENV or $CI_MERGE_REQUEST_TARGET_BRANCH_NAME) not defined'
  exit 1
fi

export PLATFORMSH_CLI_NO_INTERACTION=1

platform project:set-remote "$PF_PROJECT_ID"

# Build the push command.
push_command="platform push --force --target=${BRANCH}"
if [ "$PF_PARENT_ENV" != "$BRANCH" ]; then
  push_command="$push_command --activate --parent=${PF_PARENT_ENV}"
fi
if [ -n "$PF_NO_CLONE_PARENT" ]; then
  push_command="$push_command --no-clone-parent"
fi

# Run the push command, copying its output to push.log.
$push_command 2>&1 | tee push.log

# Analyse the result for a push failure or build failure.
push_result=${PIPESTATUS[0]}
[ "$push_result" != 0 ] && exit "$push_result"
if grep -q "Unable to build application" push.log \
  || grep -q "Error building project" push.log \
  || grep -q "Resources exceeding plan limit" push.log \
  || grep -q "Environment redeployment failed" push.log; then
  rm push.log || true
  exit 1
fi
rm push.log || true

# Write the environment's primary URL to a dotenv file.
# This can be used by a GitLab job via the "dotenv" artifact type.
echo "PRIMARY_URL=$(platform url --primary --pipe --yes --environment=${BRANCH})" > environment.env
