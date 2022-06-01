#!/usr/bin/env bash
# Runs slow PhpUnit tests.
# This must be run from the repository root.

./vendor/bin/phpunit -c ./phpunit.xml --group slow --verbose
