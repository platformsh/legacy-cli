#!/usr/bin/env bash
# Runs PhpUnit tests.
# This must be run from the repository root.

./vendor/bin/phpunit -c ./phpunit.xml --coverage-text --exclude-group slow
