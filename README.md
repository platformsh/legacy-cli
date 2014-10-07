DESCRIPTION
-----------

**Platform.sh CLI** is the official command line shell and Unix scripting interface for [Platform.sh](https://platform.sh). It ships with all the useful commands to interact with your [Platform.sh](https://platform.sh) projects.

REQUIREMENTS
------------

* OS: Linux, OS X, Windows Vista, 7 (any), or 8 Pro. (Win8 Standard does not work due to an issue with symlink permissions.)
* PHP 5.3.3 or higher with cURL
* Composer ([install Composer globally](https://getcomposer.org/doc/00-intro.md#globally))
* Drush 6.x - https://github.com/drush-ops/drush (only needed for Drupal projects) *Don't use master!*

INSTALL/UPDATE - COMPOSER
-------------------------

* Make sure Composer's global bin directory is on the system PATH (recommended):

        for FILE in $HOME/.bashrc $HOME/.bash_profile $HOME/.bash_login $HOME/.profile; \
        do if [ -f $FILE ]; then \
        printf '\nexport PATH="$HOME/.composer/vendor/bin:$PATH"' >> $FILE && . $FILE; \
        break; fi; done

* Remove the old Platform.sh CLI package, if you have it:

        composer global remove 'commerceguys/platform-cli'

* To install the latest stable version:

        composer global require "platformsh/cli=1.*"

* To update to a newer version:

        composer global update

USAGE
-----

Platform CLI can be run in your shell by typing `platform`.

        platform

Use the 'list' command to get a list of available options and commands:

        platform list

FAQ
------

#### What does "CLI" stand for?
Command Line Interface.

#### I get a message about removing symfony/yaml v2.2.1 when doing a global composer install?
You need to make sure that you're using the 6.x branch of Drush and not dev-master. Do this:

```
composer global require drush/drush:6.*
composer global update
```

You should now be able to install as described. **Note: Drush 6 is not compatible with Drupal 8.**

COMMAND LIST
------------

The current output of `platform list` is as follows:

```
Platform CLI version 1.1.0

Usage:
  [options] command [arguments]

Options:
  --help           -h Display this help message.
  --quiet          -q Do not output any message.
  --verbose        -v|vv|vvv Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
  --version        -V Display this application version.
  --no-interaction -n Do not ask any interactive question.
  --shell          -s Launch the shell.

Available commands:
  branch                      Branch an environment.
  build                       Builds the current project.
  checkout                    Checkout an environment.
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
  environment:backup          Backup an environment.
  environment:branch          Branch an environment.
  environment:checkout        Checkout an environment.
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

CREDITS
-----------

* Developed and maintained by [Bojan Zivanovic](https://github.com/bojanz).
* Sponsored by [Commerce Guys](https://commerceguys.com).
