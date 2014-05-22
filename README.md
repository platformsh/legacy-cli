DESCRIPTION
-----------

**Platform CLI** is the official command line shell and Unix scripting interface for [Platform](https://platform.sh). It ships with all the useful commands to interact with your [Platform](https://platform.sh) projects.

[![Total Downloads](https://poser.pugx.org/commerceguys/platform-cli/downloads.png)](https://packagist.org/packages/commerceguys/platform-cli)

REQUIREMENTS
------------

* PHP 5.3.3 or higher with cURL
* Composer ([Install Composer globally](http://getcomposer.org/doc/00-intro.md#system-requirements))
* Drush 6.0 or higher - https://github.com/drush-ops/drush (only for Drupal projects)

INSTALL/UPDATE - COMPOSER
-------------------------

* Make sure Composer's global bin directory is on the system PATH (recommended):

        $ sed -i '1i export PATH="$HOME/.composer/vendor/bin:$PATH"' $HOME/.bashrc
        $ source $HOME/.bashrc

* To install the stable version:

        composer global require "commerceguys/platform-cli=1.0.*"

* To update to a newer version:

        composer global update

* Add the `platform` command to your PATH (use your own path):

        export PATH=$PATH:$HOME/.composer/vendor/bin

USAGE
-----

Platform CLI can be run in your shell by typing `platform`.

    $ platform

Use the 'help' command to get a list of available options and commands:

    $ platform help

FAQ
------

```
  Q: What does "CLI" stand for?
  A: Command Line Interface.
```

CREDITS
-----------

* Developed and maintained by [Bojan Zivanovic](https://github.com/bojanz).
* Sponsored by [Commerce Guys](https://commerceguys.com).
