# Run agent-loop with Docker

The Docker setup provides separate CLI and browser UI services with their PHP
runtimes and locked Composer dependencies. PHP and Composer do not need to be
installed on the host. Docker Engine, the Docker Compose plugin, and a browser
for the UI are required.

The image contains the `agent-loop` package and its runtime dependencies. The
mounted project remains on the host and is the working directory for every CLI
command. The image does not contain or launch a coding-agent host such as Codex,
Claude Code or Cursor; run that host as usual and use this wrapper when a
workflow command needs PHP.
The CLI image build context is allow-listed to its Docker inputs,
`composer.json`, `composer.lock`, `bin/`, `src/`, `resources/` and
`docker/entrypoint.sh`. The UI image receives only its Dockerfile, locked
dependency manifest and entrypoint. Git history, local workflow state and
development dependencies are not sent to either image builder. This does not
limit the separate project bind mount.

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

By default, the image uses its standard CA certificates and does not require a
host CA path. If Composer must trust a custom proxy CA, set `CODEX_PROXY_CERT`
to a CA bundle file. The wrapper then adds `compose.proxy-ca.yaml` and supplies
the bundle as a temporary BuildKit and runtime secret; it is not stored in the
image. For direct Compose use, include the override explicitly:

```sh
CODEX_PROXY_CERT=/path/to/ca-bundle.pem \
docker compose -f compose.yaml -f compose.proxy-ca.yaml build agent-loop
```

The wrapper forwards all arguments to `agent-loop`, so any supported command is
available, for example `./docker/agent-loop workflow status MY-123 --format=json`.
It also runs project commands inside the same container with `--exec`, which is
useful for validation when PHP and Composer are not installed on the host:

```sh
./docker/agent-loop --exec composer ci
./docker/agent-loop --exec php -v
```

The default Composer cache is created at runtime under the container's current
UID, so the wrapper works with different host user IDs. When a proxy CA is
configured, runtime Composer commands use it from the read-only Compose secret;
the bundle is combined with the image's standard CA roots in `/tmp` and is not
stored in the image or target project. Dependency resolution uses the committed
`composer.lock`, resolved against PHP 8.3, the project's minimum supported
version. Update that lock when intentionally changing the packaged runtime
dependency graph.

## Start the browser UI

The optional `agent-ui` service provides the same local workflow through the
server-rendered control plane. It installs the released `voku/agent-ui` package
and its locked production dependencies in its own image; it does not need a
neighboring `agent-ui` checkout or PHP/Composer on the host.

From the project directory you want to work on, start the UI with:

```sh
/path/to/agent-loop/docker/agent-loop ui
```

Open <http://127.0.0.1:8088>. The target project is mounted read/write at
`/workspace/target`, just as it is for CLI commands. The service starts only
when requested and stays in the foreground; press Ctrl-C to stop it. To use a
different host port, set `AGENT_UI_PORT` (for example, `AGENT_UI_PORT=8090`).
Compose publishes the port on `127.0.0.1` so the local control plane is not
exposed on the network. Keep it on loopback when changing the port.

When a custom proxy CA is configured with `CODEX_PROXY_CERT`, the UI image build
and runtime receive the same temporary read-only CA secret as the CLI image.
The UI's locked dependency set is maintained in
`docker/agent-ui/composer.json` and `docker/agent-ui/composer.lock`.

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

For a custom proxy CA, add `-f compose.proxy-ca.yaml` to the Compose command and
set `CODEX_PROXY_CERT` to the CA bundle path.
