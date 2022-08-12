#!/usr/bin/env bash

if [ -z "$PLATFORM_CLI_TOKEN" ]; then
    echo "\$PLATFORMSH_CLI_TOKEN was detected as an environment variable which.";
    echo "You may updated it with \`eval $(gp env -e PLATFORMSH_CLI_TOKEN=NEW_TOKEN)\`.";
else
    echo "You have not set your Platform API token yet. Paste it now or press <return> to continue without one.";
    read NEW_TOKEN;
    if [ -z "$NEW_TOKEN" ]; then
        echo "Okay, you can always set it later \`eval $(gp env -e PLATFORMSH_CLI_TOKEN=NEW_TOKEN)\`.";
    else
        echo "Logging into Platform.sh using ${NEW_TOKEN}.";
        eval $(gp env -e PLATFORMSH_CLI_TOKEN=$NEW_TOKEN);
        $GITPOD_REPO_ROOT/bin/platform auth:info || echo "Could not log in using ${NEW_TOKEN}. Please provide a new one using: \`eval $(gp env -e PLATFORMSH_CLI_TOKEN=NEW_TOKEN)\`.";
    fi;
fi;