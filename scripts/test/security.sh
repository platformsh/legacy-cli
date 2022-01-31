#!/usr/bin/env bash
# Tests the composer.lock file using fabpot/local-php-security-checker.
# This must be run from the repository root.

cmd=local-php-security-checker

if [ -f ./local-php-security-checker ]; then
    cmd=./local-php-security-checker
elif ! command -v local-php-security-checker >/dev/null; then
    url=https://github.com/fabpot/local-php-security-checker/releases/download/v1.2.0/local-php-security-checker_1.2.0_linux_amd64
    echo >&2 "Downloading security checker from $url"
    curl -sfSL "$url" > ./local-php-security-checker
    chmod +x ./local-php-security-checker
    cmd=./local-php-security-checker
fi

$cmd
