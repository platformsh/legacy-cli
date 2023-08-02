# Contributing

Development of the Platform.sh Legacy CLI happens in public in the
[GitHub repository](https://github.com/platformsh/legacy-cli).

Issues and pull requests submitted via GitHub are very welcome.

In the near future - to be confirmed - this may move to being a subtree split
of the new CLI repository at: https://github.com/platformsh/cli

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

If your `uid` and `gid` is not `1000`, then alter `USER_ID` and `GROUP_ID` in `.env` accordingly.


Next, build and start your container

```sh
docker-compose up -d
```

Attach to the running container

```sh
docker-compose exec cli bash
```

Now you may run the steps mentioned in ["Developing locally"](#Developing-locally) inside the container.
