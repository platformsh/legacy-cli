# Contributing

Development of the Platform.sh CLI happens in public in the
[GitHub repository](https://github.com/platformsh/platformsh-cli). Issues and
pull requests submitted via GitHub are very welcome.

## Developing locally

If you clone this repository locally, you can build it with:

```sh
composer install
```

Run the CLI from the local source with:

```sh
./bin/platform
```

Run tests with:

```sh
./vendor/bin/phpunit -c ./phpunit.xml
```

Tests are also run on Travis CI: https://travis-ci.org/platformsh/platformsh-cli
