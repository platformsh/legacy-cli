#!/usr/bin/env bash

echo "When ready to make authenticated requests, use: platform auth:api-token-login"
echo "Or: export \PLATFORMSH_CLI_TOKEN=YOUR_TOKEN"

if [ -z "$PLATFORM_CLI_TOKEN" ]; then
    echo "WARNING: \$PLATFORMSH_CLI_TOKEN was detected as an environment variable. If you share this workspace with others, they will have access to this token.";
fi;
