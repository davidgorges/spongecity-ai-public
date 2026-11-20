# Deploying with Laravel Forge

The app runs on a plain PHP server with Redis: no Node, no database server. Plans, conversations
and rationales live in Redis (the `spongecity.storage` pool, `STORAGE_REDIS_URL`); the Messenger
queues in SQLite under the kernel's share dir (`var/share/<env>`, set by `APP_SHARE_DIR`).

What the geodata services answered about a place (Nominatim, OpenStreetMap, Hamburg's buildings,
infiltration areas, LOD3 roofs) is cached in Redis too (the `spongecity.geodata` pool,
`App\SpongeCity\Geo\GeodataCache`), so the same address imports again without asking them. Each
entry expires after its service's `CACHE_SECONDS`. The large raw downloads (zip archives, 3D tiles, a few hundred MB) stay in `cache.app`
on disk under `var/share`: Redis keeps everything in memory.

## Site settings

| Setting | Value |
|---|---|
| Root directory | `/` |
| Web directory | `/public` |
| PHP version | 8.4 or later |
| Frontend package manager | None (AssetMapper and the Tailwind bundle need no Node) |
| Zero downtime deployments | On |
| Shared paths | `var/share` |

`var/share` holds the SQLite database (the Messenger queues, see `DATABASE_URL`), which must survive
a release, and the computed-results cache, which may not. Plans are in Redis. `var/` itself is committed (with only a
`.gitkeep`) so Forge has a parent directory to put the symlink in. Never commit `var/share`.
`App\Kernel::boot()` creates the share dir when it is missing, because SQLite cannot.

`framework.cache.prefix_seed` is fixed in `config/packages/cache.yaml`. Symfony's default seeds
the pool namespace with the project dir, which differs per release: plans would vanish on every
deploy, and workers still on the previous release would not find plans the web just created (or
hear `messenger:stop-workers`).

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

One per Messenger transport, both with the directory `/home/forge/<site>/current`:

```
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
php bin/console messenger:consume simstadt --time-limit=3600 --limit=1
```

## Server

- Redis must keep the plans: persistence on (RDB or AOF, Forge's default) and no eviction
  (`redis-cli config get maxmemory-policy` should say `noeviction`).
  The geodata cache shares that Redis; its entries expire, so it stays bounded by how many
  different places were imported in the last days.
- The agent starts its tools as `php bin/console mcp:server` (stdio): the `php` on the `forge`
  user's `PATH` must be the site's PHP version (`php -v`), and `proc_open` must be allowed.

- SimStadt runs in Docker (`SIMSTADT_IMAGE`, `linux/amd64`). Install Docker and run
  `sudo usermod -aG docker forge`. Pick an x86 server; amd64 emulation on ARM is slow. Without
  Docker everything but the SimStadt building questions still works.
- After the first deploy, run `php bin/console app:doctor` in `current`.
