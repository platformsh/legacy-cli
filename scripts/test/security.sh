#!/usr/bin/env bash
# Tests the composer.lock file against the SensioLabs security checker.
# This must be run from the repository root.

curl -H 'Accept: text/plain' https://security.sensiolabs.org/check_lock -F lock=@./composer.lock
