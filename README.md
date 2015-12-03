The **Platform.sh CLI** is the official command-line interface for [Platform.sh](https://platform.sh). Use this tool to interact with your [Platform.sh](https://platform.sh) projects, and to build them locally for development purposes.

[![Build Status](https://travis-ci.org/platformsh/platformsh-cli.svg)](https://travis-ci.org/platformsh/platformsh-cli) [![Latest Stable Version](https://poser.pugx.org/platformsh/cli/v/stable)](https://github.com/platformsh/platformsh-cli/releases) [![License](https://poser.pugx.org/platformsh/cli/license)](https://github.com/platformsh/platformsh-cli/blob/master/LICENSE)

## Requirements

* Operating system: Linux, OS X, Windows Vista, Windows 7, Windows 8 Pro, or Windows 10 (Windows 8 Standard does not work due to an issue with symlink permissions)
* PHP 5.5 or higher, with cURL support
* [Composer](https://getcomposer.org/)
* [Drush](https://github.com/drush-ops/drush) (only for Drupal projects, optional)

## Installation

### Installing on OS X or Linux

Simply use this command:

    curl -sS https://platform.sh/cli/installer | php

### Installing on Windows

1. Install Composer using [Composer-Setup.exe](https://getcomposer.org/doc/00-intro.md#installation-windows).

2. Install the CLI, in your preferred terminal application (e.g. in Git Bash):

        composer global require platformsh/cli:@stable

3. Make sure the Composer `vendor/bin` directory is in your PATH. Use this
  command from a Command Prompt (cmd.exe):

        setx PATH "%PATH%;%APPDATA%\Composer\vendor\bin"

  Start a new terminal before continuing.

### Installing manually

Installing manually is not recommended, unless you are confident you know what
you are doing.

1. Download the latest stable package from the
  [Releases page](https://github.com/platformsh/platformsh-cli/releases)
  (look for the latest `platform.phar` file).

2. Rename the file to `platform`, ensure it is executable, and move it into a
  directory in your PATH (use `echo $PATH` to see your options).

3. Enable autocompletion and shell aliases:

        platform local:install

## Updating

New releases of the CLI are made regularly. Update with this command:

    platform self-update

If you installed the CLI using Composer, then you may need this command instead:

    composer global update

## Usage

You can run the Platform.sh CLI in your shell by typing `platform`.

    platform

Use the 'list' command to get a list of available options and commands:

    platform list

### Commands

The current output of `platform list` is as follows:

```
Platform.sh CLI

Global options:
  --help           -h Display this help message
  --quiet          -q Do not output any message
  --verbose        -v|vv|vvv Increase the verbosity of messages
  --version        -V Display this application version
  --yes            -y Answer "yes" to all prompts
  --no             -n Answer "no" to all prompts
  --shell          -s Launch the shell

Available commands:
  clear-cache (clearcache, cc)              Clear the CLI cache
  docs                                      Open the Platform.sh online documentation
  help                                      Displays help for a command
  list                                      Lists commands
  login                                     Log in to Platform.sh
  logout                                    Log out of Platform.sh
  self-update (up)                          Update the CLI to the latest version
  web                                       Open the Platform.sh Web UI
activity
  activity:list (activities)                Get the most recent activities for an environment
  activity:log                              Display the log for an environment activity
domain
  domain:add                                Add a new domain to the project
  domain:delete                             Delete a domain from the project
  domain:list (domains)                     Get a list of all domains
environment
  environment:activate                      Activate an environment
  environment:branch (branch)               Branch an environment
  environment:checkout (checkout)           Check out an environment
  environment:delete                        Delete an environment
  environment:drush (drush)                 Run a drush command on the remote environment
  environment:http-access (httpaccess)      Update HTTP access settings for an environment
  environment:info                          Read or set properties for an environment
  environment:list (environments)           Get a list of all environments
  environment:merge (merge)                 Merge an environment
  environment:relationships (relationships) List an environment's relationships
  environment:routes (routes)               List an environment's routes
  environment:set-remote                    Set the remote environment to track for a branch
  environment:sql (sql)                     Run SQL on the remote database
  environment:sql-dump (sql-dump)           Create a local dump of the remote database
  environment:ssh (ssh)                     SSH to the current environment
  environment:synchronize (sync)            Synchronize an environment
  environment:url (url)                     Get the public URL of an environment
integration
  integration:add                           Add an integration to the project
  integration:delete                        Delete an integration from a project
  integration:get (integrations)            View project integration(s)
  integration:update                        Update an integration
local
  local:build (build)                       Build the current project locally
  local:clean (clean)                       Remove old project builds
  local:drush-aliases (drush-aliases)       Find the project's Drush aliases
  local:init (init)                         Create a local project file structure from a Git repository
project
  project:delete                            Delete a project
  project:get (get)                         Clone and build a project locally
  project:info                              Read or set properties for a project
  project:list (projects)                   Get a list of all active projects
snapshot
  snapshot:create                           Make a snapshot of an environment
  snapshot:list (snapshots)                 List available snapshots of an environment
  snapshot:restore                          Restore an environment snapshot
ssh-key
  ssh-key:add                               Add a new SSH key
  ssh-key:delete                            Delete an SSH key
  ssh-key:list (ssh-keys)                   Get a list of SSH keys in your account
user
  user:add                                  Add a user to the project
  user:delete                               Delete a user
  user:list (users)                         List project users
  user:role                                 View or change a user's role
variable
  variable:delete                           Delete a variable from an environment
  variable:get (variables, vget)            Get a variable for an environment
  variable:set (vset)                       Set a variable for an environment
```

## Known issues

### Caching

The CLI caches details of your projects and their environments, and some other
information. These caches could become out-of-date. You can clear caches with
the command `platform clear-cache` (or `platform cc` for short).

## Customization

You can configure the CLI via these environment variables:

* `PLATFORMSH_CLI_API_TOKEN`: an API token to use for all requests
* `PLATFORMSH_CLI_COPY_ON_WINDOWS`: set to 1 to avoid some Windows symlink issues
* `PLATFORMSH_CLI_DEBUG`: set to 1 to enable cURL debugging
* `PLATFORMSH_CLI_DISABLE_CACHE`: set to 1 to disable caching
* `PLATFORMSH_CLI_DRUSH`: configure the Drush executable to use (default 'drush')
* `PLATFORMSH_CLI_SESSION_ID`: change user session (default 'default')
* `http_proxy` or `https_proxy`: specify a proxy for connecting to Platform.sh

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to contribute to the CLI.
