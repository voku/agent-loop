# Run agent-loop with Docker

The Docker setup provides the PHP runtime, Composer dependencies, Git and the
SQLite extensions needed by the `agent-loop` CLI. PHP and Composer do not need
to be installed on the host. Docker Engine and the Docker Compose plugin are
required.

The image contains the `agent-loop` package and its runtime dependencies. The
mounted project remains on the host and is the working directory for every CLI
command. The image does not contain or launch a coding-agent host such as Codex,
Claude Code or Cursor; run that host as usual and use this wrapper when a
workflow command needs PHP.

## Build and run against this checkout

From the `agent-loop` checkout:

```sh
./docker/agent-loop help
./docker/agent-loop init host-status --agent=codex --format=json
./docker/agent-loop enter MY-TASK --format=json
```

The first invocation builds the image. The wrapper runs as the current host
user so files created in the mounted project remain writable by that user.
Cached build progress is hidden for a quieter command line; Docker still prints
build errors when it cannot create the image.
Existing `.agent-loop/` files must also be writable by your host user. If an
earlier root or `sudo` run created them, restore ownership from the project
directory with `sudo chown -R "$(id -u):$(id -g)" .agent-loop`.

## Run against another project

Clone `agent-loop` somewhere available on the host, then point the wrapper at
the project to work on:

```sh
cd /path/to/my-project
AGENT_LOOP_TARGET="$PWD" /path/to/agent-loop/docker/agent-loop init scaffold --prefix=MY --agent=codex
AGENT_LOOP_TARGET="$PWD" /path/to/agent-loop/docker/agent-loop enter MY-123 --format=json
```

The target directory is bind-mounted read/write at `/workspace/target`. Only
that project directory is mounted; the Docker socket and host credentials are
not passed into the container. Configure Git identity in the target repository
if your workflow needs it. For a different numeric host identity, set
`AGENT_LOOP_UID` and `AGENT_LOOP_GID`; `AGENT_LOOP_TARGET` may also be set once
in the shell environment.

Compose supplies the host CA bundle to the dependency-install build step as a
temporary BuildKit secret, so TLS verification stays enabled and the bundle is
not stored in the image. The default bundle path is for Linux. On macOS or when
using a custom proxy CA, set `CODEX_PROXY_CERT` to the path of the host CA bundle
before building.

The wrapper forwards all arguments to `agent-loop`, so any supported command is
available, for example `./docker/agent-loop workflow status MY-123 --format=json`.

## Build directly with Compose

From the `agent-loop` checkout, build the image with:

```sh
docker compose build agent-loop
```

To operate on another directory with Compose directly, set `AGENT_LOOP_TARGET`
to its absolute path and provide the host UID and GID:

```sh
AGENT_LOOP_TARGET=/path/to/my-project \
AGENT_LOOP_UID="$(id -u)" \
AGENT_LOOP_GID="$(id -g)" \
docker compose run --rm --build agent-loop init host-status --agent=codex --format=json
```
