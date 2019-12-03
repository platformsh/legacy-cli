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
./scripts/test/unit.sh
```

Tests are also run on Travis CI: https://travis-ci.org/platformsh/platformsh-cli

## Developing in a docker container

If you don't have PHP installed locally or for other reasons want to do development on the
Platform.sh CLI inside a docker container, follow this procedure:

Create a `.env` file based on the default one

```sh
cp .env-dist .env
```

You should ensure that the UID and GUI used inside the container matches your local user to avoid file permission problems.
To get the UID and GID for local user, run:

```sh
id
```

If your `uid` and and `gid` is not `1000`, then alter `USER_ID` and `GROUP_ID` in `.env` accordingly.


Next, build and start your container

```sh
docker-compose up -d
```

Attach to the running container

```sh
docker-compose exec cli bash
```

Now you may run the steps mentioned in ["Developing locally"](#Developing-locally) inside the container.
