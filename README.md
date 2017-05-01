The **Platform.sh CLI** is the official command-line interface for [Platform.sh](https://platform.sh). Use this tool to interact with your [Platform.sh](https://platform.sh) projects, and to build them locally for development purposes.

[![Build Status](https://travis-ci.org/platformsh/platformsh-cli.svg)](https://travis-ci.org/platformsh/platformsh-cli) [![License](https://poser.pugx.org/platformsh/cli/license)](https://github.com/platformsh/platformsh-cli/blob/master/LICENSE)

## Requirements

* Operating system: Linux, OS X, Windows Vista, Windows 7, Windows 8 Pro, or Windows 10 (Windows 8 Standard does not work due to an issue with symlink permissions)
* PHP 5.5.9 or higher, with cURL support
* Git
* A Bash-like shell:
  * On OS X or Linux/Unix: SH, Bash, Dash or ZSH - usually the built-in shell will work.
  * On Windows: [Bash on Ubuntu](https://msdn.microsoft.com/en-gb/commandline/wsl/about) on Windows (recommended), or a Bash-compatible shell such as [Git Bash](https://git-for-windows.github.io/), Cygwin, or MinGW.
* For building locally, your project's dependencies, e.g.
  * [Composer](https://getcomposer.org/) (for many PHP projects)
  * [Drush](https://github.com/drush-ops/drush) (for Drupal projects)
  * Other build tools: [npm](https://www.npmjs.com/), [pip](http://docs.python-guide.org/en/latest/starting/installation/), [bundler](http://bundler.io/), etc.

## Installation

### Installing on OS X or Linux

This is the recommended installation method. Simply use this command:

    curl -sS https://platform.sh/cli/installer | php

### Installing manually

1. Download the latest stable package from the
  [Releases page](https://github.com/platformsh/platformsh-cli/releases)
  (look for the latest `platform.phar` file).

2. Rename the file to `platform`, ensure it is executable, and move it into a
  directory in your PATH (use `echo $PATH` to see your options).

3. Enable autocompletion and shell aliases:

        platform self:install

## Updating

New releases of the CLI are made regularly. Update with this command:

    platform self:update

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
  docs                                      Open the online documentation
  help                                      Displays help for a command
  list                                      Lists commands
  multi                                     Execute a command on multiple projects
  web                                       Open the Web UI
activity
  activity:list (activities)                Get a list of activities for an environment or project
  activity:log                              Display the log for an activity
app
  app:config-get                            View the configuration of an app
  app:list (apps)                           Get a list of all apps in the local repository
auth
  auth:info                                 Display your account information
  auth:login (login)                        Log in to Platform.sh
  auth:logout (logout)                      Log out of Platform.sh
certificate
  certificate:add                           Add an SSL certificate to the project
  certificate:delete                        Delete a certificate from the project
  certificate:get                           View a certificate
  certificate:list (certificates)           List project certificates
db
  db:dump                                   Create a local dump of the remote database
  db:size                                   Estimate the disk usage of a database
  db:sql (sql)                              Run SQL on the remote database
domain
  domain:add                                Add a new domain to the project
  domain:delete                             Delete a domain from the project
  domain:get                                Show detailed information for a domain
  domain:list (domains)                     Get a list of all domains
  domain:update                             Update a domain
environment
  environment:activate                      Activate an environment
  environment:branch (branch)               Branch an environment
  environment:checkout (checkout)           Check out an environment
  environment:delete                        Delete an environment
  environment:drush (drush)                 Run a drush command on the remote environment
  environment:http-access (httpaccess)      Update HTTP access settings for an environment
  environment:info                          Read or set properties for an environment
  environment:list (environments)           Get a list of environments
  environment:logs (log)                    Read an environment's logs
  environment:merge (merge)                 Merge an environment
  environment:push (push)                   Push code to an environment
  environment:relationships (relationships)   Show an environment's relationships
  environment:ssh (ssh)                     SSH to the current environment
  environment:synchronize (sync)            Synchronize an environment's code and/or data from its parent
  environment:url (url)                     Get the public URLs of an environment
integration
  integration:add                           Add an integration to the project
  integration:delete                        Delete an integration from a project
  integration:get                           View details of an integration
  integration:list (integrations)           View a list of project integration(s)
  integration:update                        Update an integration
local
  local:build (build)                       Build the current project locally
  local:dir (dir)                           Find the local project root
  local:drush-aliases (drush-aliases)       Find the project's Drush aliases
project
  project:delete                            Delete a project
  project:get (get)                         Clone a project locally
  project:info                              Read or set properties for a project
  project:list (projects)                   Get a list of all active projects
  project:set-remote                        Set the remote project for the current Git repository
  project:variable:delete                   Delete a variable from a project
  project:variable:get (project-variables, pvget)   View variable(s) for a project
  project:variable:set (pvset)              Set a variable for a project
route
  route:get                                 View a route
  route:list (routes)                       List all routes for an environment
self
  self:install                              Install or update CLI configuration files
  self:update (self-update)                 Update the CLI to the latest version
server
  server:list (servers)                     List running local project web server(s)
  server:run                                Run a local PHP web server
  server:start                              Run PHP web server(s) for the local project
  server:stop                               Stop local project web server(s)
snapshot
  snapshot:create                           Make a snapshot of an environment
  snapshot:list (snapshots)                 List available snapshots of an environment
  snapshot:restore                          Restore an environment snapshot
ssh-key
  ssh-key:add                               Add a new SSH key
  ssh-key:delete                            Delete an SSH key
  ssh-key:list (ssh-keys)                   Get a list of SSH keys in your account
tunnel
  tunnel:close                              Close SSH tunnels
  tunnel:info                               View relationship info for SSH tunnels
  tunnel:list (tunnels)                     List SSH tunnels
  tunnel:open                               Open SSH tunnels to an app's relationships
user
  user:add                                  Add a user to the project
  user:delete                               Delete a user
  user:list (users)                         List project users
  user:role                                 View or change a user's role
variable
  variable:delete                           Delete a variable from an environment
  variable:get (variables, vget)            View variable(s) for an environment
  variable:set (vset)                       Set a variable for an environment
```

## Known issues

### Caching

The CLI caches details of your projects and their environments, and some other
information. These caches could become out-of-date. You can clear caches with
the command `platform clear-cache` (or `platform cc` for short).

## Customization

You can configure the CLI via the user configuration file `~/.platformsh/config.yaml`:

```yaml
api:
  # A path (relative or absolute) to a file containing an API token.
  # The file should be stored with minimal permissions.
  # Run 'platform logout --all' if you change this value.
  token_file: null

local:
  # Set this to true to avoid some Windows symlink issues.
  copy_on_windows: false

  # Configure the Drush executable to use (defaults to 'drush').
  drush_executable: null

updates:
  # Whether to check for automatic updates.
  check: true

  # The interval between checking for updates (seconds).
  check_interval: 86400
```

Other customization is available via environment variables:

* `PLATFORMSH_CLI_DEBUG`: set to 1 to enable cURL debugging
* `PLATFORMSH_CLI_DISABLE_CACHE`: set to 1 to disable caching
* `PLATFORMSH_CLI_SESSION_ID`: change user session (default 'default')
* `PLATFORMSH_CLI_TOKEN`: an API token. _Warning_: storing a secret in an environment variable can be insecure. It is usually preferable to use `config.yaml` as above.
* `PLATFORMSH_CLI_UPDATES_CHECK`: set to 0 to disable the automatic updates check
* `http_proxy` or `https_proxy`: specify a proxy for connecting to Platform.sh

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to contribute to the CLI.
