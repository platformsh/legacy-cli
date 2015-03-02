The **Platform.sh CLI** is the official command-line interface for [Platform.sh](https://platform.sh). Use this tool to interact with your [Platform.sh](https://platform.sh) projects, and to build them locally for development purposes.

[![Build Status](https://travis-ci.org/platformsh/platformsh-cli.svg)](https://travis-ci.org/platformsh/platformsh-cli)

### Requirements

* Operating system: Linux, OS X, Windows Vista, Windows 7 (any), or Windows 8 Pro (Win8 Standard does not work due to an issue with symlink permissions)
* PHP 5.3.3 or higher, with cURL support
* [Composer](https://getcomposer.org/)
* [Drush](https://github.com/drush-ops/drush) (only needed for Drupal projects)

### Installation

* On Windows, install Composer first using [Composer-Setup.exe](https://getcomposer.org/Composer-Setup.exe).

* Install or update the CLI using this command:

        curl -sfS https://raw.githubusercontent.com/platformsh/platformsh-cli/installer/installer.sh | bash

### Usage

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
  docs                                    Open the Platform.sh online documentation
  help                                    Displays help for a command
  list                                    Lists commands
  login                                   Log in to Platform.sh
  logout                                  Log out of Platform.sh
  web                                     Open the Platform.sh Web UI
activity
  activity:list (activities)              Get the most recent activities for an environment
  activity:log                            Display the log for an environment activity
domain
  domain:add                              Add a new domain to the project
  domain:delete                           Delete a domain from the project
  domain:list (domains)                   Get a list of all domains
environment
  environment:activate                    Activate an environment
  environment:backup                      Make a backup of an environment
  environment:branch (branch)             Branch an environment
  environment:checkout (checkout)         Check out an environment
  environment:deactivate                  Deactivate an environment
  environment:delete                      Delete an environment
  environment:drush (drush)               Run a drush command on the remote environment
  environment:http-access (httpaccess)    Update HTTP access settings for an environment
  environment:list (environments)         Get a list of all environments
  environment:merge (merge)               Merge an environment
  environment:metadata                    Read or set metadata for an environment
  environment:relationships               List an environment's relationships
  environment:ssh (ssh)                   SSH to the current environment
  environment:synchronize (sync)          Synchronize an environment
  environment:url (url)                   Get the public URL of an environment
integration
  integration:add                         Add an integration to the project
  integration:delete                      Delete an integration from a project
  integration:get (integrations)          View project integration(s)
  integration:update                      Update an integration
project
  project:build (build)                   Build the current project locally
  project:clean (clean)                   Remove old project builds
  project:drush-aliases (drush-aliases)   Find the project's Drush aliases
  project:get (get)                       Clone and build a project locally
  project:init (init)                     Initialize from a plain Git repository
  project:list (projects)                 Get a list of all active projects
ssh-key
  ssh-key:add                             Add a new SSH key
  ssh-key:delete                          Delete an SSH key
  ssh-key:list (ssh-keys)                 Get a list of SSH keys in your account
variable
  variable:delete                         Delete a variable from an environment
  variable:get (variables, vget)          Get a variable for an environment
  variable:set (vset)                     Set a variable for an environment
```

### Known issues

#### Caching
The CLI caches details of your projects and their environments. These caches
could become out-of-date. You can get a fresh list of projects or environments
with the `platform projects` and `platform environments` commands.

### Credits

* Maintained by [Commerce Guys](https://commerceguys.com).
