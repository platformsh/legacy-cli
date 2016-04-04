The **Magento Cloud CLI** is the official command-line interface for [Magento Cloud](https://magento.cloud). Use this tool to interact with your Magento Cloud projects, and to build them locally for development purposes.

## Requirements

* Operating system: Linux, OS X, Windows Vista, Windows 7, Windows 8 Pro, or Windows 10 (Windows 8 Standard does not work due to an issue with symlink permissions)
* PHP 5.5.9 or higher, with cURL support
* Git
* For building locally, your project's dependencies, e.g.
  * [Composer](https://getcomposer.org/) (for many PHP projects)

## Installation

### Installing on OS X or Linux

This is the recommended installation method. Simply use this command:

    curl -sS https://accounts.magento.cloud/cli/installer | php

### Installing manually

1. Download the latest stable package from the Releases page
  (look for the latest `magento-cloud.phar` file).

2. Rename the file to `magento-cloud`, ensure it is executable, and move it into
  a directory in your PATH (use `echo $PATH` to see your options).

3. Enable autocompletion and shell aliases:

        magento-cloud self:install

## Updating

New releases of the CLI are made regularly. Update with this command:

    magento-cloud self:update

## Usage

You can run the Magento Cloud CLI in your shell by typing:

    magento-cloud

Use the 'list' command to get a list of available options and commands:

    magento-cloud list

### Commands

The current output of `magento-cloud list` is as follows:

```
Magento Cloud CLI

Global options:
  --help           -h Display this help message
  --quiet          -q Do not output any message
  --verbose        -v|vv|vvv Increase the verbosity of messages
  --version        -V Display this application version
  --yes            -y Answer "yes" to all prompts
  --no             -n Answer "no" to all prompts

Available commands:
  clear-cache (clearcache, cc)              Clear the CLI cache
  docs                                      Open the Magento Cloud online documentation
  help                                      Displays help for a command
  list                                      Lists commands
  login                                     Log in to Magento Cloud
  logout                                    Log out of Magento Cloud
  web                                       Open the Magento Cloud Web UI
activity
  activity:list (activities)                Get the most recent activities for an environment
  activity:log                              Display the log for an environment activity
app
  app:config-get                            Get the configuration of an app
  app:list (apps)                           Get a list of all apps in the local repository
domain
  domain:add                                Add a new domain to the project
  domain:delete                             Delete a domain from the project
  domain:list (domains)                     Get a list of all domains
environment
  environment:activate                      Activate an environment
  environment:branch (branch)               Branch an environment
  environment:checkout (checkout)           Check out an environment
  environment:delete                        Delete an environment
  environment:http-access (httpaccess)      Update HTTP access settings for an environment
  environment:info                          Read or set properties for an environment
  environment:list (environments)           Get a list of all environments
  environment:logs (log)                    Read an environment's logs
  environment:merge (merge)                 Merge an environment
  environment:relationships (relationships) List an environment's relationships
  environment:routes (routes)               List an environment's routes
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
project
  project:delete                            Delete a project
  project:get (get)                         Clone and build a project locally
  project:info                              Read or set properties for a project
  project:list (projects)                   Get a list of all active projects
self
  self:install                              Install or update CLI configuration files
  self:update                               Update the CLI to the latest version
snapshot
  snapshot:create (backup)                  Make a snapshot of an environment
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
  variable:get (variables, vget)            Get a variable for an environment
  variable:set (vset)                       Set a variable for an environment
```

## Known issues

### Caching

The CLI caches details of your projects and their environments, and some other
information. These caches could become out-of-date. You can clear caches with
the command `magento-cloud clear-cache` (or `magento-cloud cc` for short).

## Customization

You can configure the CLI via these environment variables:

* `MAGENTO_CLOUD_CLI_TOKEN`: an API token to use for non-interactive login (not yet available)
* `MAGENTO_CLOUD_CLI_COPY_ON_WINDOWS`: set to 1 to avoid some Windows symlink issues
* `MAGENTO_CLOUD_CLI_DEBUG`: set to 1 to enable cURL debugging
* `MAGENTO_CLOUD_CLI_DISABLE_CACHE`: set to 1 to disable caching
* `MAGENTO_CLOUD_CLI_SESSION_ID`: change user session (default 'default')
* `http_proxy` or `https_proxy`: specify a proxy for connecting to Magento Cloud
