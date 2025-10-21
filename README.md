The **Legacy** Platform.sh CLI is the legacy version of the command-line interface for [Platform.sh](https://platform.sh). For the **current Platform.sh CLI**, check [this repository](https://github.com/platformsh/cli).

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

In order to use the Legacy installer, you need to have an operating system supported by PHP (Linux, OS X, or Windows) and PHP 8.2 or higher, with the following extensions: `curl`, `json`, `pcre`, and `phar`.

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

2. If using a browser is not possible, use an [API token](https://docs.upsun.com/anchors/fixed/cli/api-token/).

    An interactive command is available for this: `platform auth:api-token-login`

    For non-interactive uses such as scripts or CI systems, set the API token in an environment variable named `PLATFORMSH_CLI_TOKEN`. This can be insecure if not handled properly, although it is appropriate for systems such as CircleCI, Jenkins and GitLab.

    *_Warning_*: An API token can act as the account that created it, with no restrictions. Use a separate machine account to limit the token's access.

## Customization

You can configure the CLI via the user configuration file `~/.platformsh/config.yaml`.

The possible keys that can be overridden are in the [config-defaults.yaml](/config-defaults.yaml) and [config.yaml](/config.yaml) files.

Other customization is available via environment variables, including:

* `PLATFORMSH_CLI_DEBUG`: set to 1 to enable debugging. _Warning_: this could print HTTP request information in the terminal, including sensitive access tokens.
* `PLATFORMSH_CLI_DEFAULT_TIMEOUT`: the timeout (in seconds) for most individual API requests. The default is 30.
* `PLATFORMSH_CLI_DISABLE_CACHE`: set to 1 to disable caching
* `PLATFORMSH_CLI_HOME`: override the home directory (inside which the .platformsh directory is stored)
* `PLATFORMSH_CLI_NO_COLOR`: set to 1 to disable colors in output
* `PLATFORMSH_CLI_NO_INTERACTION`: set to 1 to disable interaction (useful for scripting). Equivalent to the `--no-interaction` command-line option. _Warning_: this will bypass any confirmation questions.
* `PLATFORMSH_CLI_SESSION_ID`: change user session (default 'default'). The `session:switch` command (beta) is now available as an alternative.
* `PLATFORMSH_CLI_SHELL_CONFIG_FILE`: specify the shell configuration file that the installer should write to (as an absolute path). If not set, a file such as `~/.bashrc` will be chosen automatically. Set this to an empty string to disable writing to a shell config file.
* `PLATFORMSH_CLI_TOKEN`: an API token. *_Warning_*: An API token can act as the account that created it, with no restrictions. Use a separate machine account to limit the token's access. Additionally, storing a secret in an environment variable can be insecure. It may be better to use the `auth:api-token-login` command. The environment variable is preferable on CI systems like Jenkins and GitLab.
* `PLATFORMSH_CLI_UPDATES_CHECK`: set to 0 to disable the automatic updates check
* `PLATFORMSH_CLI_SSH_AUTO_LOAD_CERT`: set to 0 to disable automatic loading of an SSH certificate when running login or SSH commands
* `PLATFORMSH_CLI_REPORT_DEPRECATIONS`: set to 1 to enable PHP deprecation notices (suppressed by default). They will only be displayed in debug mode (`-vvv`).
* `CLICOLOR_FORCE`: set to 1 or 0 to force colorized output on or off, respectively
* `http_proxy` or `https_proxy`: specify a proxy for connecting to Platform.sh

## Known issues

### Caching

The CLI caches details of your projects and their environments, and some other
information. These caches could become out-of-date. You can clear caches with
the command `platform clear-cache` (or `platform cc` for short).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to contribute to the CLI.
