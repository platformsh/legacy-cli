The **Legacy** Platform.sh CLI is the legacy version of the command-line interface for [Platform.sh](https://platform.sh). For the **current Platform.sh CLI**, check [this repository](https://github.com/platformsh/cli).

[![Build Status](https://api.travis-ci.com/platformsh/platformsh-cli.svg)](https://travis-ci.com/github/platformsh/platformsh-cli) [![License](https://poser.pugx.org/platformsh/cli/license)](https://github.com/platformsh/platformsh-cli/blob/main/LICENSE)

## Install

To install the CLI, use either [Homebrew](https://brew.sh/) (on Linux, macOS, or the Windows Subsystem for Linux) or [Scoop](https://scoop.sh/) (on Windows):

### HomeBrew

```console
brew install platformsh/tap/platformsh-cli
```

### Scoop

```console
scoop bucket add platformsh https://github.com/platformsh/homebrew-tap.git
scoop install platform
```

### Manual installation

For manual installation, you can also [download the latest binaries](https://github.com/platformsh/cli/releases/latest).

### Legacy installer

_This installation method is considered legacy and is discouraged, use one of the methods above instead. Starting with version 5.x, this installation method will not be supported._

In order to use the Legacy installer, you need to have an operating system supported by PHP (Linux, OS X, or Windows) and PHP 5.5.9 or higher, with the following extensions: `curl`, `json`, `pcre`, and `phar`.

Run this command to install the CLI using the legacy installer, given that you have PHP already installed:

```console
curl -sS https://platform.sh/cli/installer | php
```

In some Windows terminals you may need `php.exe` instead of `php`.

## Upgrade

Upgrade using the same tool:

### HomeBrew

```console
brew upgrade platformsh-cli
```

### Scoop

```console
scoop update platform
```

## Usage

You can run the Platform.sh CLI in your shell by typing `platform`.

    platform

Use the 'list' command to get a list of available options and commands:

    platform list

## Authentication

There are two ways to authenticate:

1. The recommended way is `platform login`, which lets you log in via a web browser, including via third-party providers such as Google, GitHub, GitLab and Bitbucket.

2. If using a browser is not possible, use an [API token](https://docs.platform.sh/gettingstarted/cli/api-tokens.html).

    An interactive command is available for this: `platform auth:api-token-login`

    For non-interactive uses such as scripts or CI systems, set the API token in an environment variable named `PLATFORMSH_CLI_TOKEN`. This can be insecure if not handled properly, although it is appropriate for systems such as CircleCI, Jenkins and GitLab.

    *_Warning_*: An API token can act as the account that created it, with no restrictions. Use a separate machine account to limit the token's access.

## Customization

You can configure the CLI via the user configuration file `~/.platformsh/config.yaml`.
These are the possible keys, and their default values:

```yaml
api:
  # Whether to disable the docker-credential-helpers credential storage method.
  # When enabled (default), and if supported, credentials are stored in:
  #   - OS X: the default keychain
  #   - Linux: the default collection in the Secret Service
  #   - Windows: the Credential Manager under "Generic Credentials"
  # When disabled or not supported, credentials are stored in a hidden file.
  disable_credential_helpers: false

application:
  # The default timezone for times displayed or interpreted by the CLI.
  # An empty (falsy) value here means the PHP or system timezone will be used.
  # For a list of timezones, see: http://php.net/manual/en/timezones.php
  timezone: ~

  # The default date format string, for dates and times displayed by the CLI.
  # For a list of formats, see: http://php.net/manual/en/function.date.php
  date_format: c

  # A directory (relative to the home directory) where the CLI can write
  # user-specific files, for storing state, logs, credentials, etc.
  writable_user_dir: '.platformsh'

local:
  # Set this to true to avoid some Windows symlink issues.
  copy_on_windows: false

  # Configure the Drush executable to use (defaults to 'drush').
  drush_executable: null

# Pagination settings.
#
# These only affect 2 commands for now: project:list and org:sub:list.
pagination:
    # Enable pagination. Can be disabled with --count 0.
    enabled: true
    # Items per page. Can be overridden with --count.
    count: 20

updates:
  # Whether to check for automatic updates.
  check: true

  # The interval between checking for updates (in seconds). 604800 = 7 days.
  check_interval: 604800
```

Other customization is available via environment variables:

* `PLATFORMSH_CLI_DEBUG`: set to 1 to enable cURL debugging. _Warning_: this will print all request information in the terminal, including sensitive access tokens.
* `PLATFORMSH_CLI_DEFAULT_TIMEOUT`: the timeout (in seconds) for most individual API requests. The default is 30.
* `PLATFORMSH_CLI_DISABLE_CACHE`: set to 1 to disable caching
* `PLATFORMSH_CLI_HOME`: override the home directory (inside which the .platformsh directory is stored)
* `PLATFORMSH_CLI_NO_COLOR`: set to 1 to disable colors in output
* `PLATFORMSH_CLI_NO_INTERACTION`: set to 1 to disable interaction (useful for scripting). Equivalent to the `--no-interaction` command-line option. _Warning_: this will bypass any confirmation questions.
* `PLATFORMSH_CLI_SESSION_ID`: change user session (default 'default'). The `session:switch` command (beta) is now available as an alternative.
* `PLATFORMSH_CLI_SHELL_CONFIG_FILE`: specify the shell configuration file that the installer should write to (as an absolute path). If not set, a file such as `~/.bashrc` will be chosen automatically. Set this to an empty string to disable writing to a shell config file.
* `PLATFORMSH_CLI_TOKEN`: an API token. *_Warning_*: An API token can act as the account that created it, with no restrictions. Use a separate machine account to limit the token's access. Additionally, storing a secret in an environment variable can be insecure. It may be better to use the `auth:api-token-login` command. The environment variable is preferable on CI systems like Jenkins and GitLab.
* `PLATFORMSH_CLI_UPDATES_CHECK`: set to 0 to disable the automatic updates check
* `PLATFORMSH_CLI_AUTO_LOAD_SSH_CERT`: set to 0 to disable automatic loading of an SSH certificate when running login or SSH commands
* `CLICOLOR_FORCE`: set to 1 or 0 to force colorized output on or off, respectively
* `http_proxy` or `https_proxy`: specify a proxy for connecting to Platform.sh

## Known issues

### Caching

The CLI caches details of your projects and their environments, and some other
information. These caches could become out-of-date. You can clear caches with
the command `platform clear-cache` (or `platform cc` for short).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to contribute to the CLI.
