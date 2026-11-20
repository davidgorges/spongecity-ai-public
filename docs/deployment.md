# Deploying with Laravel Forge

The app runs on a plain PHP server with Redis: no Node, no database server. Plans, conversations
and rationales live in Redis (the `spongecity.storage` pool, `STORAGE_REDIS_URL`); the Messenger
queues in SQLite under the kernel's share dir (`var/share/<env>`, set by `APP_SHARE_DIR`).

## Site settings

| Setting | Value |
|---|---|
| Root directory | `/` |
| Web directory | `/public` |
| PHP version | 8.4 or later |
| Frontend package manager | None (AssetMapper and the Tailwind bundle need no Node) |
| Zero downtime deployments | On |
| Shared paths | `var/share`, `var/log` |

`var/share` holds the SQLite database (the Messenger queues, see `DATABASE_URL`), which must survive
a release, and the computed-results cache, which may not. Plans are in Redis. `var/` itself is committed (with only a
`.gitkeep`) so Forge has a parent directory to put the symlink in. Never commit `var/share`.
`App\Kernel::boot()` creates the share dir when it is missing, because SQLite cannot.

`framework.cache.prefix_seed` is fixed in `config/packages/cache.yaml`. Symfony's default seeds
the pool namespace with the project dir, which differs per release: plans would vanish on every
deploy, and workers still on the previous release would not find plans the web just created (or
hear `messenger:stop-workers`).

## Logs

In prod, errors (with the 50 log lines before them) go to `var/log/prod-YYYY-MM-DD.log`, kept for
14 days; deprecations to `var/log/prod.deprecations-YYYY-MM-DD.log`. Not stderr: PHP-FPM drops the
stderr of web requests. `var/log` is shared, so the files survive deploys. The workers also print
to their background process log in Forge.

## Environment

Forge's environment editor writes `.env`, which replaces the committed defaults. Either paste the
whole committed `.env` and change the values below, or share `.env.local` as well and put them
there.

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=        # php -r 'echo bin2hex(random_bytes(16));'
DEFAULT_URI=https://your-domain.tld/
ANTHROPIC_API_KEY=
OSM_USER_AGENT="SpongeCityAI/0.1 (contact: you@your-domain.tld)"
# Forge's Redis: the default is redis://${REDIS_HOST}:${REDIS_PORT}; with a password
# STORAGE_REDIS_URL=redis://PASSWORD@127.0.0.1:6379
# Mercure (see "Mercure hub" below): the app publishes to the hub on localhost, the browser
# subscribes through nginx; the secret and the issuer must match the hub's
MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=https://your-domain.tld/.well-known/mercure
MERCURE_JWT_SECRET=        # openssl rand -hex 32, the same as the hub's keys
MERCURE_JWT_ISSUER=https://your-domain.tld
```

Every release lives in its own directory (`releases/<n>`). The cache namespaces would follow it, so
`config/packages/cache.yaml` pins `prefix_seed` in production: without it, what one release stored
is out of reach for the next.

## Deploy script

After Forge's own clone and activate lines:

```bash
export APP_ENV=prod
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative
$FORGE_PHP bin/console tailwind:build --minify
$FORGE_PHP bin/console asset-map:compile
$FORGE_PHP bin/console cache:clear
$FORGE_PHP bin/console messenger:setup-transports
$FORGE_PHP bin/console messenger:stop-workers
```

`export APP_ENV=prod` comes first: Composer's `auto-scripts` boot the kernel, and without it they
boot in `dev`, which needs dev-only bundles that `--no-dev` did not install
(`Attempted to load class "DebugBundle"`). The environment file alone is not enough, because it may
not be linked into the release yet when Composer runs.

`messenger:stop-workers` makes Supervisor restart the workers on the new release.

## Daemons

One per Messenger transport, all with the directory `/home/forge/<site>/current`:

```
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
php bin/console messenger:consume simstadt --time-limit=3600 --limit=1
php bin/console messenger:consume agent --time-limit=3600 --memory-limit=256M
```

`agent` runs the planning agent's turns (`AskPlanningAgent`): a turn takes a minute or more, longer
than PHP-FPM lets a web request run, so the chat only queues it and the reply comes back through
Mercure (`ChatStream`). Its own transport, so a turn does not hold up site imports.

And the Mercure hub (next section): `/home/forge/mercure/start.sh`, directory `/home/forge/mercure`.

## Mercure hub

The chat's replies reach the browser through a [Mercure](https://mercure.rocks) hub on the same
machine, behind nginx. `symfony/mercure` speaks the **Mercure protocol 1.0**: use hub v1.x (a 0.x
hub answers 401 to its tokens).

```bash
mkdir -p ~/mercure/data && cd ~/mercure
curl -fsSL https://github.com/dunglas/mercure/releases/download/v1.1.6/mercure_Linux_x86_64.tar.gz | tar xz
openssl rand -hex 32    # the secret: MERCURE_JWT_SECRET in the app, both keys below

cat > hub.env <<'ENV'
SERVER_NAME=:3000
MERCURE_PUBLISHER_JWT_KEY=<secret>
MERCURE_SUBSCRIBER_JWT_KEY=<secret>
MERCURE_TRUSTED_ISSUERS=https://your-domain.tld
MERCURE_EXTRA_DIRECTIVES=anonymous
GLOBAL_OPTIONS=admin off
XDG_DATA_HOME=/home/forge/mercure/data
XDG_CONFIG_HOME=/home/forge/mercure/data
ENV
chmod 600 hub.env

cat > start.sh <<'SH'
#!/usr/bin/env bash
set -a; . /home/forge/mercure/hub.env; set +a
exec /home/forge/mercure/mercure run --config /home/forge/mercure/Caddyfile
SH
chmod +x start.sh
```

- `SERVER_NAME=:3000`: plain HTTP on port 3000; the firewall keeps it off the internet, nginx is
  the way in. `MERCURE_TRUSTED_ISSUERS` must equal the app's `MERCURE_JWT_ISSUER`.
- `anonymous`: the chat's updates are public, like the plan itself (anyone with its id opens it).

In the site's nginx configuration (Forge: site, Edit Nginx Configuration), before `location /`:

```nginx
location /.well-known/mercure {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 24h;
}
```

Check: `curl -N https://your-domain.tld/.well-known/mercure?match=test` stays open (the stream),
instead of answering 404 or 502.

## Server

- Redis must keep the plans: persistence on (RDB or AOF, Forge's default) and no eviction
  (`redis-cli config get maxmemory-policy` should say `noeviction`).
- The agent starts its tools as `php bin/console mcp:server` (stdio): the `php` on the `forge`
  user's `PATH` must be the site's PHP version (`php -v`), and `proc_open` must be allowed.

- SimStadt runs in Docker (`SIMSTADT_IMAGE`, `linux/amd64`). Install Docker and run
  `sudo usermod -aG docker forge`. Pick an x86 server; amd64 emulation on ARM is slow. Without
  Docker everything but the SimStadt building questions still works.
- After the first deploy, run `php bin/console app:doctor` in `current`.
