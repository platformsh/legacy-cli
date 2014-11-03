The **Platform.sh CLI** is the official command-line interface for [Platform.sh](https://platform.sh). Use this tool to interact with your [Platform.sh](https://platform.sh) projects, and to build them locally for development purposes.

### Requirements

* Operating system: Linux, OS X, Windows Vista, Windows 7 (any), or Windows 8 Pro (Win8 Standard does not work due to an issue with symlink permissions)
* PHP 5.3.3 or higher, with cURL support
* [Composer](https://getcomposer.org/)
* [Drush](https://github.com/drush-ops/drush) (only needed for Drupal projects)

### Installation

* [Install Composer globally](https://getcomposer.org/doc/00-intro.md#globally).

* Make sure Composer's global executable directory is in your system's PATH:

        for FILE in $HOME/.bashrc $HOME/.bash_profile $HOME/.bash_login $HOME/.profile; \
        do if [ -f $FILE ]; then \
        printf '\nexport PATH="$HOME/.composer/vendor/bin:$PATH"' >> $FILE && . $FILE; \
        break; fi; done

* Install the latest stable version of the CLI:

        composer global require 'platformsh/cli:1.*'

### Usage

You can run the Platform.sh CLI in your shell by typing `platform`.

        platform

Use the 'list' command to get a list of available options and commands:

        platform list

### Commands

The current output of `platform list` is as follows:

```
Platform.sh CLI version 1.2.3

Usage:
  [options] command [arguments]

Options:
  --help           -h Display this help message.
  --quiet          -q Do not output any message.
  --verbose        -v|vv|vvv Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
  --version        -V Display this application version.
  --yes            -y Answer "yes" to all prompts.
  --no             -n Answer "no" to all prompts.
  --shell          -s Launch the shell.

Available commands:
  branch                      Branch an environment.
  build                       Builds the current project.
  checkout                    Check out an environment.
  clean                       Remove project builds.
  domains                     Get a list of all domains.
  drush                       Invoke a drush command using the site alias for the current environment.
  drush-aliases               Determine and/or recreate the project's Drush aliases (if any).
  environments                Get a list of all environments.
  get                         Does a git clone of the referenced project.
  help                        Displays help for a command
  list                        Lists commands
  login                       Log in to Platform.sh
  logout                      Log out of Platform.sh
  projects                    Get a list of all active projects.
  ssh                         SSH to the current environment.
  ssh-keys                    Get a list of all added SSH keys.
  url                         Get the public URL to an environment, and open it in a browser.
domain
  domain:add                  Add a new domain to the project.
  domain:delete               Delete a domain from the project.
environment
  environment:activate        Activate an environment.
  environment:backup          Make a backup of an environment.
  environment:branch          Branch an environment.
  environment:checkout        Check out an environment.
  environment:deactivate      Deactivate an environment.
  environment:delete          Delete an environment.
  environment:merge           Merge an environment.
  environment:relationships   List the environment's relationships.
  environment:ssh             SSH to the current environment.
  environment:synchronize     Synchronize an environment.
  environment:url             Get the public URL to an environment, and open it in a browser.
project
  project:build               Builds the current project.
  project:clean               Remove project builds.
  project:drush-aliases       Determine and/or recreate the project's Drush aliases (if any).
  project:get                 Does a git clone of the referenced project.
ssh-key
  ssh-key:add                 Add a new SSH key.
  ssh-key:delete              Delete an SSH key.
```

### Credits

* Maintained by [Commerce Guys](https://commerceguys.com).
