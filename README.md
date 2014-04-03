# Commerce Platform CLI

This is the official CLI for Commerce Platform.

## Requirements

* Drush 6.0 or higher - https://github.com/drush-ops/drush
* PHP 5.3.3 or higher with cURL
* Composer - https://getcomposer.org/doc/00-intro.md

## Installation (TMP)
Clone the Git repository locally
```
git clone git@github.com:commerceguys/platform-cli.git
```

Launch composer
```
cd platform-cli
composer install
```

Add the `platform` command to your path by adding this line to you bashrc file (use your own path)
```
export PATH=$PATH:/projects/platform/platform-cli
```
or create a symlink:
```
ln -s /projects/platform/platform-cli/platform /usr/local/bin/platform
```

Now you can run `platform` and see all the available commands !

## Installation
```
composer global require 'commerceguys/platform-cli:*'
```
Make sure you have ~/.composer/vendor/bin/ in your path.


## Getting started
You can then go into a directory
```
cd myprojects
platform
```
