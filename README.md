DESCRIPTION
-----------

**Platform CLI** is the official command line shell and Unix scripting interface for [Platform](https://platform.sh). It ships with all the useful commands to interact with your [Platform](https://platform.sh) projects.

[![Total Downloads](https://poser.pugx.org/platformsh/cli/downloads.png)](https://packagist.org/packages/platformsh/cli)

REQUIREMENTS
------------

* PHP 5.3.3 or higher with cURL
* Composer ([Install Composer globally](http://getcomposer.org/doc/00-intro.md#system-requirements))
* Drush 6.x - https://github.com/drush-ops/drush (only for Drupal projects) *Don't use master!*

INSTALL/UPDATE - COMPOSER
-------------------------

* Make sure Composer's global bin directory is on the system PATH (recommended):

        $ sed -i '1i export PATH="$HOME/.composer/vendor/bin:$PATH"' $HOME/.bashrc
        $ source $HOME/.bashrc

* Remove the old CLI version, if you have it:

        composer global remove 'commerceguys/platform-cli'

* To install the stable version:

        composer global require "platformsh/cli=1.0.*"

* To update to a newer version:

        composer global update

* Add the `platform` command to your PATH (use your own path):

        export PATH=$PATH:$HOME/.composer/vendor/bin

USAGE
-----

Platform CLI can be run in your shell by typing `platform`.

    $ platform

Use the 'list' command to get a list of available options and commands:

    $ platform list

FAQ
------

#### What does "CLI" stand for?
Command Line Interface.

#### I get a message about removing symfony/yaml v2.2.1 when doing a global composer install?
You need to make sure that you're using the 6.x branch and not dev-master. Do this:

```
composer global require drush/drush:6.*
composer global update
```

This will remove the dev-version of drush as well as the dependencies. You should now be able to install as described. **Note: Drush 6 is not compatible with Drupal 8.**


CREDITS
-----------

* Developed and maintained by [Bojan Zivanovic](https://github.com/bojanz).
* Sponsored by [Commerce Guys](https://commerceguys.com).
